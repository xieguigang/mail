<?php
/**
 * TokenService.php —— API 访问令牌服务
 *
 * 设计要点：
 *   - 采用「随机串 + 服务端存储」而非自包含签名令牌（如 JWT），
 *     以支持主动吊销与「登出即失效」，并避免手写签名校验带来的实现风险。
 *   - 数据库只存 token 的 sha256 哈希，明文不落库；
 *     即使数据库泄露，攻击者也无法直接冒用令牌。
 *   - 令牌明文仅在签发时返回客户端一次。
 */

class TokenService
{
    /**
     * @return Table
     */
    public static function table()
    {
        return new Table(TOKENS_TABLE);
    }

    /**
     * 计算令牌哈希
     *
     * 使用 sha256 而非 password_hash：令牌本身是高熵随机串，
     * 无需抗暴力破解的慢哈希，且校验时需要按哈希做等值索引查找。
     *
     * @param string $token 明文令牌
     * @return string 64 位十六进制
     */
    private static function hash($token)
    {
        return hash("sha256", (string) $token);
    }

    /**
     * 为用户签发新令牌
     *
     * @param integer $userId
     * @param integer|null $ttl 有效期（秒），为空则读取配置
     * @return array{token:string, expire_time:string}|false 失败返回 false
     */
    public static function issue($userId, $ttl = null)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return false;
        }

        if ($ttl === null) {
            $ttl = (int) DotNetRegistry::Read("TOKEN_TTL", 604800);
        }

        # 32 字节随机数据 → 64 位十六进制串，熵值足以抵抗猜测
        $token = random_token(32);
        $expire = now_time(time() + (int) $ttl);

        $table = self::table();
        $newId = $table->add([
            "user_id"     => $userId,
            "token_hash"  => self::hash($token),
            "create_time" => now_time(),
            "expire_time" => $expire,
            "client_ip"   => Utils::UserIPAddress(),
            "status"      => 1
        ]);

        if ($newId === false) {
            mail_log("api", "token issue failed: " . $table->getLastMySqlError());
            return false;
        }

        # 明文令牌只在此处返回一次，之后无法再取回
        return ["token" => $token, "expire_time" => $expire];
    }

    /**
     * 校验令牌并返回对应用户
     *
     * 校验项：存在性 → 是否已吊销 → 是否过期 → 用户是否有效。
     *
     * @param string $token 明文令牌
     * @return array|null 有效返回用户记录，否则 null
     */
    public static function resolve($token)
    {
        if (empty($token) || !is_string($token)) {
            return null;
        }

        # 长度与字符集预检，避免把明显非法的输入送进数据库查询
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $row = self::table()
            ->where(["token_hash" => self::hash($token), "status" => 1])
            ->find();

        if ($row === false) {
            return null;
        }

        # 过期判定放在 PHP 侧，避免依赖数据库与应用服务器的时钟一致性
        if (strtotime($row["expire_time"]) <= time()) {
            return null;
        }

        $user = UserService::findById($row["user_id"]);

        if ($user === null || (int) $user["status"] !== 1) {
            return null;
        }

        # 更新最近使用时间。此处不做频率控制，
        # 若写入压力大可改为「距上次更新超过 N 分钟才写」
        self::table()->where(["id" => (int) $row["id"]])->save(["last_used" => now_time()]);

        return $user;
    }

    /**
     * 吊销单个令牌（登出）
     *
     * @param string $token 明文令牌
     * @return boolean
     */
    public static function revoke($token)
    {
        if (empty($token) || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return false;
        }

        return self::table()
            ->where(["token_hash" => self::hash($token)])
            ->save(["status" => 0]) !== false;
    }

    /**
     * 吊销某用户的全部令牌（改密、注销所有设备）
     *
     * @param integer $userId
     * @return boolean
     */
    public static function revokeAll($userId)
    {
        # 批量更新：save() 的 $limit1 参数默认为 true，只会更新一行。
        # 此处必须显式传入 false，否则只有一个令牌被吊销
        return self::table()
            ->where(["user_id" => (int) $userId, "status" => 1])
            ->save(["status" => 0], false) !== false;
    }

    /**
     * 清理过期与已吊销的令牌（由 cleanup.php 定时调用）
     *
     * @return integer 受影响行数，失败返回 0
     */
    public static function purgeExpired()
    {
        $table = self::table();

        # 统计待清理数量，用于返回清理结果
        $count = $table->where(["expire_time" => "~< '" . now_time() . "'"])->count();

        # 删除已过期的记录。where 条件使用原生表达式做时间比较，
        # 时间串由 now_time() 生成，非用户输入，无注入风险
        $table->where(["expire_time" => "~< '" . now_time() . "'"])->delete();

        return (int) $count;
    }
}
