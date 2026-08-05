<?php
/**
 * MailService.php —— 邮件核心编排服务
 *
 * 数据模型要点：
 *   每一封邮件在每一个用户的邮箱中都是一条独立的 mails 记录。
 *   发件人的「已发送」与收件人的「收件箱」是两条不同的记录，
 *   这样文件夹归属、已读状态、星标、删除都能各自独立，互不影响。
 *   两条记录通过相同的 message_id 关联。
 */

class MailService
{
    /**
     * @return Table
     */
    public static function table()
    {
        return new Table(MAILS_TABLE);
    }

    /**
     * @return Table
     */
    public static function recipients()
    {
        return new Table(RECIPIENTS_TABLE);
    }

    /**
     * @return Table
     */
    public static function threads()
    {
        return new Table(THREADS_TABLE);
    }

    /**
     * 列表查询只取这些列
     *
     * 刻意排除 body_text / body_html 两个大字段：
     * 列表页不需要正文，读取它们会造成大量无谓的磁盘 I/O 与网络传输。
     *
     * @return string
     */
    public static function summaryFields()
    {
        return "id,user_id,folder_id,thread_id,message_id,from_address,from_name,"
            . "to_summary,subject,summary,size,has_attach,is_read,is_starred,"
            . "is_draft,direction,send_status,mail_time,create_time";
    }

    /**
     * 取得邮件并校验归属
     *
     * @param integer $mailId
     * @param integer $userId
     * @return array|null
     */
    public static function findOwned($mailId, $userId)
    {
        $row = self::table()
            ->where(["id" => (int) $mailId, "user_id" => (int) $userId])
            ->find();

        return $row === false ? null : $row;
    }

    /**
     * 取得邮件的收件人明细
     *
     * @param integer $mailId
     * @return array
     */
    public static function recipientsOf($mailId)
    {
        $rows = self::recipients()->where(["mail_id" => (int) $mailId])->select();

        return is_array($rows) ? $rows : [];
    }

    /**
     * 写入收件人明细
     *
     * @param integer $mailId
     * @param array $list MailAddress::parseList() 的结果
     * @param string $type to / cc / bcc
     * @return void
     */
    public static function saveRecipients($mailId, $list, $type)
    {
        $table = self::recipients();

        foreach ($list as $one) {
            $table->add([
                "mail_id" => (int) $mailId,
                "type"    => $type,
                "address" => $one["address"],
                "name"    => isset($one["name"]) ? $one["name"] : ""
            ]);
        }
    }

