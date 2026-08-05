<?php
/**
 * sender.php —— 发信队列守护进程
 *
 * 功能：轮询 send_queue 表，对「待发送」状态的任务进行实际投递。
 *   - 本域收件人：直接入库到对方收件箱
 *   - 外域收件人：构建 MIME 报文后通过 SmtpClient 投递
 *
 * 投递失败按指数退避重试，达到上限后置为最终失败。
 *
 * 启动方式：php daemon/sender.php
 */

namespace daemon;

require_once __DIR__ . "/bootstrap_cli.php";

use dotnet;

final class SenderDaemon
{
    /** @var boolean 运行标志 */
    private $running = true;

    /** @var integer 轮询间隔（秒） */
    private $interval;

    /** @var integer 单批处理任务数 */
    private $batch;

    /** @var integer 最大重试次数 */
    private $maxRetry;

    public function __construct()
    {
        $this->interval = (int) DotNetRegistry::Read("SENDER_INTERVAL", 5);
        $this->batch    = (int) DotNetRegistry::Read("SENDER_BATCH", 10);
        $this->maxRetry = (int) DotNetRegistry::Read("SENDER_MAX_RETRY", 5);

        cli_echo("Sender daemon initializing [interval={$this->interval}s, batch={$this->batch}]");
    }

    /**
     * 向自身发送 SIGTERM 以触发优雅退出
     */
    public function shutdown()
    {
        $this->running = false;
    }

    /**
     * 主循环
     */
    public function run()
    {
        cli_echo("Sender daemon started");

        while ($this->running) {
            if (!ensure_database()) {
                cli_echo("database unavailable, retry after {$this->interval}s");
                sleep($this->interval);
                continue;
            }

            try {
                $this->processBatch();
            } catch (\Exception $ex) {
                mail_log("sender", "loop fatal: " . $ex->getMessage());
                cli_echo("fatal error: " . $ex->getMessage());
            }

            if ($this->running) {
                sleep($this->interval);
            }
        }

        cli_echo("Sender daemon stopped");
    }

    /**
     * 批量取待发任务并投递
     */
    private function processBatch()
    {
        $tasks = QueueService::fetchPending($this->batch);

        if (empty($tasks)) {
            return;
        }

        # 按邮件 ID 聚合，同一封邮件的所有收件人一起处理
        $byMail = [];
        foreach ($tasks as $t) {
            $mid = (int) $t["mail_id"];
            $byMail[$mid][] = $t;
        }

        cli_echo("processing " . count($tasks) . " tasks across " . count($byMail) . " mail(s)");

        foreach ($byMail as $mailId => $mailTasks) {
            try {
                $this->processMail($mailId, $mailTasks);
            } catch (\Exception $ex) {
                mail_log("sender", "mail #{$mailId} error: " . $ex->getMessage());
                cli_echo("mail #{$mailId} error: " . $ex->getMessage());

                # 单封邮件处理出错时，所有相关任务标记失败
                foreach ($mailTasks as $t) {
                    QueueService::markFailed($t["id"], "processing error: " . $ex->getMessage(), (int) $t["attempts"] + 1);
                }
            }
        }
    }

    /**
     * 处理单封邮件的所有待投递任务
     *
     * @param integer $mailId
     * @param array $tasks
     */
    private function processMail($mailId, $tasks)
    {
        $mail = MailService::table()->where(["id" => (int) $mailId])->find();
        if ($mail === false) {
            foreach ($tasks as $t) {
                QueueService::markFailed($t["id"], "mail record not found", (int) $t["attempts"] + 1);
            }
            return;
        }

        $mailTime = $mail["mail_time"];
        if (!empty($mailTime)) {
            $mail["mail_time"] = date("r", strtotime($mailTime));
        } else {
            $mail["mail_time"] = date("r");
        }

        if (empty($mail["message_id"])) {
            $mail["message_id"] = MimeBuilder::newMessageId();
        }

        # ---- 区分本域 / 外域收件人 ----
        $localTasks = [];
        $remoteByDomain = [];

        foreach ($tasks as $t) {
            $address = $t["to_address"];
            if (MailAddress::isLocal($address)) {
                $localTasks[] = $t;
            } else {
                $domain = MailAddress::domainOf($address);
                $remoteByDomain[$domain][] = $t;
            }
        }

        # ---- 本域投递（直接入库） ----
        foreach ($localTasks as $t) {
            $this->deliverOneLocal($mailId, $t);
        }

        # ---- 外域投递（构建 MIME + SMTP） ----
        if (!empty($remoteByDomain)) {
            $this->deliverRemoteByDomain($mail, $remoteByDomain);
        }

        QueueService::syncMailStatus($mailId);
    }

