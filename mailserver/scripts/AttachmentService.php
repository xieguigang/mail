<?php
/**
 * AttachmentService.php —— 附件存储服务
 *
 * 大附件采用「初始化 → 分片上传 → 合并」三段式协议：
 *   1) init     建立上传会话，若校验值命中已有附件则直接秒传
 *   2) chunk    逐片上传，分片幂等，支持乱序与重传
 *   3) progress 查询已完成分片，客户端据此断点续传
 *   4) complete 全部分片齐备后按序流式拼接落盘
 *
 * 为何不用单次 multipart 上传：
 *   单次上传受 upload_max_filesize / post_max_size / max_execution_time
 *   三重限制，且中断后必须整体重传。分片方案把风险切分到片级，
 *   天然支持续传，是大文件场景的成熟实践。
 *
 * 存储布局（STORAGE_ROOT 下）：
 *   attachments/<hh>/<hh>/<uuid>.bin   最终附件，两级哈希目录分散
 *   tmp/<upload_id>/<index>.part       分片临时文件
 *   raw/<yyyymm>/<uuid>.eml            SMTP 收到的原始报文
 */

class AttachmentService
{
    /**
     * 取得存储根目录，并确保其存在
     *
     * @return string
     */
    public static function storageRoot()
    {
        $root = DotNetRegistry::Read("STORAGE_ROOT", APP_PATH . "/storage");
        ensure_dir($root);
        return rtrim($root, "/\\");
    }

    /**
     * 附件存储目录
     *
     * @return string
     */
    public static function attachmentRoot()
    {
        $dir = self::storageRoot() . "/attachments";
        ensure_dir($dir);
        return $dir;
    }

    /**
     * 分片临时目录
     *
     * @return string
     */
    public static function tmpRoot()
    {
        $dir = self::storageRoot() . "/tmp";
        ensure_dir($dir);
        return $dir;
    }

    /**
     * 原始报文归档目录
     *
     * @return string
     */
    public static function rawRoot()
    {
        $dir = self::storageRoot() . "/raw";
        ensure_dir($dir);
        return $dir;
    }

    /**
     * 生成附件的相对存储路径
     *
     * 使用两级哈希子目录分散文件，避免单目录下文件数过多
     * 导致文件系统性能显著衰减（ext4/NTFS 在单目录数万文件后目录项查找变慢）。
     *
     * @return string 形如 "a3/f7/<uuid>.bin"
     */
    public static function newStorePath()
    {
        $uuid = uuid_v4();
        $hash = md5($uuid);
        $l1 = substr($hash, 0, 2);
        $l2 = substr($hash, 2, 2);

        ensure_dir(self::attachmentRoot() . "/" . $l1 . "/" . $l2);

        return $l1 . "/" . $l2 . "/" . $uuid . ".bin";
    }

    /**
     * 相对路径转绝对路径
     *
     * @param string $relative
     * @return string
     */
    public static function absPath($relative)
    {
        return self::attachmentRoot() . "/" . ltrim($relative, "/\\");
    }

    /**
     * @return Table
     */
    public static function table()
    {
        return new Table(ATTACHMENTS_TABLE);
    }

    /**
     * @return Table
     */
    public static function sessions()
    {
        return new Table(UPLOAD_SESSIONS_TABLE);
    }

    /**
     * @return Table
     */
    public static function chunks()
    {
        return new Table(UPLOAD_CHUNKS_TABLE);
    }

    /**
     * 清洗用户提交的文件名
     *
     * 剥离路径分隔符与控制字符，杜绝目录穿越；
     * 服务端另行生成存储名，原始文件名仅作展示用途。
     *
     * @param string $name
     * @return string
     */
    public static function sanitizeFilename($name)
    {
        $name = (string) $name;
        # 只取路径的最后一段，消除 ../ 与绝对路径
        $name = str_replace("\\", "/", $name);
        $name = basename($name);
        # 移除控制字符与文件系统保留字符
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', "", $name);
        $name = trim($name);

        if ($name === "" || $name === "." || $name === "..") {
            $name = "unnamed";
        }

        if (mb_strlen($name) > 200) {
            # 截断过长文件名但保留扩展名
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $name = mb_substr($base, 0, 180) . ($ext !== "" ? "." . $ext : "");
        }

        return $name;
    }

