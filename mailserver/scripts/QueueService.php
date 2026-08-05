<?php
/**
 * QueueService.php —— 发信队列服务
 *
 * 发信接口只负责入队后立即返回，实际的网络投递由
 * daemon/sender.php 常驻进程异步执行。
 *
 * 这样设计的原因：SMTP 投递涉及 DNS 查询、TCP 连接、TLS 握手与
 * 多轮协议交互，耗时可能长达数十秒；若放在 HTTP 请求内同步执行，
 * 会导致请求超时、用户体验差，且无法实现失败重试。
 *
 * 一封邮件的每个收件人对应一条队列记录，便于分别跟踪与重试：
 * 发往 A 成功、发往 B 失败时，只需重试 B。
 */

class QueueService
{
    /**
     * @return Table
     */
    public static function table()
    {
        return new Table(SEND_QUEUE_TABLE);
    }

    /**
     * 入队一个投递任务
     *
     * @param integer $mailId
     * @param string $fromAddress 信封发件人
     * @param string $toAddress 信封收件人
     * @return integer 队列记录 id，失败返回 0
     */
    public static function enqueue($mailId, $fromAddress, $toAddress)
    {
        $toAddress = MailAddress::normalize($toAddress);
        $domain = MailAddress::domainOf($toAddress);

        if ($domain === false) {
            return 0;
        }

        $now = now_time();

        $newId = self::table()->add([
            "mail_id"      => (int) $mailId,
            "from_address" => MailAddress::normalize($fromAddress),
            "to_address"   => $toAddress,
            "to_domain"    => $domain,
            "status"       => "pending",
            "attempts"     => 0,
            # 立即可投递
            "next_retry"   => $now,
            "last_error"   => "",
            "create_time"  => $now,
            "update_time"  => $now
        ]);

        return $newId === false ? 0 : (int) $newId;
    }

    /**
     * 取出一批待投递的任务
     *
     * 条件：状态为 pending 且已到重试时间。
     *
     * @param integer $limit
     * @return array
     */
    public static function fetchPending($limit = 10)
    {
        $rows = self::table()
            ->where([
                "status"     => "pending",
                # next_retry <= 当前时间。时间串由 now_time() 生成，非用户输入
                "next_retry" => "~<= '" . now_time() . "'"
            ])
            ->order_by("next_retry")
            ->limit((int) $limit)
            ->select();

        return is_array($rows) ? $rows : [];
    }

    /**
     * 把任务标记为投递中
     *
     * 用于避免多个 sender 进程重复投递同一任务。
     *
     * @param integer $queueId
     * @return boolean
     */
    public static function markSending($queueId)
    {
        # 条件中带上 status = pending，
        # 这样两个进程同时抢占时只有一个能更新成功
        $affected = self::table()
            ->where(["id" => (int) $queueId, "status" => "pending"])
            ->save(["status" => "sending", "update_time" => now_time()]);

        return $affected !== false;
    }

    /**
     * 标记投递成功
     *
     * @param integer $queueId
     * @return void
     */
    public static function markSent($queueId)
    {
        self::table()->where(["id" => (int) $queueId])->save([
            "status"      => "sent",
            "last_error"  => "",
            "update_time" => now_time()
        ]);
    }

