<?php
/**
 * cleanup.php —— 定时清理任务
 *
 * 本脚本设计为由操作系统的计划任务（cron / 任务计划程序）定期触发，
 * 执行一次后退出。负责清理：
 *   1. 过期 Token（超过有效期未被吊销的）
 *   2. 过期上传会话及其临时分片文件
 *   3. 回收站中超过保留天数的邮件（彻底删除）
 *   4. 孤儿附件文件（元数据已不存在但磁盘文件未清理的）
 *
 * 建议执行频率：每小时一次
 * 启动方式：php daemon/cleanup.php
 */

namespace daemon;

require_once __DIR__ . "/bootstrap_cli.php";

use dotnet;
use \Table;

final class CleanupTask
{
    /** @var string 附件存储根目录 */
    private $storageRoot;

    /** @var integer 回收站保留天数 */
    private $trashRetainDays;

    /** @var integer Token 有效期（秒） */
    private $tokenTtl;

    /** @var integer 上传会话有效期（秒） */
    private $uploadSessionTtl;

    /** @var array 执行统计 */
    private $stats = [
        "tokens_cleaned"   => 0,
        "sessions_cleaned" => 0,
        "chunks_removed"   => 0,
        "mails_purged"     => 0,
        "orphans_removed"  => 0
    ];

    public function __construct()
    {
        $this->storageRoot      = DotNetRegistry::Read("STORAGE_ROOT", APP_PATH . "/storage");
        $this->trashRetainDays  = (int) DotNetRegistry::Read("TRASH_RETAIN_DAYS", 30);
        $this->tokenTtl         = (int) DotNetRegistry::Read("TOKEN_TTL", 604800);
        $this->uploadSessionTtl = (int) DotNetRegistry::Read("UPLOAD_SESSION_TTL", 86400);
    }

    /**
     * 执行全部清理任务
     */
    public function run()
    {
        cli_echo("Cleanup task started");

        if (!ensure_database()) {
            cli_echo("database unavailable, aborting");
            return;
        }

        try {
            $this->cleanTokens();
            $this->cleanUploadSessions();
            $this->purgeTrashMails();
            $this->cleanOrphanAttachmentFiles();

            $this->reportStats();
        } catch (\Exception $ex) {
            mail_log("cleanup", "error: " . $ex->getMessage());
            cli_echo("cleanup error: " . $ex->getMessage());
        }

        cli_echo("Cleanup task finished");
    }

    // =================================================================
    // 一、清理过期 Token
    // =================================================================

    private function cleanTokens()
    {
        $now = now_time();
        $table = new Table(TOKENS_TABLE);

        $expired = $table
            ->where(new \ValueEnumerable([
                "expire_time" => ["<=" => $now]
            ]))
            ->select();

        if (!empty($expired)) {
            $this->stats["tokens_cleaned"] = count($expired);

            # 逐个删除，以确保关联数据完整清理
            foreach ($expired as $token) {
                $table->where(["id" => (int) $token["id"]])->delete();
            }
        }

        cli_echo("tokens cleaned: " . $this->stats["tokens_cleaned"]);
    }

    // =================================================================
    // 二、清理过期上传会话
    // =================================================================

    private function cleanUploadSessions()
    {
        $now = now_time();
        $table = new Table(UPLOAD_SESSIONS_TABLE);

        $expired = $table
            ->where(new \ValueEnumerable([
                "status"    => "uploading",
                "expire_time" => ["<=" => $now]
            ]))
            ->select();

        foreach ($expired as $session) {
            $sessionId = (int) $session["id"];

            # 删除分片记录与临时分片文件
            $this->cleanSessionChunks($session);
            $this->removeSessionTempDir($session);

            # 删除会话记录
            $table->where(["id" => $sessionId])->delete();

            $this->stats["sessions_cleaned"]++;
        }

        cli_echo("upload sessions cleaned: " . $this->stats["sessions_cleaned"]);
    }

    /**
     * 删除指定会话的分片临时文件与数据库记录
     *
     * @param array $session
     */
    private function cleanSessionChunks($session)
    {
        $chunksTable = new Table(UPLOAD_CHUNKS_TABLE);
        $chunks = $chunksTable->where(["upload_id" => $session["upload_id"]])->select();

        if (empty($chunks)) {
            return;
        }

        # 分片数据库记录与临时目录均在 removeSessionTempDir 中处理
        # 此处仅统计数量，不逐文件删除（更高效的方式是直接删除整个临时目录）
        $this->stats["chunks_removed"] += count($chunks);

        # 删除所有分片记录
        $chunksTable->where(["upload_id" => $session["upload_id"]])->delete();
    }

