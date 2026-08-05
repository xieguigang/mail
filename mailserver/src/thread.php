<?php
/**
 * src/thread.php —— 会话与草稿接口控制器
 *
 * 挂载于 api.php 入口：/api.php?ctl=thread&app=方法名
 * 重写后：/api/thread/方法名
 */

class ThreadApp
{
    /**
     * 分页列出当前用户的邮件会话
     *
     * 按最后邮件时间倒序排列，每条包含参与人数、最新摘要等。
     *
     * @uses api
     * @access user|admin
     * @method GET|POST
     * @origin *
     */
    public function list()
    {
        $user = require_login();

        $paging = input_paging(20, 100);
        $page  = (int) $paging["page"];
        $limit = (int) $paging["size"];

        $threadsTable = new Table(THREADS_TABLE);

        $total = (int) $threadsTable
            ->where(["user_id" => (int) $user["id"]])
            ->where(new ValueEnumerable(["mail_count" => [">" => 0]]))
            ->count();

        $rows = $threadsTable
            ->where(["user_id" => (int) $user["id"]])
            ->where(new ValueEnumerable(["mail_count" => [">" => 0]]))
            ->order_by("last_time", true)
            ->limit($limit)
            ->offset($paging["offset"]);

        if ($rows === false) {
            $rows = [];
        }

        $list = [];

        foreach ($rows as $row) {
            # 取线程内最新一封邮件的摘要信息
            $lastMail = MailService::table()
                ->where(["thread_id" => (int) $row["id"], "is_draft" => 0])
                ->order_by("mail_time", true)
                ->limit(1)
                ->find();

            $fromInfo = ["address" => "", "name" => ""];

            if ($lastMail !== false) {
                $fromInfo = [
                    "address" => $lastMail["from_address"],
                    "name"    => $lastMail["from_name"]
                ];
            }

            # 统计参与者（发件人 + 收件人去重）
            $participants = $this->threadParticipants($row["id"]);

            $list[] = [
                "id"            => (int) $row["id"],
                "subject"       => $row["subject"],
                "mail_count"    => (int) $row["mail_count"],
                "unread_count"  => (int) $row["unread_count"],
                "last_time"     => $row["last_time"],
                "last_from"     => $fromInfo,
                "participants"  => $participants,
                "create_time"   => $row["create_time"]
            ];
        }

        controller::success(paged_result($list, $total, $paging));
    }

    /**
     * 会话详情：列出会话内全部邮件（按时间正序）
     *
     * 邮件较多时会话内分页，默认每页 50 条。
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     * @require id=i32
     */
    public function detail()
    {
        $user = require_login();

        $paging   = input_paging(50, 200);
        $threadId = input_int("id", 0);

        if ($threadId <= 0) {
            controller::error("thread id required", 400);
        }

        # 校验线程归属
        $thread = MailService::threads()
            ->where(["id" => $threadId, "user_id" => (int) $user["id"]])
            ->find();

        if ($thread === false) {
            controller::error("thread not found", 404);
        }

        $table = MailService::table();

        $total = (int) $table
            ->where(["thread_id" => $threadId, "user_id" => (int) $user["id"], "is_draft" => 0])
            ->count();

        $rows = $table
            ->where(["thread_id" => $threadId, "user_id" => (int) $user["id"], "is_draft" => 0])
            ->order_by("mail_time", false)
            ->limit($paging["size"])
            ->offset($paging["offset"])
            ->select(MailService::summaryFields());

        if ($rows === false) {
            $rows = [];
        }

        $list = [];

        foreach ($rows as $row) {
            $list[] = MailService::summaryView($row);
        }

        # 将线程内未读邮件标记为已读
        MailService::table()
            ->where(["thread_id" => $threadId, "user_id" => (int) $user["id"], "is_read" => 0])
            ->save(["is_read" => 1], false);

        MailService::refreshThread($threadId);

        controller::success([
            "thread"  => [
                "id"           => (int) $thread["id"],
                "subject"      => $thread["subject"],
                "mail_count"   => (int) $thread["mail_count"],
                "unread_count" => (int) $thread["unread_count"],
                "last_time"    => $thread["last_time"]
            ],
            "mails"   => paged_result($list, $total, $paging)
        ]);
    }

    /**
     * 新建或覆盖保存草稿
     *
     * 若传入 draft_id 则覆盖已有草稿，否则创建新草稿。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require to=string
     */
    public function draft_save()
    {
        $user = require_login();

        $draftId = input_int("draft_id", 0);

        $result = MailService::saveDraft($user, [
            "to"          => input("to", ""),
            "cc"          => input("cc", ""),
            "bcc"         => input("bcc", ""),
            "subject"     => input("subject", ""),
            "body_text"   => input("body_text", ""),
            "body_html"   => input("body_html", ""),
            "attachments" => input_array("attachments"),
            "in_reply_to" => input("in_reply_to", ""),
            "references"  => input("references", "")
        ], $draftId);

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        controller::success($result["data"]);
    }