    /**
     * 依据文件名推断 MIME 类型
     *
     * @param string $filename
     * @return string
     */
    public static function guessMime($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $map = [
            "txt" => "text/plain", "html" => "text/html", "htm" => "text/html",
            "css" => "text/css", "csv" => "text/csv", "xml" => "application/xml",
            "json" => "application/json", "js" => "application/javascript",
            "pdf" => "application/pdf",
            "doc" => "application/msword",
            "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            "xls" => "application/vnd.ms-excel",
            "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            "ppt" => "application/vnd.ms-powerpoint",
            "pptx" => "application/vnd.openxmlformats-officedocument.presentationml.presentation",
            "zip" => "application/zip", "rar" => "application/x-rar-compressed",
            "7z" => "application/x-7z-compressed", "gz" => "application/gzip",
            "tar" => "application/x-tar",
            "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png",
            "gif" => "image/gif", "bmp" => "image/bmp", "webp" => "image/webp",
            "svg" => "image/svg+xml", "ico" => "image/x-icon",
            "mp3" => "audio/mpeg", "wav" => "audio/wav", "ogg" => "audio/ogg",
            "mp4" => "video/mp4", "avi" => "video/x-msvideo", "mkv" => "video/x-matroska",
            "mov" => "video/quicktime", "webm" => "video/webm",
            "eml" => "message/rfc822"
        ];

        return isset($map[$ext]) ? $map[$ext] : "application/octet-stream";
    }

    // =================================================================
    // 分片上传
    // =================================================================

    /**
     * 初始化上传会话
     *
     * 若客户端提供了整文件校验值且命中已有附件，则直接秒传：
     * 复用已有的磁盘文件，跳过全部数据传输。
     *
     * @param integer $userId
     * @param string $filename
     * @param integer $totalSize
     * @param string $checksum 整文件 sha256（可空，为空则不支持秒传）
     * @param integer $chunkSize 客户端期望的分片大小（可空）
     * @return array{ok:bool, error?:string, data?:array}
     */
    public static function initUpload($userId, $filename, $totalSize, $checksum = "", $chunkSize = 0)
    {
        $userId = (int) $userId;
        $totalSize = (int) $totalSize;
        $filename = self::sanitizeFilename($filename);

        if ($totalSize <= 0) {
            return ["ok" => false, "error" => "total_size must be greater than zero"];
        }

        $maxSize = (int) DotNetRegistry::Read("ATTACHMENT_MAX_SIZE", 2147483648);

        if ($totalSize > $maxSize) {
            return [
                "ok" => false,
                "error" => "file exceeds the maximum allowed size of " . $maxSize . " bytes"
            ];
        }

        # 配额检查
        if (!UserService::hasQuota($userId, $totalSize)) {
            return ["ok" => false, "error" => "storage quota exceeded"];
        }

        $checksum = strtolower(trim((string) $checksum));

        # ---- 秒传判定 ----
        if (preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            $existing = self::table()
                ->where(["checksum" => $checksum, "size" => $totalSize])
                ->find();

            if ($existing !== false && is_file(self::absPath($existing["store_path"]))) {
                # 命中已有文件：新建一条元数据记录指向同一份磁盘文件，
                # 无需重复占用存储空间
                $newId = self::table()->add([
                    "mail_id"     => 0,
                    "user_id"     => $userId,
                    "filename"    => $filename,
                    "mime_type"   => self::guessMime($filename),
                    "size"        => $totalSize,
                    "store_path"  => $existing["store_path"],
                    "checksum"    => $checksum,
                    "content_id"  => "",
                    "is_inline"   => 0,
                    "create_time" => now_time()
                ]);

                if ($newId !== false) {
                    UserService::addUsedSize($userId, $totalSize);

                    return [
                        "ok" => true,
                        "data" => [
                            "instant"       => true,
                            "attachment_id" => (int) $newId,
                            "filename"      => $filename,
                            "size"          => $totalSize,
                            "checksum"      => $checksum
                        ]
                    ];
                }
            }
        }

        # ---- 建立新的上传会话 ----
        $defaultChunk = (int) DotNetRegistry::Read("CHUNK_SIZE", 4194304);
        $chunkSize = (int) $chunkSize;

        # 分片大小约束在 [256KB, 32MB]，过小会产生过多请求，过大则失去分片意义
        if ($chunkSize < 262144 || $chunkSize > 33554432) {
            $chunkSize = $defaultChunk;
        }

        $totalChunks = (int) ceil($totalSize / $chunkSize);
        $uploadId = uuid_v4();
        $tempDir = self::tmpRoot() . "/" . $uploadId;

        if (!ensure_dir($tempDir)) {
            return ["ok" => false, "error" => "failed to create temporary directory"];
        }

        $ttl = (int) DotNetRegistry::Read("UPLOAD_SESSION_TTL", 86400);

        $newId = self::sessions()->add([
            "upload_id"     => $uploadId,
            "user_id"       => $userId,
            "filename"      => $filename,
            "mime_type"     => self::guessMime($filename),
            "total_size"    => $totalSize,
            "chunk_size"    => $chunkSize,
            "total_chunks"  => $totalChunks,
            "uploaded"      => 0,
            "checksum"      => $checksum,
            "temp_dir"      => $uploadId,
            "status"        => "uploading",
            "attachment_id" => 0,
            "create_time"   => now_time(),
            "expire_time"   => now_time(time() + $ttl)
        ]);

        if ($newId === false) {
            return ["ok" => false, "error" => "failed to create upload session"];
        }

        return [
            "ok" => true,
            "data" => [
                "instant"      => false,
                "upload_id"    => $uploadId,
                "chunk_size"   => $chunkSize,
                "total_chunks" => $totalChunks,
                "uploaded"     => []
            ]
        ];
    }

