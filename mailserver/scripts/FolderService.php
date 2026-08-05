<?php
/**
 * FolderService.php —— 邮件文件夹服务
 *
 * 系统文件夹在用户注册时自动创建，以 is_system=1 标记，不可重命名或删除。
 * 用户可自建普通文件夹用于归类。
 */

class FolderService
{
    # 系统文件夹类型常量。收信、发信、草稿等逻辑都依据这些类型定位目标文件夹
    const INBOX  = "inbox";
    const SENT   = "sent";
    const DRAFTS = "drafts";
    const SPAM   = "spam";
    const TRASH  = "trash";

    /**
     * 系统文件夹定义：类型 => 默认显示名
     *
     * @return array
     */
    public static function systemFolders()
    {
        return [
            self::INBOX  => "收件箱",
            self::SENT   => "已发送",
            self::DRAFTS => "草稿箱",
            self::SPAM   => "垃圾邮件",
            self::TRASH  => "回收站"
        ];
    }

    /**
     * @return Table
     */
    public static function table()
    {
        return new Table(FOLDERS_TABLE);
    }

    /**
     * 为新用户初始化全部系统文件夹
     *
     * @param integer $userId
     * @return void
     */
    public static function initSystemFolders($userId)
    {
        $userId = (int) $userId;
        $now = now_time();

        foreach (self::systemFolders() as $type => $name) {
            # 幂等：已存在同类型文件夹则跳过，避免重复初始化产生重复行
            $exists = self::table()
                ->where(["user_id" => $userId, "type" => $type])
                ->find();

            if ($exists !== false) {
                continue;
            }

            self::table()->add([
                "user_id"     => $userId,
                "name"        => $name,
                "type"        => $type,
                "is_system"   => 1,
                "create_time" => $now
            ]);
        }
    }

    /**
     * 按类型取得用户的系统文件夹
     *
     * @param integer $userId
     * @param string $type
     * @return array|null
     */
    public static function findByType($userId, $type)
    {
        $row = self::table()
            ->where(["user_id" => (int) $userId, "type" => $type])
            ->find();

        return $row === false ? null : $row;
    }

    /**
     * 按类型取得文件夹 id，不存在时自动补建
     *
     * 用于 SMTP 收信等场景：即使用户数据不完整也能保证投递有落点。
     *
     * @param integer $userId
     * @param string $type
     * @return integer 取不到返回 0
     */
    public static function idOfType($userId, $type)
    {
        $folder = self::findByType($userId, $type);

        if ($folder !== null) {
            return (int) $folder["id"];
        }

        # 系统文件夹缺失时补建
        $names = self::systemFolders();

        if (!isset($names[$type])) {
            return 0;
        }

        $newId = self::table()->add([
            "user_id"     => (int) $userId,
            "name"        => $names[$type],
            "type"        => $type,
            "is_system"   => 1,
            "create_time" => now_time()
        ]);

        return $newId === false ? 0 : (int) $newId;
    }

    /**
     * 按 id 取文件夹，并校验归属
     *
     * 校验归属可防止用户通过伪造 folder_id 访问他人文件夹。
     *
     * @param integer $folderId
     * @param integer $userId
     * @return array|null 不存在或不属于该用户时返回 null
     */
    public static function findOwned($folderId, $userId)
    {
        $row = self::table()
            ->where(["id" => (int) $folderId, "user_id" => (int) $userId])
            ->find();

        return $row === false ? null : $row;
    }

