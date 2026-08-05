<?php
/**
 * src/folder.php —— 文件夹与邮件管理接口控制器
 *
 * 挂载于 api.php 入口：/api.php?ctl=folder&app=方法名
 * 重写后：/api/folder/方法名
 */

class FolderApp
{
    /**
     * 列出全部文件夹（含系统与自建），附带总数与未读数
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     */
    public function list()
    {
        $user = require_login();

        controller::success(FolderService::listAll($user["id"]));
    }

    /**
     * 创建自建文件夹
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require name=string
     */
    public function create()
    {
        $user = require_login();

        $name = input("name", "");

        $result = FolderService::create($user["id"], $name);

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        controller::success(["id" => $result["id"], "message" => "folder created"]);
    }

    /**
     * 重命名自建文件夹
     *
     * 系统文件夹不可重命名。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require id=i32,name=string
     */
    public function rename()
    {
        $user = require_login();

        $folderId = input_int("id", 0);
        $name     = input("name", "");

        $result = FolderService::rename($user["id"], $folderId, $name);

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        controller::success(["message" => "folder renamed"]);
    }

    /**
     * 删除自建文件夹
     *
     * 其中的邮件自动移入回收站；系统文件夹不可删除。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require id=i32
     */
    public function remove()
    {
        $user = require_login();

        $folderId = input_int("id", 0);

        $result = FolderService::remove($user["id"], $folderId);

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        controller::success([
            "message"  => "folder deleted",
            "moved"    => $result["moved"]
        ]);
    }

    /**
     * 移动邮件到指定文件夹
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require id=i32,target=i32
     */
    public function move()
    {
        $user = require_login();

        $mailId  = input_int("id", 0);
        $targetId = input_int("target", 0);

        $mail = MailService::findOwned($mailId, $user["id"]);

        if ($mail === null) {
            controller::error("mail not found", 404);
        }

        # 校验目标文件夹归属
        if (FolderService::findOwned($targetId, $user["id"]) === null) {
            controller::error("folder not found", 404);
        }

        MailService::table()->where(["id" => $mailId])->save(["folder_id" => $targetId]);
        MailService::refreshThread($mail["thread_id"]);

        controller::success(["message" => "mail moved"]);
    }

    /**
     * 标记邮件已读 / 未读
     *
     * 支持单个或批量操作：单封传 id，批量传 ids（逗号分隔或 JSON 数组）。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     */
    public function mark()
    {
        $user = require_login();

        $isRead = input_bool("read", true);
        $ids    = $this->resolveMailIds();

        if (empty($ids)) {
            controller::error("mail id(s) required", 400);
        }

        $updated = 0;

        foreach ($ids as $mailId) {
            $mail = MailService::findOwned($mailId, $user["id"]);

            if ($mail === null) {
                continue;
            }

            MailService::table()
                ->where(["id" => $mailId])
                ->save(["is_read" => $isRead ? 1 : 0]);

            MailService::refreshThread($mail["thread_id"]);
            $updated++;
        }

        controller::success([
            "message" => $isRead ? "marked read" : "marked unread",
            "updated" => $updated
        ]);
    }

    /**
     * 切换星标
     *
     * 发送已加星标则取消星标，未加星标则加星标。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require id=i32
     */
    public function star()
    {
        $user = require_login();

        $mailId = input_int("id", 0);
        $mail = MailService::findOwned($mailId, $user["id"]);

        if ($mail === null) {
            controller::error("mail not found", 404);
        }

        $newStarred = (int) $mail["is_starred"] === 1 ? 0 : 1;

        MailService::table()
            ->where(["id" => $mailId])
            ->save(["is_starred" => $newStarred]);

        controller::success([
            "message"    => $newStarred ? "starred" : "unstarred",
            "is_starred" => $newStarred === 1
        ]);
    }