    /**
     * 查找并校验上传会话归属
     *
     * @param string $uploadId
     * @param integer $userId
     * @return array|null
     */
    public static function findSession($uploadId, $userId)
    {
        if (!preg_match('/^[a-f0-9\-]{36}$/i', (string) $uploadId)) {
            return null;
        }

        $row = self::sessions()
            ->where(["upload_id" => $uploadId, "user_id" => (int) $userId])
            ->find();

        return $row === false ? null : $row;
    }

    /**
     * 接收一个分片
     *
     * 分片写入采用「先写临时文件再原子重命名」，
     * 避免并发或中断产生半截文件被误判为完整分片。
     *
     * @param string $uploadId
     * @param integer $userId
     * @param integer $index 分片序号，从 0 开始
     * @param string $sourceFile 分片数据的来源文件路径（$_FILES 的 tmp_name）
     * @param boolean $isUploadedFile 来源是否为 PHP 上传文件
     * @return array{ok:bool, error?:string, data?:array}
     */
    public static function saveChunk($uploadId, $userId, $index, $sourceFile, $isUploadedFile = true)
    {
        $session = self::findSession($uploadId, $userId);

        if ($session === null) {
            return ["ok" => false, "error" => "upload session not found"];
        }

        if ($session["status"] !== "uploading") {
            return ["ok" => false, "error" => "upload session is already " . $session["status"]];
        }

        if (strtotime($session["expire_time"]) <= time()) {
            return ["ok" => false, "error" => "upload session has expired"];
        }

        $index = (int) $index;
        $totalChunks = (int) $session["total_chunks"];

        if ($index < 0 || $index >= $totalChunks) {
            return ["ok" => false, "error" => "chunk index out of range"];
        }

        if (!is_file($sourceFile)) {
            return ["ok" => false, "error" => "no chunk data received"];
        }

        $size = filesize($sourceFile);
        $chunkSize = (int) $session["chunk_size"];
        $totalSize = (int) $session["total_size"];

        # 校验分片大小：除最后一片外都应等于约定的分片大小。
        # 这可以及早发现客户端切片逻辑错误，避免合并后才发现文件损坏
        $expected = ($index === $totalChunks - 1)
            ? $totalSize - $chunkSize * ($totalChunks - 1)
            : $chunkSize;

        if ($size !== $expected) {
            return [
                "ok" => false,
                "error" => "chunk size mismatch, expected " . $expected . " but got " . $size
            ];
        }

        $tempDir = self::tmpRoot() . "/" . $session["temp_dir"];

        if (!ensure_dir($tempDir)) {
            return ["ok" => false, "error" => "temporary directory is unavailable"];
        }

        $target = $tempDir . "/" . $index . ".part";
        # 先写入带随机后缀的临时名，成功后再原子重命名为正式分片名
        $staging = $target . "." . bin2hex(random_bytes(4)) . ".tmp";

        if ($isUploadedFile) {
            # move_uploaded_file 会校验来源确实是本次请求上传的文件，防止路径伪造
            $moved = move_uploaded_file($sourceFile, $staging);
        } else {
            $moved = @rename($sourceFile, $staging);

            if (!$moved) {
                $moved = @copy($sourceFile, $staging);
            }
        }

        if (!$moved) {
            return ["ok" => false, "error" => "failed to store chunk data"];
        }

        $checksum = hash_file("sha256", $staging);

        # 原子替换：即使同一分片被并发重传，也不会出现半截文件
        if (!@rename($staging, $target)) {
            @unlink($staging);
            return ["ok" => false, "error" => "failed to commit chunk"];
        }

        # 登记分片记录。分片幂等：同序号重复上传时更新而非新增
        $chunkTable = self::chunks();
        $exists = $chunkTable
            ->where(["upload_id" => $session["upload_id"], "chunk_index" => $index])
            ->find();

        if ($exists === false) {
            $chunkTable->add([
                "upload_id"   => $session["upload_id"],
                "chunk_index" => $index,
                "chunk_size"  => $size,
                "checksum"    => $checksum,
                "create_time" => now_time()
            ]);
        } else {
            $chunkTable
                ->where(["upload_id" => $session["upload_id"], "chunk_index" => $index])
                ->save(["chunk_size" => $size, "checksum" => $checksum, "create_time" => now_time()]);
        }

        # 依据分片记录表重新统计已完成数，而非简单自增，
        # 这样重复上传同一分片不会导致计数虚高
        $uploaded = (int) $chunkTable->where(["upload_id" => $session["upload_id"]])->count();

        self::sessions()
            ->where(["upload_id" => $session["upload_id"]])
            ->save(["uploaded" => $uploaded]);

        return [
            "ok" => true,
            "data" => [
                "index"     => $index,
                "received"  => $size,
                "uploaded"  => $uploaded,
                "total"     => $totalChunks,
                "completed" => $uploaded >= $totalChunks
            ]
        ];
    }