    /**
     * 读取草稿详情
     *
     * 返回完整正文、收件人与附件列表，供客户端恢复编辑。
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     * @require id=i32
     */
    public function draft_get()
    {
        $user = require_login();

        $draftId = input_int("id", 0);

        $mail = MailService::table()
            ->where(["id" => $draftId, "user_id" => (int) $user["id"], "is_draft" => 1])
            ->find();

        if ($mail === false) {
            controller::error("draft not found", 404);
        }

        controller::success(MailService::detailView($mail));
    }

    /**
     * 将草稿转为正式发送
     *
     * 发信成功后自动删除草稿。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require id=i32
     */
    public function draft_send()
    {
        $user = require_login();

        $draftId = input_int("id", 0);

        $draft = MailService::table()
            ->where(["id" => $draftId, "user_id" => (int) $user["id"], "is_draft" => 1])
            ->find();

        if ($draft === false) {
            controller::error("draft not found", 404);
        }

        # 取回草稿的收件人信息
        $recipients = MailService::recipientsOf($draftId);

        $toList  = [];
        $ccList  = [];
        $bccList = [];

        foreach ($recipients as $r) {
            $formatted = !empty($r["name"])
                ? "{$r["name"]} <{$r["address"]}>"
                : $r["address"];

            if ($r["type"] === "cc") {
                $ccList[] = $formatted;
            } else if ($r["type"] === "bcc") {
                $bccList[] = $formatted;
            } else {
                $toList[] = $formatted;
            }
        }

        # 发布发送
        $result = MailService::send($user, [
            "to"          => implode(", ", $toList),
            "cc"          => implode(", ", $ccList),
            "bcc"         => implode(", ", $bccList),
            "subject"     => $draft["subject"],
            "body_text"   => $draft["body_text"],
            "body_html"   => $draft["body_html"],
            "attachments" => array_column(AttachmentService::listOfMail($draftId), "id"),
            "in_reply_to" => $draft["in_reply_to"],
            "references"  => $draft["references"]
        ]);

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        # 删除草稿及收件人记录
        MailService::recipients()->where(["mail_id" => $draftId])->delete();
        MailService::table()->where(["id" => $draftId])->delete();

        # 附件归属已转移到新邮件，无需额外处理

        controller::success($result["data"]);
    }

    /**
     * 删除草稿
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require id=i32
     */
    public function draft_delete()
    {
        $user = require_login();

        $draftId = input_int("id", 0);

        $draft = MailService::table()
            ->where(["id" => $draftId, "user_id" => (int) $user["id"], "is_draft" => 1])
            ->find();

        if ($draft === false) {
            controller::error("draft not found", 404);
        }

        # 清理关联附件
        foreach (AttachmentService::listOfMail($draftId) as $att) {
            AttachmentService::remove($att["id"], $user["id"]);
        }

        MailService::recipients()->where(["mail_id" => $draftId])->delete();
        MailService::table()->where(["id" => $draftId])->delete();

        controller::success(["message" => "draft deleted", "id" => $draftId]);
    }

    /**
     * 列出草稿列表
     *
     * 按最后修改时间倒序排列。
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     */
    public function drafts()
    {
        $user = require_login();

        $paging = input_paging(20, 100);

        $table = MailService::table();
        $condition = [
            "user_id"  => (int) $user["id"],
            "is_draft" => 1
        ];

        $total = (int) $table->where($condition)->count();

        $rows = $table->where($condition)
            ->order_by("mail_time", true)
            ->limit($paging["size"])
            ->offset($paging["offset"])
            ->select(MailService::summaryFields());

        if ($rows === false) {
            $rows = [];
        }

        $list = [];

        foreach ($rows as $row) {
            $list[] = MailService::summaryView($row);
        }

        controller::success(paged_result($list, $total, $paging));
    }

    // =================================================================
    // 辅助
    // =================================================================

    /**
     * 统计线程参与者（去重的邮件地址列表）
     *
     * @param integer $threadId
     * @return array 参与者的地址与名称列表
     */
    private function threadParticipants($threadId)
    {
        $mails = MailService::table()
            ->where(["thread_id" => (int) $threadId, "is_draft" => 0])
            ->select("id,from_address,from_name");

        if ($mails === false) {
            return [];
        }

        $seen = [];
        $participants = [];

        foreach ($mails as $mail) {
            # 添加发件人
            $addr = strtolower(trim($mail["from_address"]));

            if ($addr !== "" && !isset($seen[$addr])) {
                $seen[$addr] = true;

                $participants[] = [
                    "address" => $mail["from_address"],
                    "name"    => $mail["from_name"]
                ];
            }

            # 添加收件人
            $recipients = MailService::recipientsOf($mail["id"]);

            foreach ($recipients as $r) {
                $raddr = strtolower(trim($r["address"]));

                if ($raddr !== "" && !isset($seen[$raddr])) {
                    $seen[$raddr] = true;

                    $participants[] = [
                        "address" => $r["address"],
                        "name"    => $r["name"]
                    ];
                }
            }
        }

        return $participants;
    }
}
