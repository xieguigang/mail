<?php
/**
 * src/mail.php —— 邮件收发接口控制器
 *
 * 挂载于 api.php 入口：/api.php?ctl=mail&app=方法名
 * 重写后：/api/mail/方法名
 */

class MailApp
{
    /**
     * 发送邮件
     *
     * 本域收件人直接入库投递，外域收件人写入发信队列由守护进程异步投递，
     * 因此本接口会立即返回，不会因网络投递而阻塞。
     *
     * 附件通过 attachments 参数传入已上传完成的附件 id 数组，
     * 附件本身应先经 /api/attachment/ 系列接口分片上传。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require to=string
     */
    public function send()
    {
        $user = require_login();

        $result = MailService::send($user, [
            "to"          => input("to", ""),
            "cc"          => input("cc", ""),
            "bcc"         => input("bcc", ""),
            "subject"     => input("subject", ""),
            "body_text"   => input("body_text", ""),
            "body_html"   => input("body_html", ""),
            "attachments" => input_array("attachments"),
            "in_reply_to" => input("in_reply_to", ""),
            "references"  => input("references", "")
        ]);

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        controller::success($result["data"]);
    }

    /**
     * 查询邮件详情
     *
     * 返回完整正文与附件列表，并自动把邮件标记为已读。
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     * @require id=i32
     */
    public function get()
    {
        $user = require_login();

        $id = WebRequest::getInteger("id", 0);
        $mail = MailService::findOwned($id, $user["id"]);

        if ($mail === null) {
            controller::error("mail not found", 404);
        }

        # 读取详情时自动标记已读
        if ((int) $mail["is_read"] === 0) {
            MailService::table()->where(["id" => $id])->save(["is_read" => 1]);
            $mail["is_read"] = 1;
            MailService::refreshThread($mail["thread_id"]);
        }

        controller::success(MailService::detailView($mail));
    }

    /**
     * 邮件列表（按文件夹分页）
     *
     * 只返回摘要字段，不含正文大字段，以避免不必要的磁盘 I/O。
     *
     * @uses api
     * @access user|admin
     * @method GET|POST
     * @origin *
     */
    public function lists()
    {
        $user = require_login();

        $paging = input_paging(20, 100);
        $folderId = input_int("folder_id", 0);
        $folderType = input("folder_type", "");

        # 支持按文件夹类型定位（如 folder_type=inbox），
        # 免去客户端先查文件夹 id 的一次往返
        if ($folderId <= 0 && $folderType !== "") {
            $folderId = FolderService::idOfType($user["id"], $folderType);
        }

        $condition = ["user_id" => (int) $user["id"]];

        if ($folderId > 0) {
            # 校验文件夹归属，防止越权查看他人文件夹
            if (FolderService::findOwned($folderId, $user["id"]) === null) {
                controller::error("folder not found", 404);
            }

            $condition["folder_id"] = $folderId;
        }

        # 可选过滤条件
        if (input("unread_only", "") !== "") {
            $condition["is_read"] = input_bool("unread_only") ? 0 : 1;
        }

        if (input_bool("starred_only")) {
            $condition["is_starred"] = 1;
        }

        $table = MailService::table();

        # Table 链式调用返回新实例，原对象不受影响，
        # 因此可用同一个基础对象分别构建 count 与 select 查询
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

    /**
     * 删除邮件
     *
     * 默认为软删除（移入回收站）；
     * 已在回收站中的邮件，或显式指定 permanent=1 时执行彻底删除。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require id=i32
     */
    public function delete()
    {
        $user = require_login();

        $id = input_int("id", 0);
        $permanent = input_bool("permanent");

        $mail = MailService::findOwned($id, $user["id"]);

        if ($mail === null) {
            controller::error("mail not found", 404);
        }

        $trashId = FolderService::idOfType($user["id"], FolderService::TRASH);

        # 已在回收站中再次删除即为彻底删除
        if ($permanent || (int) $mail["folder_id"] === $trashId) {
            $this->purge($mail, $user);

            controller::success(["message" => "mail permanently deleted", "id" => $id]);
        }

        MailService::table()->where(["id" => $id])->save(["folder_id" => $trashId]);
        MailService::refreshThread($mail["thread_id"]);

        controller::success(["message" => "mail moved to trash", "id" => $id]);
    }

    /**
     * 彻底删除一封邮件及其附件
     *
     * @param array $mail
     * @param array $user
     * @return void
     */
    private function purge($mail, $user)
    {
        $mailId = (int) $mail["id"];

        # 逐个删除附件（内部会判断磁盘文件是否仍被其他记录引用）
        foreach (AttachmentService::listOfMail($mailId) as $att) {
            AttachmentService::remove($att["id"], $user["id"]);
        }

        MailService::recipients()->where(["mail_id" => $mailId])->delete();
        MailService::table()->where(["id" => $mailId])->delete();

        # 原始报文归档一并清理
        if (!empty($mail["raw_path"])) {
            $rawFile = AttachmentService::rawRoot() . "/" . $mail["raw_path"];

            if (is_file($rawFile)) {
                @unlink($rawFile);
            }
        }

        UserService::addUsedSize($user["id"], -(int) $mail["size"]);
        MailService::refreshThread($mail["thread_id"]);
    }

    /**
     * 查询邮件的投递状态
     *
     * 返回每个收件人的投递结果、重试次数与失败原因。
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     * @require id=i32
     */
    public function status()
    {
        $user = require_login();

        $id = WebRequest::getInteger("id", 0);
        $mail = MailService::findOwned($id, $user["id"]);

        if ($mail === null) {
            controller::error("mail not found", 404);
        }

        controller::success([
            "mail_id"     => $id,
            "send_status" => $mail["send_status"],
            "recipients"  => QueueService::statusOfMail($id)
        ]);
    }

    /**
     * 重新投递失败的邮件
     *
     * 把该邮件下所有 failed 状态的队列任务重置为待投递。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require id=i32
     */
    public function resend()
    {
        $user = require_login();

        $id = input_int("id", 0);
        $mail = MailService::findOwned($id, $user["id"]);

        if ($mail === null) {
            controller::error("mail not found", 404);
        }

        $count = QueueService::retryFailed($id);

        if ($count === 0) {
            controller::error("no failed delivery to retry", 400);
        }

        controller::success([
            "message" => "requeued for delivery",
            "count"   => $count
        ]);
    }

    /**
     * 下载邮件原始报文
     *
     * 仅对由 SMTP 收到的邮件可用（发出的邮件不保存原始报文）。
     *
     * @uses router
     * @access user|admin
     * @method GET
     * @origin *
     * @require id=i32
     */
    public function raw()
    {
        $user = require_login();

        $id = WebRequest::getInteger("id", 0);
        $mail = MailService::findOwned($id, $user["id"]);

        if ($mail === null) {
            controller::error("mail not found", 404);
        }

        if (empty($mail["raw_path"])) {
            controller::error("raw message is not available for this mail", 404);
        }

        $path = AttachmentService::rawRoot() . "/" . $mail["raw_path"];

        if (!is_file($path)) {
            controller::error("raw message file is missing on server", 404);
        }

        # 复用附件的流式下载逻辑，同样支持 Range 断点续传
        AttachmentService::streamDownload([
            "store_path" => "",
            "filename"   => "message-" . $id . ".eml",
            "mime_type"  => "message/rfc822",
            "checksum"   => md5($mail["raw_path"]),
            "__abs_path" => $path
        ], false);
    }
}
