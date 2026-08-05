<?php
/**
 * UserService.php —— 用户领域服务
 *
 * 所有与用户账号相关的数据库读写收敛于此，
 * 控制器只负责取参数、调方法、拼响应，不掺杂 SQL 细节。
 *
 * 密码使用 PHP 原生 password_hash()（bcrypt）存储，
 * 绝不保存明文，校验走 password_verify() 的恒定时间比较。
 */

class UserService
{
    /**
     * 取得 users 表实例
     *
     * Table 的链式调用每一步都返回新实例，原对象不被修改，
     * 因此可安全复用同一基础对象构建不同查询。
     *
     * @return Table
     */
    public static function table()
    {
        return new Table(USERS_TABLE);
    }

    /**
     * 按 id 查找用户
     *
     * @param integer $id
     * @return array|null 未找到返回 null
     */
    public static function findById($id)
    {
        $id = (int) $id;

        if ($id <= 0) {
            return null;
        }

        # find() 未找到时返回 false，统一转换为 null 便于调用方判断
        $row = self::table()->where(["id" => $id])->find();

        return $row === false ? null : $row;
    }

    /**
     * 按完整邮箱地址查找用户
     *
     * @param string $email
     * @return array|null
     */
    public static function findByEmail($email)
    {
        $email = MailAddress::normalize($email);

        if ($email === "") {
            return null;
        }

        $row = self::table()->where(["email" => $email])->find();

        return $row === false ? null : $row;
    }

    /**
     * 注册新用户
     *
     * @param string $email 完整邮箱地址
     * @param string $password 明文密码
     * @param string $nickname 显示名
     * @return array{ok:bool, error?:string, user?:array}
     */
    public static function register($email, $password, $nickname = "")
    {
        $email = MailAddress::normalize($email);

        # ---- 校验邮箱格式 ----
        if (!MailAddress::isValid($email)) {
            return ["ok" => false, "error" => "invalid email address"];
        }

        # ---- 校验域名归属：只允许注册本服务负责的域 ----
        if (!MailAddress::isLocal($email)) {
            return [
                "ok" => false,
                "error" => "email domain is not served by this server, allowed: "
                    . implode(", ", MailAddress::localDomains())
            ];
        }

        # ---- 校验密码强度 ----
        if (!is_string($password) || strlen($password) < 8) {
            return ["ok" => false, "error" => "password must be at least 8 characters"];
        }

        if (strlen($password) > 128) {
            return ["ok" => false, "error" => "password is too long"];
        }

        # ---- 应用层唯一性检查（数据库层还有 uk_email 唯一索引兜底）----
        if (self::findByEmail($email) !== null) {
            return ["ok" => false, "error" => "email address already registered"];
        }

        $username = MailAddress::userOf($email);
        $domain = MailAddress::domainOf($email);

        $data = [
            "username"      => $username,
            "domain"        => $domain,
            "email"         => $email,
            # PASSWORD_DEFAULT 会随 PHP 版本演进采用更强算法
            "password_hash" => password_hash($password, PASSWORD_DEFAULT),
            "nickname"      => $nickname === "" ? $username : $nickname,
            "role"          => "user",
            "status"        => 1,
            "quota"         => (int) DotNetRegistry::Read("USER_QUOTA", 5368709120),
            "used_size"     => 0,
            "create_time"   => now_time()
        ];

        $table = self::table();
        $newId = $table->add($data);

        # add() 有自增主键时返回新行 id，失败返回 false。
        # 必须用 === false 判断，不可用真值判断
        if ($newId === false) {
            mail_log("api", "user register failed: " . $table->getLastMySqlError());
            return ["ok" => false, "error" => "failed to create account"];
        }

        # 为新用户初始化系统文件夹（收件箱/已发送/草稿/垃圾邮件/回收站）
        FolderService::initSystemFolders((int) $newId);

        $user = self::findById($newId);

        return ["ok" => true, "user" => $user];
    }