    /**
     * 列出用户的全部文件夹，附带邮件总数与未读数
     *
     * @param integer $userId
     * @return array
     */
    public static function listAll($userId)
    {
        $userId = (int) $userId;

        $rows = self::table()
            ->where(["user_id" => $userId])
            ->order_by("is_system", true)
            ->select();

        if ($rows === false) {
            return [];
        }

        $mails = new Table(MAILS_TABLE);
        $result = [];

        foreach ($rows as $row) {
            $fid = (int) $row["id"];

            # 每个文件夹分别统计总数与未读数。
            # Table 链式调用返回新实例，可安全复用 $mails 基础对象
            $total = $mails->where(["user_id" => $userId, "folder_id" => $fid])->count();
            $unread = $mails->where([
                "user_id"   => $userId,
                "folder_id" => $fid,
                "is_read"   => 0
            ])->count();

            $result[] = [
                "id"        => $fid,
                "name"      => $row["name"],
                "type"      => $row["type"],
                "is_system" => (int) $row["is_system"],
                "total"     => (int) $total,
                "unread"    => (int) $unread
            ];
        }

        return $result;
    }

    /**
     * 创建自建文件夹
     *
     * @param integer $userId
     * @param string $name
     * @return array{ok:bool, error?:string, id?:int}
     */
    public static function create($userId, $name)
    {
        $name = trim((string) $name);

        if ($name === "") {
            return ["ok" => false, "error" => "folder name is required"];
        }

        if (mb_strlen($name) > 64) {
            return ["ok" => false, "error" => "folder name is too long"];
        }

        # 同名检查（数据库层还有 uk_user_name 唯一索引兜底）
        $exists = self::table()
            ->where(["user_id" => (int) $userId, "name" => $name])
            ->find();

        if ($exists !== false) {
            return ["ok" => false, "error" => "folder already exists"];
        }

        $newId = self::table()->add([
            "user_id"     => (int) $userId,
            "name"        => $name,
            "type"        => "custom",
            "is_system"   => 0,
            "create_time" => now_time()
        ]);

        if ($newId === false) {
            return ["ok" => false, "error" => "failed to create folder"];
        }

        return ["ok" => true, "id" => (int) $newId];
    }

    /**
     * 重命名自建文件夹
     *
     * @param integer $userId
     * @param integer $folderId
     * @param string $name
     * @return array{ok:bool, error?:string}
     */
    public static function rename($userId, $folderId, $name)
    {
        $folder = self::findOwned($folderId, $userId);

        if ($folder === null) {
            return ["ok" => false, "error" => "folder not found"];
        }

        if ((int) $folder["is_system"] === 1) {
            return ["ok" => false, "error" => "system folder cannot be renamed"];
        }

        $name = trim((string) $name);

        if ($name === "" || mb_strlen($name) > 64) {
            return ["ok" => false, "error" => "invalid folder name"];
        }

        $ok = self::table()
            ->where(["id" => (int) $folderId, "user_id" => (int) $userId])
            ->save(["name" => $name]);

        if ($ok === false) {
            return ["ok" => false, "error" => "failed to rename folder"];
        }

        return ["ok" => true];
    }

    /**
     * 删除自建文件夹，其中的邮件移入回收站
     *
     * @param integer $userId
     * @param integer $folderId
     * @return array{ok:bool, error?:string, moved?:int}
     */
    public static function remove($userId, $folderId)
    {
        $folder = self::findOwned($folderId, $userId);

        if ($folder === null) {
            return ["ok" => false, "error" => "folder not found"];
        }

        if ((int) $folder["is_system"] === 1) {
            return ["ok" => false, "error" => "system folder cannot be deleted"];
        }

        $trashId = self::idOfType($userId, self::TRASH);
        $mails = new Table(MAILS_TABLE);

        $moved = $mails->where([
            "user_id"   => (int) $userId,
            "folder_id" => (int) $folderId
        ])->count();

        # 批量更新必须显式传入 $limit1 = false，否则只会移动一封邮件
        $mails->where([
            "user_id"   => (int) $userId,
            "folder_id" => (int) $folderId
        ])->save(["folder_id" => $trashId], false);

        self::table()
            ->where(["id" => (int) $folderId, "user_id" => (int) $userId])
            ->delete();

        return ["ok" => true, "moved" => (int) $moved];
    }
}