    /**
     * 投递一封邮件给本域收件人
     *
     * @param integer $mailId
     * @param array $task
     */
    private function deliverOneLocal($mailId, $task)
    {
        try {
            $result = MailService::deliverLocal($mailId, $task["to_address"]);

            if ($result) {
                QueueService::markSent($task["id"]);
            } else {
                QueueService::markFailed($task["id"], "local delivery failed (recipient not found or over quota)", (int) $task["attempts"] + 1);
            }
        } catch (\Exception $ex) {
            QueueService::markFailed($task["id"], "local delivery error: " . $ex->getMessage(), (int) $task["attempts"] + 1);
        }
    }

    /**
     * 按域名分组，通过 SMTP 向外域投递邮件
     *
     * 同一域名的收件人在一次连接中合并投递，
     * 以减少连接建立与 TLS 握手开销。
     *
     * @param array $mail
     * @param array $remoteByDomain [domain => [tasks...]]
     */
    private function deliverRemoteByDomain($mail, $remoteByDomain)
    {
        # 先准备附件列表：在 MimeBuilder 中需传入 attachmentsOf 结果
        $messagePath = null;

        try {
            # 构建 MIME 报文，只构建一次，所有域名共用
            $attachments = $mail["has_attach"] ? AttachmentService::listOfMail($mail["id"]) : [];
            $messagePath = MimeBuilder::build($mail, $attachments);

            if ($messagePath === false || $messagePath === null) {
                $fallbackMsg = "failed to build MIME message";
                foreach ($remoteByDomain as $domain => $domainTasks) {
                    foreach ($domainTasks as $t) {
                        QueueService::markFailed($t["id"], $fallbackMsg, (int) $t["attempts"] + 1);
                    }
                }
                return;
            }

            foreach ($remoteByDomain as $domain => $domainTasks) {
                $addresses = array_column($domainTasks, "to_address");

                try {
                    $client = new SmtpClient();
                    $result = $client->deliverToDomain($domain, $mail["from_address"], $addresses, $messagePath);

                    if (!empty($result["ok"])) {
                        cli_echo("delivered to {$domain} via " . ($result["host"] ?? "?"));
                        foreach ($domainTasks as $t) {
                            QueueService::markSent($t["id"]);
                        }
                    } else {
                        $error = isset($result["error"]) ? $result["error"] : "unknown SMTP error";
                        cli_echo("delivery to {$domain} failed: {$error}");

                        foreach ($domainTasks as $t) {
                            $newAttempts = (int) $t["attempts"] + 1;
                            QueueService::markFailed($t["id"], $error, $newAttempts);
                        }
                    }
                } catch (\Exception $ex) {
                    $error = "SMTP delivery error for {$domain}: " . $ex->getMessage();
                    cli_echo($error);

                    foreach ($domainTasks as $t) {
                        $newAttempts = (int) $t["attempts"] + 1;
                        QueueService::markFailed($t["id"], $error, $newAttempts);
                    }
                }
            }
        } finally {
            # 清理临时 .eml 文件
            if ($messagePath !== null && file_exists($messagePath)) {
                @unlink($messagePath);
            }
        }
    }
}

# ---- 启动守护进程 ----

$daemon = new SenderDaemon();

# 注册信号处理以实现优雅退出（Unix 平台）
if (function_exists("pcntl_signal")) {
    pcntl_signal(SIGTERM, [$daemon, "shutdown"]);
    pcntl_signal(SIGINT, [$daemon, "shutdown"]);
}

$daemon->run();