    /**
     * 校验登录凭据
     *
     * @param string $email
     * @param string $password 明文密码
     * @return array{ok:bool, error?:string, user?:array}
     */
    public static function verifyLogin($email, $password)
    {
        $user = self::findByEmail($email);

        # 用户不存在时也执行一次哈希校验，使响应耗时与「密码错误」一致，
        # 避免通过时间差异枚举出哪些邮箱已注册
        if ($user === null) {
            password_verify((string) $password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            return ["ok" => false, "error" => "invalid email or password"];
        }

        if (!password_verify((string) $password, $user["password_hash"])) {
            return ["ok" => false, "error" => "invalid email or password"];
        }

        if ((int) $user["status"] !== 1) {
            return ["ok" => false, "error" => "account is disabled"];
        }

        return ["ok" => true, "user" => $user];
    }

    /**
     * 记录登录信息
     *
     * @param integer $userId
     * @return void
     */
    public static function touchLogin($userId)
    {
        self::table()
            ->where(["id" => (int) $userId])
            ->save([
                "last_login"    => now_time(),
                "last_login_ip" => Utils::UserIPAddress()
            ]);
    }

    /**
     * 修改密码
     *
     * @param integer $userId
     * @param string $oldPassword
     * @param string $newPassword
     * @return array{ok:bool, error?:string}
     */
    public static function changePassword($userId, $oldPassword, $newPassword)
    {
        $user = self::findById($userId);

        if ($user === null) {
            return ["ok" => false, "error" => "user not found"];
        }

        if (!password_verify((string) $oldPassword, $user["password_hash"])) {
            return ["ok" => false, "error" => "current password is incorrect"];
        }

        if (!is_string($newPassword) || strlen($newPassword) < 8) {
            return ["ok" => false, "error" => "new password must be at least 8 characters"];
        }

        if (strlen($newPassword) > 128) {
            return ["ok" => false, "error" => "new password is too long"];
        }

        $ok = self::table()
            ->where(["id" => (int) $userId])
            ->save(["password_hash" => password_hash($newPassword, PASSWORD_DEFAULT)]);

        if ($ok === false) {
            return ["ok" => false, "error" => "failed to update password"];
        }

        # 改密后吊销该用户的全部令牌，强制所有客户端重新登录
        TokenService::revokeAll($userId);

        return ["ok" => true];
    }

    /**
     * 更新显示名
     *
     * @param integer $userId
     * @param string $nickname
     * @return boolean
     */
    public static function updateProfile($userId, $nickname)
    {
        $nickname = trim((string) $nickname);

        if ($nickname === "" || mb_strlen($nickname) > 64) {
            return false;
        }

        return self::table()
            ->where(["id" => (int) $userId])
            ->save(["nickname" => $nickname]) !== false;
    }

    /**
     * 调整用户已用容量
     *
     * 使用 ~ 前缀的原生表达式做原子自增/自减，避免「读取-计算-写回」的竞态。
     * 注意：~ 前缀内容原样拼接不转义，此处 $delta 已强制转为整数，无注入风险。
     *
     * @param integer $userId
     * @param integer $delta 正数增加，负数减少
     * @return void
     */
    public static function addUsedSize($userId, $delta)
    {
        $delta = (int) $delta;

        if ($delta === 0) {
            return;
        }

        if ($delta > 0) {
            $expr = "~`used_size` + " . $delta;
        } else {
            # 减法需防止无符号列下溢为极大值，用 GREATEST 兜底到 0
            $expr = "~GREATEST(CAST(`used_size` AS SIGNED) - " . abs($delta) . ", 0)";
        }

        self::table()->where(["id" => (int) $userId])->save(["used_size" => $expr]);
    }

    /**
     * 检查用户配额是否还能容纳指定大小
     *
     * @param integer $userId
     * @param integer $size 待新增的字节数
     * @return boolean 配额充足返回 true
     */
    public static function hasQuota($userId, $size)
    {
        $user = self::findById($userId);

        if ($user === null) {
            return false;
        }

        $quota = (int) $user["quota"];

        # quota 为 0 表示不限制容量
        if ($quota <= 0) {
            return true;
        }

        return ((int) $user["used_size"] + (int) $size) <= $quota;
    }

    /**
     * 输出安全的用户信息（剔除密码哈希等敏感字段）
     *
     * @param array $user
     * @return array
     */
    public static function publicView($user)
    {
        if (empty($user)) {
            return [];
        }

        return [
            "id"        => (int) $user["id"],
            "email"     => $user["email"],
            "nickname"  => $user["nickname"],
            "role"      => $user["role"],
            "status"    => (int) $user["status"],
            "quota"     => (int) $user["quota"],
            "used_size" => (int) $user["used_size"],
            "create_time" => $user["create_time"],
            "last_login"  => $user["last_login"]
        ];
    }
}