    /**
     * 查询上传进度
     *
     * 返回已完成分片序号列表，客户端据此从断点继续上传。
     *
     * @param string $uploadId
     * @param integer $userId
     * @return array{ok:bool, error?:string, data?:array}
     */
    public static function progress($uploadId, $userId)
    {
        $session = self::findSession($uploadId, $userId);

        if ($session === null) {
            return ["ok" => false, "error" => "upload session not found"];
        }

        $rows = self::chunks()
            ->where(["upload_id" => $session["upload_id"]])
            ->order_by("chunk_index")
            ->select();

        $indexes = [];

        if (is_array($rows)) {
            foreach ($rows as $r) {
                $indexes[] = (int) $r["chunk_index"];
            }
        }

        $total = (int) $session["total_chunks"];

        return [
            "ok" => true,
            "data" => [
                "upload_id"    => $session["upload_id"],
                "filename"     => $session["filename"],
                "total_size"   => (int) $session["total_size"],
                "chunk_size"   => (int) $session["chunk_size"],
                "total_chunks" => $total,
                "uploaded"     => $indexes,
                "percent"      => $total > 0 ? round(count($indexes) * 100 / $total, 2) : 0,
                "status"       => $session["status"]
            ]
        ];
    }

    /**
     * 合并分片，完成上传
     *
     * 按序流式拼接分片到最终存储路径，全程使用固定大小缓冲，
     * 内存占用与文件体积无关。
     *
     * @param string $uploadId
     * @param integer $userId
     * @return array{ok:bool, error?:string, data?:array}
     */
    public static function complete($uploadId, $userId)
    {
        $session = self::findSession($uploadId, $userId);

        if ($session === null) {
            return ["ok" => false, "error" => "upload session not found"];
        }

        # 已完成的会话直接返回原结果，保证接口幂等
        if ($session["status"] === "completed" && (int) $session["attachment_id"] > 0) {
            $att = self::table()->where(["id" => (int) $session["attachment_id"]])->find();

            if ($att !== false) {
                return ["ok" => true, "data" => self::publicView($att)];
            }
        }

        if ($session["status"] !== "uploading") {
            return ["ok" => false, "error" => "upload session is " . $session["status"]];
        }

        $totalChunks = (int) $session["total_chunks"];
        $tempDir = self::tmpRoot() . "/" . $session["temp_dir"];

        # ---- 校验分片齐备 ----
        $missing = [];

        for ($i = 0; $i < $totalChunks; $i++) {
            if (!is_file($tempDir . "/" . $i . ".part")) {
                $missing[] = $i;
            }
        }

        if (!empty($missing)) {
            return [
                "ok" => false,
                "error" => "missing chunks: " . implode(",", array_slice($missing, 0, 20))
                    . (count($missing) > 20 ? " ..." : "")
            ];
        }

        # ---- 加锁防止并发重复合并 ----
        $lockFile = $tempDir . "/.merge.lock";
        $lock = @fopen($lockFile, "c");

        if ($lock === false) {
            return ["ok" => false, "error" => "failed to acquire merge lock"];
        }

        # LOCK_NB 非阻塞：拿不到锁说明已有请求在合并，直接返回提示
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ["ok" => false, "error" => "merge already in progress"];
        }