    /**
     * 删除上传会话的临时目录
     *
     * @param array $session
     */
    private function removeSessionTempDir($session)
    {
        $tmpDir = $this->storageRoot . "/tmp/" . $session["upload_id"];

        if (is_dir($tmpDir)) {
            $this->rmdirRecursive($tmpDir);
        }
    }

    // =================================================================
    // 三、清理回收站超期邮件
    // =================================================================

    private function purgeTrashMails()
    {
        $table = new Table(MAILS_TABLE);

        $cutoff = date("Y-m-d H:i:s", time() - ($this->trashRetainDays * 86400));

        # 先找到所有用户的回收站文件夹
        $folderTable = new Table(FOLDERS_TABLE);
        $trashFolders = $folderTable
            ->where(["type" => "trash"])
            ->select();

        foreach ($trashFolders as $folder) {
            $mails = $table
                ->where(new \ValueEnumerable([
                    "folder_id" => (int) $folder["id"],
                    "mail_time" => ["<=" => $cutoff]
                ]))
                ->select();

            foreach ($mails as $mail) {
                # 从 recipients 表删除关联记录
                $recTable = new Table(RECIPIENTS_TABLE);
                $recTable->where(["mail_id" => (int) $mail["id"]])->delete();

                # 将关联附件标记为孤儿（不删除磁盘文件，交给 orphan 清理）
                # 附件表使用 mail_id 列直连，无独立关联表
                $attachTable = new Table(ATTACHMENTS_TABLE);
                $attachTable->where(["mail_id" => (int) $mail["id"]])->save(["mail_id" => 0], false);

                # 删除邮件本身
                $table->where(["id" => (int) $mail["id"]])->delete();

                $this->stats["mails_purged"]++;
            }
        }

        cli_echo("trash mails purged: " . $this->stats["mails_purged"]);
    }

    // =================================================================
    // 四、清理孤儿附件文件
    // =================================================================

    private function cleanOrphanAttachmentFiles()
    {
        # 附件表直接使用 mail_id 列引用邮件，无独立关联表。
        # 孤儿附件定义为 mail_id = 0 且无其他邮件共享同一文件路径的记录。
        $attachTable = new Table(ATTACHMENTS_TABLE);

        # 找到 mail_id = 0 的候选孤儿附件
        $candidates = $attachTable
            ->where(["mail_id" => 0])
            ->select();

        if (empty($candidates)) {
            cli_echo("orphan attachments removed: 0");
            return;
        }

        foreach ($candidates as $att) {
            $attId = (int) $att["id"];
            $filePath = $att["file_path"];

            # 检查是否还有其他记录引用同一文件（秒传共享场景）
            $sameFile = $attachTable
                ->where(new \ValueEnumerable([
                    "file_path" => $filePath,
                    "id"        => ["!=" => $attId]
                ]))
                ->count();

            if ((int) $sameFile === 0) {
                # 无其他引用，安全删除磁盘文件
                if (!empty($filePath) && file_exists($filePath)) {
                    @unlink($filePath);
                }

                $attachTable->where(["id" => $attId])->delete();
                $this->stats["orphans_removed"]++;
            } else {
                # 有其他记录引用同一文件，仅删此条记录
                $attachTable->where(["id" => $attId])->delete();
            }
        }

        cli_echo("orphan attachments removed: " . $this->stats["orphans_removed"]);
    }

    // =================================================================
    // 辅助方法
    // =================================================================

    /**
     * 递归删除目录
     *
     * @param string $dir
     */
    private function rmdirRecursive($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $handle = opendir($dir);
        if ($handle === false) {
            return;
        }

        while (($item = readdir($handle)) !== false) {
            if ($item === "." || $item === "..") {
                continue;
            }

            $path = $dir . "/" . $item;

            if (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                @unlink($path);
            }
        }

        closedir($handle);
        @rmdir($dir);
    }

    /**
     * 输出统计汇总
     */
    private function reportStats()
    {
        $total = array_sum($this->stats);
        $msg = "cleanup summary: "
            . "tokens={$this->stats["tokens_cleaned"]}, "
            . "sessions={$this->stats["sessions_cleaned"]}, "
            . "chunks={$this->stats["chunks_removed"]}, "
            . "mails={$this->stats["mails_purged"]}, "
            . "orphans={$this->stats["orphans_removed"]}";

        cli_echo($msg);

        if ($total > 0) {
            mail_log("cleanup", $msg);
        }
    }
}

# ---- 启动清理任务 ----
$task = new CleanupTask();
$task->run();