    /**
     * 批量操作
     *
     * 支持的 action：move、mark_read、mark_unread、star、unstar、delete、permanent_delete
     * ids 传操作对象邮件 id 数组；target 传目标文件夹 id（action=move 时必需）。
     *
     * 「批量更新必须显式传 $limit1=false，否则只会更新一行。」
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     */
    public function batch()
    {
        $user = require_login();

        $action = input("action", "");
        $ids    = $this->resolveMailIds();

        if (empty($ids)) {
            controller::error("mail ids required", 400);
        }

        if (!in_array($action, ["move", "mark_read", "mark_unread", "star", "unstar", "delete", "permanent_delete"], true)) {
            controller::error("unsupported batch action: " . $action, 400);
        }

        $updated = 0;

        switch ($action) {
            case "move":
                $targetId = input_int("target", 0);

                if (FolderService::findOwned($targetId, $user["id"]) === null) {
                    controller::error("folder not found", 404);
                }

                foreach ($ids as $mailId) {
                    $mail = MailService::findOwned($mailId, $user["id"]);

                    if ($mail === null) {
                        continue;
                    }

                    MailService::table()->where(["id" => $mailId])->save(["folder_id" => $targetId]);
                    MailService::refreshThread($mail["thread_id"]);
                    $updated++;
                }
                break;

            case "mark_read":
            case "mark_unread":
                $read = $action === "mark_read" ? 1 : 0;

                foreach ($ids as $mailId) {
                    $mail = MailService::findOwned($mailId, $user["id"]);

                    if ($mail === null) {
                        continue;
                    }

                    MailService::table()->where(["id" => $mailId])->save(["is_read" => $read]);
                    MailService::refreshThread($mail["thread_id"]);
                    $updated++;
                }
                break;

            case "star":
            case "unstar":
                $starred = $action === "star" ? 1 : 0;

                foreach ($ids as $mailId) {
                    $mail = MailService::findOwned($mailId, $user["id"]);

                    if ($mail === null) {
                        continue;
                    }

                    MailService::table()->where(["id" => $mailId])->save(["is_starred" => $starred]);
                    $updated++;
                }
                break;

            case "delete":
            case "permanent_delete":
                $trashId = FolderService::idOfType($user["id"], FolderService::TRASH);
                $perm = $action === "permanent_delete";

                foreach ($ids as $mailId) {
                    $mail = MailService::findOwned($mailId, $user["id"]);

                    if ($mail === null) {
                        continue;
                    }

                    if ($perm || (int) $mail["folder_id"] === $trashId) {
                        # 彻底删除
                        foreach (AttachmentService::listOfMail($mailId) as $att) {
                            AttachmentService::remove($att["id"], $user["id"]);
                        }

                        MailService::recipients()->where(["mail_id" => $mailId])->delete();
                        MailService::table()->where(["id" => $mailId])->delete();

                        if (!empty($mail["raw_path"])) {
                            $rawFile = AttachmentService::rawRoot() . "/" . $mail["raw_path"];

                            if (is_file($rawFile)) {
                                @unlink($rawFile);
                            }
                        }

                        UserService::addUsedSize($user["id"], -(int) $mail["size"]);
                    } else {
                        # 移入回收站
                        MailService::table()->where(["id" => $mailId])->save(["folder_id" => $trashId]);
                    }

                    MailService::refreshThread($mail["thread_id"]);
                    $updated++;
                }
                break;
        }

        controller::success([
            "message" => "batch action completed: {$action}",
            "updated" => $updated
        ]);
    }

    /**
     * 列出某文件夹中的邮件（摘要分页）
     *
     * 支持 folder_id 或 folder_type 快速定位。
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     */
    public function mails()
    {
        $user = require_login();

        $paging = input_paging(20, 100);
        $folderId   = input_int("folder_id", 0);
        $folderType = input("folder_type", "");

        # 按类型定位文件夹
        if ($folderId <= 0 && $folderType !== "") {
            $folderId = FolderService::idOfType($user["id"], $folderType);
        }

        if ($folderId <= 0) {
            controller::error("folder_id or folder_type required", 400);
        }

        if (FolderService::findOwned($folderId, $user["id"]) === null) {
            controller::error("folder not found", 404);
        }

        # 只获取非草稿的邮件
        $condition = [
            "user_id"   => (int) $user["id"],
            "folder_id" => $folderId,
            "is_draft"  => 0
        ];

        $table = MailService::table();
        $total = (int) $table->where($condition)->count();

        $rows = $table->where($condition)
            ->order_by("mail_time", true)
            ->limit($paging["offset"], $paging["size"])
            ->select(MailService::summaryFields());

        $list = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $list[] = MailService::summaryView($row);
            }
        }

        controller::success(paged_result($list, $total, $paging));
    }

    // =================================================================
    // 辅助
    // =================================================================

    /**
     * 从请求中解析邮件 id 列表（支持逗号分隔字符串与 JSON 数组）
     *
     * @return array
     */
    private function resolveMailIds()
    {
        $raw = input("id", "");

        if ($raw === "") {
            $raw = input("ids", "");

            if ($raw === "") {
                return [];
            }
        }

        # JSON 数组：["1","2","3"] 或 [1,2,3]
        if (is_string($raw) && $raw[0] === "[") {
            $decoded = @json_decode($raw, true);

            if (is_array($decoded)) {
                return array_map("intval", $decoded);
            }
        }

        # 逗号分隔：1,2,3
        $parts = explode(",", $raw);
        $ids   = [];

        foreach ($parts as $part) {
            $id = (int) trim($part);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