        $storePath = self::newStorePath();
        $absPath = self::absPath($storePath);

        $out = @fopen($absPath, "wb");

        if ($out === false) {
            flock($lock, LOCK_UN);
            fclose($lock);
            return ["ok" => false, "error" => "failed to open target file for writing"];
        }

        $written = 0;
        $failed = false;

        for ($i = 0; $i < $totalChunks; $i++) {
            $in = @fopen($tempDir . "/" . $i . ".part", "rb");

            if ($in === false) {
                $failed = true;
                break;
            }

            # 固定 1MB 缓冲逐块搬运，内存占用恒定
            while (!feof($in)) {
                $buf = fread($in, 1048576);

                if ($buf === false) {
                    $failed = true;
                    break;
                }

                $len = strlen($buf);

                if ($len === 0) {
                    break;
                }

                if (fwrite($out, $buf) !== $len) {
                    $failed = true;
                    break;
                }

                $written += $len;
            }

            fclose($in);

            if ($failed) {
                break;
            }
        }

        fclose($out);
        flock($lock, LOCK_UN);
        fclose($lock);

        if ($failed) {
            @unlink($absPath);
            return ["ok" => false, "error" => "failed to merge chunks"];
        }

        $totalSize = (int) $session["total_size"];

        # ---- 校验合并结果 ----
        if ($written !== $totalSize) {
            @unlink($absPath);
            return [
                "ok" => false,
                "error" => "merged size mismatch, expected " . $totalSize . " but got " . $written
            ];
        }

        $actual = hash_file("sha256", $absPath);
        $expected = strtolower(trim((string) $session["checksum"]));

        # 客户端提供了校验值时做完整性验证，不匹配则判定传输损坏
        if ($expected !== "" && preg_match('/^[a-f0-9]{64}$/', $expected) && $actual !== $expected) {
            @unlink($absPath);
            return ["ok" => false, "error" => "checksum mismatch, file may be corrupted"];
        }

        # ---- 落库元数据 ----
        $attId = self::table()->add([
            "mail_id"     => 0,
            "user_id"     => (int) $userId,
            "filename"    => $session["filename"],
            "mime_type"   => $session["mime_type"],
            "size"        => $totalSize,
            "store_path"  => $storePath,
            "checksum"    => $actual,
            "content_id"  => "",
            "is_inline"   => 0,
            "create_time" => now_time()
        ]);

        if ($attId === false) {
            @unlink($absPath);
            return ["ok" => false, "error" => "failed to save attachment metadata"];
        }