    /**
     * 标记本次投递失败，按指数退避安排下次重试
     *
     * 退避间隔 = SENDER_RETRY_BASE * 2^(已重试次数)，
     * 例如基数 60 秒时依次为 60s、120s、240s、480s、960s。
     * 这样既能应对对端临时故障，又不会对故障服务器造成持续冲击。
     *
     * @param integer $queueId
     * @param string $error 错误描述
     * @param integer $attempts 当前已重试次数
     * @return void
     */
    public static function markFailed($queueId, $error, $attempts)
    {
        $maxRetry = (int) DotNetRegistry::Read("SENDER_MAX_RETRY", 5);
        $base = (int) DotNetRegistry::Read("SENDER_RETRY_BASE", 60);

        $attempts = (int) $attempts + 1;

        # 错误描述截断至字段长度以内，避免写入失败
        $error = mb_substr(str_replace(["\r", "\n"], " ", (string) $error), 0, 500);

        if ($attempts >= $maxRetry) {
            # 达到重试上限：置为最终失败，不再重试
            self::table()->where(["id" => (int) $queueId])->save([
                "status"      => "failed",
                "attempts"    => $attempts,
                "last_error"  => $error,
                "update_time" => now_time()
            ]);

            # 同步更新邮件的投递状态
            $row = self::table()->where(["id" => (int) $queueId])->find();

            if ($row !== false) {
                self::syncMailStatus((int) $row["mail_id"]);
            }

            return;
        }

        # 指数退避：间隔随重试次数翻倍
        $delay = $base * pow(2, $attempts - 1);
        # 上限 6 小时，避免间隔无限增长
        $delay = min($delay, 21600);

        self::table()->where(["id" => (int) $queueId])->save([
            "status"      => "pending",
            "attempts"    => $attempts,
            "last_error"  => $error,
            "next_retry"  => now_time(time() + (int) $delay),
            "update_time" => now_time()
        ]);
    }

    /**
     * 依据队列中各收件人的投递结果，同步更新邮件的整体状态
     *
     * 规则：
     *   还有待投递或投递中的任务 → queued
     *   全部成功                 → sent
     *   全部失败                 → failed
     *   部分成功部分失败         → partial
     *
     * @param integer $mailId
     * @return void
     */
    public static function syncMailStatus($mailId)
    {
        $mailId = (int) $mailId;

        if ($mailId <= 0) {
            return;
        }

        $table = self::table();

        # in() 是框架提供的表达式助手，生成 `status` IN ('pending', 'sending')
        $pending = (int) $table->where([
            "mail_id" => $mailId,
            "status"  => in(["pending", "sending"])
        ])->count();

        if ($pending > 0) {
            return;
        }

        $sent = (int) $table->where(["mail_id" => $mailId, "status" => "sent"])->count();
        $failed = (int) $table->where(["mail_id" => $mailId, "status" => "failed"])->count();

        if ($failed === 0 && $sent > 0) {
            $status = "sent";
        } else if ($sent === 0 && $failed > 0) {
            $status = "failed";
        } else if ($sent > 0 && $failed > 0) {
            $status = "partial";
        } else {
            return;
        }

        MailService::table()->where(["id" => $mailId])->save(["send_status" => $status]);
    }

    /**
     * 查询某封邮件的投递明细
     *
     * @param integer $mailId
     * @return array
     */
    public static function statusOfMail($mailId)
    {
        $rows = self::table()->where(["mail_id" => (int) $mailId])->select();

        if (!is_array($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $r) {
            $result[] = [
                "to_address" => $r["to_address"],
                "status"     => $r["status"],
                "attempts"   => (int) $r["attempts"],
                "last_error" => $r["last_error"],
                "next_retry" => $r["next_retry"],
                "update_time" => $r["update_time"]
            ];
        }

        return $result;
    }

    /**
     * 重新投递失败的任务
     *
     * 把 failed 状态的任务重置为 pending 并清零重试计数。
     *
     * @param integer $mailId
     * @return integer 重置的任务数
     */
    public static function retryFailed($mailId)
    {
        $mailId = (int) $mailId;

        $count = (int) self::table()
            ->where(["mail_id" => $mailId, "status" => "failed"])
            ->count();

        if ($count === 0) {
            return 0;
        }

        # 批量更新必须显式传入 $limit1 = false，否则只会重置一条任务
        self::table()
            ->where(["mail_id" => $mailId, "status" => "failed"])
            ->save([
                "status"      => "pending",
                "attempts"    => 0,
                "next_retry"  => now_time(),
                "last_error"  => "",
                "update_time" => now_time()
            ], false);

        MailService::table()->where(["id" => $mailId])->save(["send_status" => "queued"]);

        return $count;
    }
}