    /**
     * 生成收件人摘要串（冗余存储于 mails.to_summary，供列表展示）
     *
     * @param array $to
     * @param array $cc
     * @return string
     */
    public static function makeToSummary($to, $cc = [])
    {
        $all = array_merge($to, $cc);
        $parts = [];

        foreach ($all as $one) {
            $parts[] = MailAddress::format($one);
        }

        $text = implode(", ", $parts);

        # 字段长度为 1024，超长时截断
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 500) . "...";
        }

        return $text;
    }

    // =================================================================
    // 会话聚合
    // =================================================================

    /**
     * 规范化主题：剥离 Re:/Fwd: 等回复转发前缀
     *
     * 用于把「Re: Re: 项目进度」与「项目进度」聚合到同一会话。
     *
     * @param string $subject
     * @return string
     */
    public static function normalizeSubject($subject)
    {
        $s = trim((string) $subject);

        # 反复剥离前缀，处理多层嵌套的 Re: Re: Fwd:
        $pattern = '/^\s*(re|fw|fwd|答复|回复|转发)\s*(\[\d+\])?\s*[:：]\s*/iu';

        while (preg_match($pattern, $s)) {
            $s = preg_replace($pattern, "", $s);
        }

        return trim($s);
    }

    /**
     * 计算邮件所属会话，不存在则创建
     *
     * 聚合依据优先级：
     *   1) In-Reply-To / References 指向的邮件已在某会话中 → 归入该会话
     *   2) 同一用户下存在规范化主题相同的会话 → 归入该会话
     *   3) 都不满足 → 新建会话
     *
     * @param integer $userId
     * @param string $subject
     * @param string $inReplyTo
     * @param string $references
     * @param string $mailTime
     * @return integer 会话 id
     */
    public static function resolveThread($userId, $subject, $inReplyTo, $references, $mailTime)
    {
        $userId = (int) $userId;

        # ---- 依据引用关系查找 ----
        $refIds = [];

        if (!empty($inReplyTo)) {
            $refIds[] = trim($inReplyTo, " <>");
        }

        if (!empty($references)) {
            foreach (preg_split('/\s+/', trim($references)) as $r) {
                $r = trim($r, " <>");

                if ($r !== "") {
                    $refIds[] = $r;
                }
            }
        }

        foreach (array_unique($refIds) as $mid) {
            $ref = self::table()
                ->where(["user_id" => $userId, "message_id" => $mid])
                ->find();

            if ($ref !== false && (int) $ref["thread_id"] > 0) {
                return (int) $ref["thread_id"];
            }
        }

        # ---- 依据规范化主题查找 ----
        $normalized = self::normalizeSubject($subject);

        if ($normalized !== "") {
            $thread = self::threads()
                ->where(["user_id" => $userId, "subject" => $normalized])
                ->find();

            if ($thread !== false) {
                return (int) $thread["id"];
            }
        }

        # ---- 新建会话 ----
        $newId = self::threads()->add([
            "user_id"      => $userId,
            "subject"      => $normalized === "" ? "(无主题)" : $normalized,
            "mail_count"   => 0,
            "unread_count" => 0,
            "last_time"    => $mailTime,
            "create_time"  => now_time()
        ]);

        return $newId === false ? 0 : (int) $newId;
    }

    /**
     * 重新统计会话的邮件数、未读数与最后时间
     *
     * 采用「重新统计」而非「增量自增」，
     * 可保证在邮件被移动、删除后计数依然准确。
     *
     * @param integer $threadId
     * @return void
     */
    public static function refreshThread($threadId)
    {
        $threadId = (int) $threadId;

        if ($threadId <= 0) {
            return;
        }

        $mails = self::table();

        $count = (int) $mails->where(["thread_id" => $threadId])->count();

        if ($count === 0) {
            # 会话已空，直接删除
            self::threads()->where(["id" => $threadId])->delete();
            return;
        }

        $unread = (int) $mails->where(["thread_id" => $threadId, "is_read" => 0])->count();
        $last = $mails->where(["thread_id" => $threadId])->ExecuteScalar("max(`mail_time`)");

        self::threads()->where(["id" => $threadId])->save([
            "mail_count"   => $count,
            "unread_count" => $unread,
            "last_time"    => $last ? $last : now_time()
        ]);
    }

    // =================================================================
    // 发信
    // =================================================================

    /**
     * 创建并投递一封邮件
     *
     * 流程：
     *   1) 校验收件人与配额
     *   2) 在发件人的「已发送」中创建记录
     *   3) 关联附件
     *   4) 本域收件人直接入库投递；外域收件人写入发信队列
     *
     * 发信接口只负责入库与入队即返回，实际的网络投递由
     * daemon/sender.php 异步执行，避免 HTTP 请求被长时间阻塞。
     *
     * @param array $sender 发件人用户记录
     * @param array $options 邮件内容与选项
     * @return array{ok:bool, error?:string, data?:array}
     */
    public static function send($sender, $options)
    {
        $to = MailAddress::parseList(isset($options["to"]) ? $options["to"] : "");
        $cc = MailAddress::parseList(isset($options["cc"]) ? $options["cc"] : "");
        $bcc = MailAddress::parseList(isset($options["bcc"]) ? $options["bcc"] : "");

        if (empty($to) && empty($cc) && empty($bcc)) {
            return ["ok" => false, "error" => "at least one valid recipient is required"];
        }

        $all = array_merge($to, $cc, $bcc);

        if (count($all) > 200) {
            return ["ok" => false, "error" => "too many recipients, maximum is 200"];
        }

        $subject = trim((string) (isset($options["subject"]) ? $options["subject"] : ""));
        $bodyText = (string) (isset($options["body_text"]) ? $options["body_text"] : "");
        $bodyHtml = (string) (isset($options["body_html"]) ? $options["body_html"] : "");
        $attachmentIds = isset($options["attachments"]) ? $options["attachments"] : [];

        # ---- 计算附件总大小并校验配额 ----
        $attachSize = 0;

        foreach ($attachmentIds as $aid) {
            $att = AttachmentService::table()
                ->where(["id" => (int) $aid, "user_id" => (int) $sender["id"]])
                ->find();

            if ($att !== false) {
                $attachSize += (int) $att["size"];
            }
        }

        if (!UserService::hasQuota($sender["id"], $attachSize)) {
            return ["ok" => false, "error" => "storage quota exceeded"];
        }

        $now = now_time();
        $messageId = MimeBuilder::newMessageId();
        $inReplyTo = trim((string) (isset($options["in_reply_to"]) ? $options["in_reply_to"] : ""));
        $references = trim((string) (isset($options["references"]) ? $options["references"] : ""));

        $sentFolder = FolderService::idOfType($sender["id"], FolderService::SENT);
        $threadId = self::resolveThread($sender["id"], $subject, $inReplyTo, $references, $now);

        $summary = MimeParser::makeSummary($bodyText, $bodyHtml);

        # ---- 在发件人的「已发送」中创建记录 ----
        $mailId = self::table()->add([
            "user_id"      => (int) $sender["id"],
            "folder_id"    => $sentFolder,
            "thread_id"    => $threadId,
            "message_id"   => $messageId,
            "in_reply_to"  => $inReplyTo,
            "references"   => $references,
            "from_address" => $sender["email"],
            "from_name"    => $sender["nickname"],
            "to_summary"   => self::makeToSummary($to, $cc),
            "subject"      => $subject,
            "body_text"    => $bodyText,
            "body_html"    => $bodyHtml,
            "summary"      => $summary,
            "size"         => strlen($bodyText) + strlen($bodyHtml) + $attachSize,
            "has_attach"   => empty($attachmentIds) ? 0 : 1,
            # 自己发出的邮件默认已读
            "is_read"      => 1,
            "is_starred"   => 0,
            "is_draft"     => 0,
            "direction"    => "out",
            "send_status"  => "queued",
            "raw_path"     => "",
            "mail_time"    => $now,
            "create_time"  => $now
        ]);

        if ($mailId === false) {
            return ["ok" => false, "error" => "failed to create mail record"];
        }

        $mailId = (int) $mailId;

        # ---- 写收件人明细 ----
        self::saveRecipients($mailId, $to, "to");
        self::saveRecipients($mailId, $cc, "cc");
        self::saveRecipients($mailId, $bcc, "bcc");

        # ---- 关联附件 ----
        if (!empty($attachmentIds)) {
            AttachmentService::attachToMail($attachmentIds, $mailId, $sender["id"]);
        }

        self::refreshThread($threadId);

        # ---- 分流投递 ----
        $localCount = 0;
        $remoteCount = 0;

        foreach ($all as $one) {
            if (MailAddress::isLocal($one["address"])) {
                # 本域收件人：直接入库，无需走网络
                if (self::deliverLocal($mailId, $one["address"])) {
                    $localCount++;
                }
            } else {
                # 外域收件人：写入发信队列，由守护进程异步投递
                QueueService::enqueue($mailId, $sender["email"], $one["address"]);
                $remoteCount++;
            }
        }

        # 全部为本域收件人时无需等待队列，直接标记为已送达
        if ($remoteCount === 0) {
            self::table()->where(["id" => $mailId])->save(["send_status" => "sent"]);
        }

        return [
            "ok" => true,
            "data" => [
                "mail_id"    => $mailId,
                "message_id" => $messageId,
                "local"      => $localCount,
                "queued"     => $remoteCount,
                "status"     => $remoteCount === 0 ? "sent" : "queued"
            ]
        ];
    }

    /**
     * 本域投递：把邮件复制到本服务器上收件人的收件箱
     *
     * @param integer $sourceMailId 源邮件（发件人「已发送」中的记录）id
     * @param string $toAddress 收件人地址
     * @return boolean
     */
    public static function deliverLocal($sourceMailId, $toAddress)
    {
        $recipient = UserService::findByEmail($toAddress);

        if ($recipient === null || (int) $recipient["status"] !== 1) {
            return false;
        }

        $source = self::table()->where(["id" => (int) $sourceMailId])->find();

        if ($source === false) {
            return false;
        }

        # 收件人配额不足时拒收
        if (!UserService::hasQuota($recipient["id"], (int) $source["size"])) {
            mail_log("delivery", "local delivery rejected, quota exceeded: " . $toAddress);
            return false;
        }

        $inbox = FolderService::idOfType($recipient["id"], FolderService::INBOX);
        $threadId = self::resolveThread(
            $recipient["id"],
            $source["subject"],
            $source["in_reply_to"],
            $source["references"],
            $source["mail_time"]
        );

        $newId = self::table()->add([
            "user_id"      => (int) $recipient["id"],
            "folder_id"    => $inbox,
            "thread_id"    => $threadId,
            # 保持与源邮件相同的 message_id，便于跨账号追溯同一封邮件
            "message_id"   => $source["message_id"],
            "in_reply_to"  => $source["in_reply_to"],
            "references"   => $source["references"],
            "from_address" => $source["from_address"],
            "from_name"    => $source["from_name"],
            "to_summary"   => $source["to_summary"],
            "subject"      => $source["subject"],
            "body_text"    => $source["body_text"],
            "body_html"    => $source["body_html"],
            "summary"      => $source["summary"],
            "size"         => (int) $source["size"],
            "has_attach"   => (int) $source["has_attach"],
            # 收到的邮件默认未读
            "is_read"      => 0,
            "is_starred"   => 0,
            "is_draft"     => 0,
            "direction"    => "in",
            "send_status"  => "received",
            "raw_path"     => "",
            "mail_time"    => $source["mail_time"],
            "create_time"  => now_time()
        ]);

        if ($newId === false) {
            return false;
        }

        # 复制附件元数据（磁盘文件共享，不重复占用存储）
        if ((int) $source["has_attach"] === 1) {
            AttachmentService::copyToMail($sourceMailId, (int) $newId, (int) $recipient["id"]);
        }

        UserService::addUsedSize($recipient["id"], (int) $source["size"]);
        self::refreshThread($threadId);

        return true;
    }

    /**
     * 投递一封从 SMTP 收到的外部邮件
     *
     * 由 daemon/smtpd.php 在解析完报文后调用。
     *
     * @param array $recipient 收件人用户记录
     * @param array $parsed MimeParser::parse() 的结果
     * @param string $rawPath 原始报文归档路径（相对 raw 目录）
     * @param integer $rawSize 报文体积
     * @return integer 新邮件 id，失败返回 0
     */
    public static function deliverInbound($recipient, $parsed, $rawPath, $rawSize)
    {
        $userId = (int) $recipient["id"];

        if (!UserService::hasQuota($userId, $rawSize)) {
            mail_log("smtpd", "inbound rejected, quota exceeded: " . $recipient["email"]);
            return 0;
        }

        $from = $parsed["from"];
        $fromAddress = ($from !== false && isset($from["address"])) ? $from["address"] : "";
        $fromName = ($from !== false && isset($from["name"])) ? $from["name"] : "";

        $subject = isset($parsed["subject"]) ? $parsed["subject"] : "";
        $mailTime = isset($parsed["date"]) ? $parsed["date"] : now_time();

        $inbox = FolderService::idOfType($userId, FolderService::INBOX);
        $threadId = self::resolveThread(
            $userId,
            $subject,
            $parsed["in_reply_to"],
            $parsed["references"],
            $mailTime
        );

        $bodyText = isset($parsed["text"]) ? $parsed["text"] : "";
        $bodyHtml = isset($parsed["html"]) ? $parsed["html"] : "";
        $attachments = isset($parsed["attachments"]) ? $parsed["attachments"] : [];

        $messageId = $parsed["message_id"];

        if ($messageId === "") {
            $messageId = MimeBuilder::newMessageId();
        }

        # 去重：同一用户收到相同 message_id 的邮件时跳过，
        # 防止重复投递（外部服务器重试时可能重复送达）
        $exists = self::table()
            ->where(["user_id" => $userId, "message_id" => $messageId])
            ->find();

        if ($exists !== false) {
            return (int) $exists["id"];
        }

        $newId = self::table()->add([
            "user_id"      => $userId,
            "folder_id"    => $inbox,
            "thread_id"    => $threadId,
            "message_id"   => $messageId,
            "in_reply_to"  => $parsed["in_reply_to"],
            "references"   => $parsed["references"],
            "from_address" => $fromAddress,
            "from_name"    => $fromName,
            "to_summary"   => self::makeToSummary($parsed["to"], $parsed["cc"]),
            "subject"      => $subject,
            "body_text"    => $bodyText,
            "body_html"    => $bodyHtml,
            "summary"      => MimeParser::makeSummary($bodyText, $bodyHtml),
            "size"         => (int) $rawSize,
            "has_attach"   => empty($attachments) ? 0 : 1,
            "is_read"      => 0,
            "is_starred"   => 0,
            "is_draft"     => 0,
            "direction"    => "in",
            "send_status"  => "received",
            "raw_path"     => $rawPath,
            "mail_time"    => $mailTime,
            "create_time"  => now_time()
        ]);

        if ($newId === false) {
            return 0;
        }

        $newId = (int) $newId;

        # 写收件人明细
        self::saveRecipients($newId, $parsed["to"], "to");
        self::saveRecipients($newId, $parsed["cc"], "cc");

        # 保存解析出的附件
        foreach ($attachments as $att) {
            AttachmentService::createFromFile(
                $userId,
                $newId,
                $att["filename"],
                $att["mime_type"],
                $att["temp_path"],
                $att["content_id"],
                $att["is_inline"]
            );
        }

        UserService::addUsedSize($userId, $rawSize);
        self::refreshThread($threadId);

        return $newId;
    }

    // =================================================================
    // 草稿
    // =================================================================

    /**
     * 保存或更新草稿
     *
     * @param array $user
     * @param array $options
     * @return array{ok:bool, error?:string, data?:array}
     */
    public static function saveDraft($user, $options)
    {
        $draftId = isset($options["draft_id"]) ? (int) $options["draft_id"] : 0;

        $to = MailAddress::parseList(isset($options["to"]) ? $options["to"] : "");
        $cc = MailAddress::parseList(isset($options["cc"]) ? $options["cc"] : "");
        $bcc = MailAddress::parseList(isset($options["bcc"]) ? $options["bcc"] : "");

        $subject = trim((string) (isset($options["subject"]) ? $options["subject"] : ""));
        $bodyText = (string) (isset($options["body_text"]) ? $options["body_text"] : "");
        $bodyHtml = (string) (isset($options["body_html"]) ? $options["body_html"] : "");
        $attachmentIds = isset($options["attachments"]) ? $options["attachments"] : [];

        $now = now_time();
        $draftFolder = FolderService::idOfType($user["id"], FolderService::DRAFTS);

        $payload = [
            "folder_id"    => $draftFolder,
            "from_address" => $user["email"],
            "from_name"    => $user["nickname"],
            "to_summary"   => self::makeToSummary($to, $cc),
            "subject"      => $subject,
            "body_text"    => $bodyText,
            "body_html"    => $bodyHtml,
            "summary"      => MimeParser::makeSummary($bodyText, $bodyHtml),
            "size"         => strlen($bodyText) + strlen($bodyHtml),
            "has_attach"   => empty($attachmentIds) ? 0 : 1,
            "is_read"      => 1,
            "is_draft"     => 1,
            "direction"    => "out",
            "send_status"  => "draft",
            "mail_time"    => $now
        ];

        if ($draftId > 0) {
            # ---- 更新已有草稿 ----
            $existing = self::table()
                ->where(["id" => $draftId, "user_id" => (int) $user["id"], "is_draft" => 1])
                ->find();

            if ($existing === false) {
                return ["ok" => false, "error" => "draft not found"];
            }

            self::table()->where(["id" => $draftId])->save($payload);

            # 收件人明细整体重写，避免残留旧地址
            self::recipients()->where(["mail_id" => $draftId])->delete();
            self::saveRecipients($draftId, $to, "to");
            self::saveRecipients($draftId, $cc, "cc");
            self::saveRecipients($draftId, $bcc, "bcc");

            if (!empty($attachmentIds)) {
                AttachmentService::attachToMail($attachmentIds, $draftId, $user["id"]);
            }

            return ["ok" => true, "data" => ["draft_id" => $draftId]];
        }

        # ---- 新建草稿 ----
        $payload["user_id"] = (int) $user["id"];
        $payload["thread_id"] = 0;
        $payload["message_id"] = MimeBuilder::newMessageId();
        $payload["in_reply_to"] = trim((string) (isset($options["in_reply_to"]) ? $options["in_reply_to"] : ""));
        $payload["references"] = trim((string) (isset($options["references"]) ? $options["references"] : ""));
        $payload["is_starred"] = 0;
        $payload["raw_path"] = "";
        $payload["create_time"] = $now;

        $newId = self::table()->add($payload);

        if ($newId === false) {
            return ["ok" => false, "error" => "failed to save draft"];
        }

        $newId = (int) $newId;

        self::saveRecipients($newId, $to, "to");
        self::saveRecipients($newId, $cc, "cc");
        self::saveRecipients($newId, $bcc, "bcc");

        if (!empty($attachmentIds)) {
            AttachmentService::attachToMail($attachmentIds, $newId, $user["id"]);
        }

        return ["ok" => true, "data" => ["draft_id" => $newId]];
    }

    // =================================================================
    // 展示
    // =================================================================

    /**
     * 邮件摘要视图（列表用）
     *
     * @param array $row
     * @return array
     */
    public static function summaryView($row)
    {
        return [
            "id"          => (int) $row["id"],
            "folder_id"   => (int) $row["folder_id"],
            "thread_id"   => (int) $row["thread_id"],
            "message_id"  => $row["message_id"],
            "from"        => [
                "address" => $row["from_address"],
                "name"    => $row["from_name"]
            ],
            "to_summary"  => $row["to_summary"],
            "subject"     => $row["subject"],
            "summary"     => $row["summary"],
            "size"        => (int) $row["size"],
            "has_attach"  => (int) $row["has_attach"] === 1,
            "is_read"     => (int) $row["is_read"] === 1,
            "is_starred"  => (int) $row["is_starred"] === 1,
            "is_draft"    => (int) $row["is_draft"] === 1,
            "direction"   => $row["direction"],
            "send_status" => $row["send_status"],
            "mail_time"   => $row["mail_time"]
        ];
    }

    /**
     * 邮件详情视图（含正文与附件）
     *
     * @param array $row
     * @return array
     */
    public static function detailView($row)
    {
        $view = self::summaryView($row);

        $view["body_text"] = $row["body_text"];
        $view["body_html"] = $row["body_html"];

        # 收件人按类型分组
        $to = [];
        $cc = [];
        $bcc = [];

        foreach (self::recipientsOf($row["id"]) as $r) {
            $item = ["address" => $r["address"], "name" => $r["name"]];

            if ($r["type"] === "cc") {
                $cc[] = $item;
            } else if ($r["type"] === "bcc") {
                $bcc[] = $item;
            } else {
                $to[] = $item;
            }
        }

        $view["to"] = $to;
        $view["cc"] = $cc;
        $view["bcc"] = $bcc;
        $view["in_reply_to"] = $row["in_reply_to"];
        $view["references"] = $row["references"];
        $view["attachments"] = AttachmentService::listOfMail($row["id"]);

        return $view;
    }
}