        self::sessions()
            ->where(["upload_id" => $session["upload_id"]])
            ->save(["status" => "completed", "attachment_id" => (int) $attId]);

        # 清理临时分片
        self::cleanupTempDir($tempDir);
        self::chunks()->where(["upload_id" => $session["upload_id"]])->delete();

        UserService::addUsedSize($userId, $totalSize);

        $att = self::table()->where(["id" => (int) $attId])->find();

        return ["ok" => true, "data" => self::publicView($att)];
    }

    /**
     * 递归删除临时目录
     *
     * @param string $dir
     * @return void
     */
    public static function cleanupTempDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }

            $path = $dir . "/" . $item;

            if (is_dir($path)) {
                self::cleanupTempDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    // =================================================================
    // 附件读取与下载
    // =================================================================

    /**
     * 取得附件并校验访问权限
     *
     * 允许访问的条件：附件属于该用户，或附件所属邮件属于该用户。
     * 这可以防止用户通过遍历 id 下载他人附件。
     *
     * @param integer $attachmentId
     * @param integer $userId
     * @return array|null
     */
    public static function findAccessible($attachmentId, $userId)
    {
        $att = self::table()->where(["id" => (int) $attachmentId])->find();

        if ($att === false) {
            return null;
        }

        # 情形一：本人上传的游离附件
        if ((int) $att["user_id"] === (int) $userId) {
            return $att;
        }

        # 情形二：附件所属邮件归本人所有
        if ((int) $att["mail_id"] > 0) {
            $mail = (new Table(MAILS_TABLE))
                ->where(["id" => (int) $att["mail_id"], "user_id" => (int) $userId])
                ->find();

            if ($mail !== false) {
                return $att;
            }
        }

        return null;
    }

    /**
     * 流式输出附件内容，支持 Range 断点续传
     *
     * 不使用框架的 Utils::PushDownload()，因为它内部整体推送、
     * 不解析请求侧的 Range 头，无法满足大文件断点续传的要求。
     *
     * 本实现解析 Range 后以 206 返回指定区间，
     * 按固定缓冲块循环读写，内存占用恒定。
     *
     * @param array $attachment 附件记录
     * @param boolean $inline true 则以 inline 方式展示（用于内嵌图片）
     * @return void 直接输出并终止脚本
     */
    public static function streamDownload($attachment, $inline = false)
    {
        # 允许调用方直接指定绝对路径（原始报文下载等场景复用本方法）
        if (!empty($attachment["__abs_path"])) {
            $path = $attachment["__abs_path"];
        } else {
            $path = self::absPath($attachment["store_path"]);
        }

        if (!is_file($path)) {
            controller::error("attachment file is missing on server", 404);
        }

        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        $isPartial = false;

        # ---- 解析 Range 请求头 ----
        $rangeHeader = isset($_SERVER["HTTP_RANGE"]) ? $_SERVER["HTTP_RANGE"] : "";

        if ($rangeHeader !== "" && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $m)) {
            $rangeStart = $m[1];
            $rangeEnd = $m[2];

            if ($rangeStart === "" && $rangeEnd !== "") {
                # 形如 bytes=-500，表示最后 500 字节
                $start = max(0, $size - (int) $rangeEnd);
                $end = $size - 1;
            } else {
                $start = (int) $rangeStart;
                $end = ($rangeEnd === "") ? $size - 1 : (int) $rangeEnd;
            }

            # 区间非法时按规范返回 416
            if ($start > $end || $start >= $size) {
                header("HTTP/1.1 416 Requested Range Not Satisfiable");
                header("Content-Range: bytes */" . $size);
                exit(0);
            }

            if ($end >= $size) {
                $end = $size - 1;
            }

            $isPartial = true;
        }

        $length = $end - $start + 1;

        # ---- 清空并关闭输出缓冲 ----
        # 大文件必须边读边发，若仍有缓冲层会把内容堆积在内存中
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        # 关闭 zlib 压缩：附件多为已压缩格式，再压缩收益极低，
        # 且会破坏 Content-Length 与 Range 的字节对应关系
        if (ini_get("zlib.output_compression")) {
            ini_set("zlib.output_compression", "Off");
        }

        # 大文件传输可能持续很久，取消脚本执行时间限制
        set_time_limit(0);

        # ---- 发送响应头 ----
        if ($isPartial) {
            header("HTTP/1.1 206 Partial Content");
            header("Content-Range: bytes " . $start . "-" . $end . "/" . $size);
        } else {
            header("HTTP/1.1 200 OK");
        }

        $filename = $attachment["filename"];
        $disposition = $inline ? "inline" : "attachment";

        header("Content-Type: " . $attachment["mime_type"]);
        header("Content-Length: " . $length);
        # 声明支持范围请求，客户端据此启用断点续传
        header("Accept-Ranges: bytes");
        # 同时提供 filename 与 filename*，兼容不支持 RFC 5987 的老客户端
        header(
            "Content-Disposition: " . $disposition
            . "; filename=\"" . str_replace('"', "", $filename) . "\""
            . "; filename*=UTF-8''" . rawurlencode($filename)
        );
        header("Content-Transfer-Encoding: binary");
        header("Cache-Control: private, max-age=0, must-revalidate");
        # ETag 便于客户端缓存校验
        header('ETag: "' . $attachment["checksum"] . '"');

        # ---- 流式输出 ----
        $fp = @fopen($path, "rb");

        if ($fp === false) {
            exit(0);
        }

        if ($start > 0) {
            fseek($fp, $start);
        }

        $bufferSize = (int) DotNetRegistry::Read("DOWNLOAD_BUFFER", 262144);

        if ($bufferSize < 8192) {
            $bufferSize = 262144;
        }

        $remaining = $length;

        while ($remaining > 0 && !feof($fp)) {
            # 最后一块可能小于缓冲区，取两者较小值避免多读
            $read = ($remaining > $bufferSize) ? $bufferSize : $remaining;
            $buf = fread($fp, $read);

            if ($buf === false || $buf === "") {
                break;
            }

            echo $buf;
            # 立即推送给客户端，避免内容在 PHP 侧堆积
            flush();

            $remaining -= strlen($buf);

            # 客户端已断开则停止读盘，避免无谓的 I/O
            if (connection_aborted()) {
                break;
            }
        }

        fclose($fp);
        exit(0);
    }

    /**
     * 从本地文件创建附件记录（供 SMTP 收信时保存解析出的附件）
     *
     * @param integer $userId
     * @param integer $mailId
     * @param string $filename
     * @param string $mimeType
     * @param string $sourcePath 已落盘的文件绝对路径
     * @param string $contentId 内嵌资源标识，普通附件传空串
     * @param boolean $isInline
     * @return integer 附件 id，失败返回 0
     */
    public static function createFromFile($userId, $mailId, $filename, $mimeType, $sourcePath, $contentId = "", $isInline = false)
    {
        if (!is_file($sourcePath)) {
            return 0;
        }

        $filename = self::sanitizeFilename($filename);
        $size = filesize($sourcePath);
        $checksum = hash_file("sha256", $sourcePath);

        # 秒传：内容相同的文件复用同一份磁盘存储
        $existing = self::table()
            ->where(["checksum" => $checksum, "size" => $size])
            ->find();

        if ($existing !== false && is_file(self::absPath($existing["store_path"]))) {
            $storePath = $existing["store_path"];
            # 来源文件已无用，直接删除
            @unlink($sourcePath);
        } else {
            $storePath = self::newStorePath();
            $absPath = self::absPath($storePath);

            # 优先使用 rename（同分区下是原子操作且无需复制数据）
            if (!@rename($sourcePath, $absPath)) {
                if (!@copy($sourcePath, $absPath)) {
                    return 0;
                }
                @unlink($sourcePath);
            }
        }

        $attId = self::table()->add([
            "mail_id"     => (int) $mailId,
            "user_id"     => (int) $userId,
            "filename"    => $filename,
            "mime_type"   => $mimeType === "" ? self::guessMime($filename) : $mimeType,
            "size"        => $size,
            "store_path"  => $storePath,
            "checksum"    => $checksum,
            "content_id"  => $contentId,
            "is_inline"   => $isInline ? 1 : 0,
            "create_time" => now_time()
        ]);

        return $attId === false ? 0 : (int) $attId;
    }

    /**
     * 把游离附件关联到邮件
     *
     * @param array $attachmentIds
     * @param integer $mailId
     * @param integer $userId
     * @return integer 成功关联的数量
     */
    public static function attachToMail($attachmentIds, $mailId, $userId)
    {
        $count = 0;

        foreach ($attachmentIds as $id) {
            $id = (int) $id;

            if ($id <= 0) {
                continue;
            }

            # 只允许关联本人上传且尚未归属任何邮件的附件，
            # 防止把他人附件挂到自己的邮件上
            $att = self::table()
                ->where(["id" => $id, "user_id" => (int) $userId, "mail_id" => 0])
                ->find();

            if ($att === false) {
                continue;
            }

            self::table()->where(["id" => $id])->save(["mail_id" => (int) $mailId]);
            $count++;
        }

        return $count;
    }

    /**
     * 复制附件记录到另一封邮件（本域直投时收发双方各持一份元数据）
     *
     * 磁盘文件不复制，两条记录指向同一份存储。
     *
     * @param integer $mailId 源邮件 id
     * @param integer $targetMailId 目标邮件 id
     * @param integer $targetUserId 目标用户 id
     * @return void
     */
    public static function copyToMail($mailId, $targetMailId, $targetUserId)
    {
        $rows = self::table()->where(["mail_id" => (int) $mailId])->select();

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            self::table()->add([
                "mail_id"     => (int) $targetMailId,
                "user_id"     => (int) $targetUserId,
                "filename"    => $row["filename"],
                "mime_type"   => $row["mime_type"],
                "size"        => $row["size"],
                # 指向同一份磁盘文件，不重复占用存储
                "store_path"  => $row["store_path"],
                "checksum"    => $row["checksum"],
                "content_id"  => $row["content_id"],
                "is_inline"   => $row["is_inline"],
                "create_time" => now_time()
            ]);
        }
    }

    /**
     * 列出邮件的全部附件
     *
     * @param integer $mailId
     * @return array
     */
    public static function listOfMail($mailId)
    {
        $rows = self::table()->where(["mail_id" => (int) $mailId])->select();

        if (!is_array($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $result[] = self::publicView($row);
        }

        return $result;
    }

    /**
     * 删除附件
     *
     * 磁盘文件仅在没有其他记录引用同一路径时才真正删除，
     * 避免秒传共享存储的场景下误删他人仍在使用的文件。
     *
     * @param integer $attachmentId
     * @param integer $userId
     * @return array{ok:bool, error?:string}
     */
    public static function remove($attachmentId, $userId)
    {
        $att = self::findAccessible($attachmentId, $userId);

        if ($att === null) {
            return ["ok" => false, "error" => "attachment not found"];
        }

        $storePath = $att["store_path"];

        self::table()->where(["id" => (int) $att["id"]])->delete();

        # 统计仍引用该磁盘文件的记录数
        $refs = (int) self::table()->where(["store_path" => $storePath])->count();

        if ($refs === 0) {
            @unlink(self::absPath($storePath));
        }

        if ((int) $att["user_id"] > 0) {
            UserService::addUsedSize($att["user_id"], -(int) $att["size"]);
        }

        return ["ok" => true];
    }

    /**
     * 附件的对外展示结构
     *
     * 不暴露 store_path，防止泄露服务器存储布局。
     *
     * @param array $row
     * @return array
     */
    public static function publicView($row)
    {
        if (empty($row)) {
            return [];
        }

        return [
            "id"         => (int) $row["id"],
            "mail_id"    => (int) $row["mail_id"],
            "filename"   => $row["filename"],
            "mime_type"  => $row["mime_type"],
            "size"       => (int) $row["size"],
            "checksum"   => $row["checksum"],
            "is_inline"  => (int) $row["is_inline"],
            "content_id" => $row["content_id"],
            "download"   => "/api/attachment/download?id=" . (int) $row["id"],
            "create_time" => $row["create_time"]
        ];
    }
}
