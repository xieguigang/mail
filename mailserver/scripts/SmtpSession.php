<?php
/**
 * SmtpSession.php —— SMTP 服务端单连接会话状态机
 *
 * 每个入站连接对应一个本类实例，状态与缓冲区完全隔离，互不干扰。
 *
 * 协议状态流转：
 *   INIT --EHLO/HELO--> GREETED --MAIL FROM--> MAIL --RCPT TO--> RCPT
 *        --DATA--> DATA --(收到 \r\n.\r\n)--> GREETED（可继续下一封）
 *   任意状态下 QUIT 均可结束会话。
 *
 * 内存安全（核心设计）：
 *   DATA 阶段绝不把整封邮件累积在内存字符串中。
 *   收到的数据块边收边追加写入临时文件，仅在缓冲区尾部保留少量字节，
 *   用于跨块检测结束标记与执行透明填充还原。
 *   由此单连接内存占用恒定在 KB 级，与邮件体积无关，
 *   可安全接收数百 MB 的带附件来信。
 */

class SmtpSession
{
    # 会话状态
    const STATE_INIT    = "init";
    const STATE_GREETED = "greeted";
    const STATE_MAIL    = "mail";
    const STATE_RCPT    = "rcpt";
    const STATE_DATA    = "data";
    const STATE_QUIT    = "quit";

    /** @var resource 客户端 socket */
    public $socket;

    /** @var string 客户端 IP */
    public $remoteIp;

    /** @var string 当前状态 */
    public $state = self::STATE_INIT;

    /** @var integer 最近一次活动的时间戳，用于空闲超时回收 */
    public $lastActive;

    /** @var string 指令行接收缓冲（仅在非 DATA 阶段使用） */
    private $lineBuffer = "";

    /** @var string 对端 EHLO 声明的标识 */
    private $helo = "";

    /** @var string 信封发件人 */
    private $mailFrom = "";

    /** @var array 信封收件人列表，每项为 ["address"=>, "user"=>] */
    private $rcptTo = [];

    /** @var resource|null DATA 阶段的临时文件句柄 */
    private $dataFile = null;

    /** @var string DATA 阶段临时文件路径 */
    private $dataPath = "";

    /** @var integer 已接收的报文字节数 */
    private $dataSize = 0;

    /** @var string DATA 阶段的尾部残留缓冲，用于跨块检测结束标记 */
    private $dataTail = "";

    /** @var integer 连续协议错误次数 */
    private $errorCount = 0;

    /** @var boolean 会话是否已结束 */
    public $closed = false;

    /**
     * @param resource $socket
     * @param string $remoteIp
     */
    public function __construct($socket, $remoteIp)
    {
        $this->socket = $socket;
        $this->remoteIp = $remoteIp;
        $this->lastActive = time();
    }

    /**
     * 发送问候语（连接建立后立即调用）
     *
     * @return void
     */
    public function greet()
    {
        $hostname = DotNetRegistry::Read("MAIL_HOSTNAME", "localhost");

        $this->reply(220, $hostname . " ESMTP " . DotNetRegistry::Read("APP_NAME", "php-mail-server") . " ready");
    }

    /**
     * 处理从 socket 收到的一段数据
     *
     * 依据当前状态决定是按指令行解析，还是作为报文内容写盘。
     *
     * @param string $data
     * @return void
     */
    public function feed($data)
    {
        $this->lastActive = time();

        if ($this->state === self::STATE_DATA) {
            $this->feedData($data);
            return;
        }

        # ---- 指令阶段：按行切分 ----
        $this->lineBuffer .= $data;

        # 防御：指令行异常超长（正常 SMTP 指令不超过 512 字节）
        if (strlen($this->lineBuffer) > 8192) {
            $this->lineBuffer = "";
            $this->reply(500, "Line too long");
            $this->countError();
            return;
        }

        while (($pos = strpos($this->lineBuffer, "\n")) !== false) {
            $line = substr($this->lineBuffer, 0, $pos);
            $this->lineBuffer = substr($this->lineBuffer, $pos + 1);

            $line = rtrim($line, "\r\n");

            $this->handleCommand($line);

            # 进入 DATA 状态后，缓冲区中剩余的内容属于报文内容
            if ($this->state === self::STATE_DATA && $this->lineBuffer !== "") {
                $rest = $this->lineBuffer;
                $this->lineBuffer = "";
                $this->feedData($rest);
                return;
            }

            if ($this->closed) {
                return;
            }
        }
    }

    /**
     * 处理一条 SMTP 指令
     *
     * @param string $line
     * @return void
     */
    private function handleCommand($line)
    {
        $trimmed = trim($line);

        if ($trimmed === "") {
            return;
        }

        # 指令与参数以第一个空格分隔
        $spacePos = strpos($trimmed, " ");

        if ($spacePos === false) {
            $verb = strtoupper($trimmed);
            $args = "";
        } else {
            $verb = strtoupper(substr($trimmed, 0, $spacePos));
            $args = trim(substr($trimmed, $spacePos + 1));
        }

        switch ($verb) {
            case "EHLO":
                $this->cmdEhlo($args, true);
                break;

            case "HELO":
                $this->cmdEhlo($args, false);
                break;

            case "MAIL":
                $this->cmdMail($args);
                break;

            case "RCPT":
                $this->cmdRcpt($args);
                break;

            case "DATA":
                $this->cmdData();
                break;

            case "RSET":
                # 重置事务状态，但保留 EHLO 结果
                $this->resetTransaction();
                $this->state = ($this->helo === "") ? self::STATE_INIT : self::STATE_GREETED;
                $this->reply(250, "OK");
                break;

            case "NOOP":
                $this->reply(250, "OK");
                break;

            case "VRFY":
                # 不泄露账号是否存在，避免被用于枚举有效邮箱
                $this->reply(252, "Cannot VRFY user, but will accept message and attempt delivery");
                break;

            case "QUIT":
                $hostname = DotNetRegistry::Read("MAIL_HOSTNAME", "localhost");
                $this->reply(221, $hostname . " closing connection");
                $this->state = self::STATE_QUIT;
                $this->closed = true;
                break;

            case "STARTTLS":
                # 本实现基于原生 socket，不在服务端支持 TLS 升级；
                # 生产环境建议在前面加一层 stunnel 或反向代理来提供加密
                $this->reply(454, "TLS not available");
                break;

            case "AUTH":
                # 作为接收外部来信的 MX，不需要也不应开放认证提交
                $this->reply(502, "Authentication not supported on this port");
                break;

            default:
                $this->reply(500, "Command not recognized");
                $this->countError();
        }
    }

    /**
     * EHLO / HELO
     *
     * @param string $args
     * @param boolean $extended 是否为 EHLO
     * @return void
     */
    private function cmdEhlo($args, $extended)
    {
        if ($args === "") {
            $this->reply(501, "Syntax: " . ($extended ? "EHLO" : "HELO") . " hostname");
            $this->countError();
            return;
        }

        $this->helo = $args;
        $this->state = self::STATE_GREETED;
        $this->resetTransaction();

        $hostname = DotNetRegistry::Read("MAIL_HOSTNAME", "localhost");

        if (!$extended) {
            $this->reply(250, $hostname);
            return;
        }

        # EHLO 多行响应：声明支持的扩展能力
        $maxSize = (int) DotNetRegistry::Read("SMTPD_MAX_SIZE", 104857600);

        $this->replyMulti(250, [
            $hostname . " Hello " . $args,
            "SIZE " . $maxSize,
            # 声明支持 8 位正文，附件与非 ASCII 内容需要
            "8BITMIME",
            "ENHANCEDSTATUSCODES",
            "PIPELINING"
        ]);
    }

    /**
     * MAIL FROM
     *
     * @param string $args
     * @return void
     */
    private function cmdMail($args)
    {
        if ($this->state !== self::STATE_GREETED) {
            $this->reply(503, "Bad sequence of commands, send EHLO first");
            $this->countError();
            return;
        }

        if (!preg_match('/^FROM\s*:\s*(.*)$/i', $args, $m)) {
            $this->reply(501, "Syntax: MAIL FROM:<address>");
            $this->countError();
            return;
        }

        $rest = trim($m[1]);

        # 提取尖括号中的地址，并解析可能附带的 SIZE 参数
        if (preg_match('/^<([^>]*)>/', $rest, $am)) {
            $address = trim($am[1]);
            $params = trim(substr($rest, strlen($am[0])));
        } else {
            $parts = preg_split('/\s+/', $rest, 2);
            $address = trim($parts[0]);
            $params = isset($parts[1]) ? $parts[1] : "";
        }

        # 空地址是合法的（退信通知使用 MAIL FROM:<>）
        if ($address !== "" && !MailAddress::isValid($address)) {
            $this->reply(501, "Invalid sender address");
            $this->countError();
            return;
        }

        # 若声明了 SIZE，提前拒绝超限的邮件，避免白白接收数据
        if (preg_match('/SIZE\s*=\s*(\d+)/i', $params, $sm)) {
            $declared = (int) $sm[1];
            $maxSize = (int) DotNetRegistry::Read("SMTPD_MAX_SIZE", 104857600);

            if ($declared > $maxSize) {
                $this->reply(552, "Message size exceeds maximum of " . $maxSize . " bytes");
                return;
            }
        }

        $this->mailFrom = MailAddress::normalize($address);
        $this->rcptTo = [];
        $this->state = self::STATE_MAIL;

        $this->reply(250, "OK");
    }

    /**
     * RCPT TO
     *
     * 校验收件人是否为本域用户且存在，不存在则拒收，
     * 避免成为开放中继（open relay）被滥用发送垃圾邮件。
     *
     * @param string $args
     * @return void
     */
    private function cmdRcpt($args)
    {
        if ($this->state !== self::STATE_MAIL && $this->state !== self::STATE_RCPT) {
            $this->reply(503, "Bad sequence of commands, send MAIL FROM first");
            $this->countError();
            return;
        }

        if (!preg_match('/^TO\s*:\s*(.*)$/i', $args, $m)) {
            $this->reply(501, "Syntax: RCPT TO:<address>");
            $this->countError();
            return;
        }

        $rest = trim($m[1]);

        if (preg_match('/^<([^>]*)>/', $rest, $am)) {
            $address = trim($am[1]);
        } else {
            $parts = preg_split('/\s+/', $rest, 2);
            $address = trim($parts[0]);
        }

        $address = MailAddress::normalize($address);

        if (!MailAddress::isValid($address)) {
            $this->reply(501, "Invalid recipient address");
            $this->countError();
            return;
        }

        $maxRcpt = (int) DotNetRegistry::Read("SMTPD_MAX_RCPT", 100);

        if (count($this->rcptTo) >= $maxRcpt) {
            $this->reply(452, "Too many recipients");
            return;
        }

        # 只接收本域邮件：拒绝中继，否则服务器会被当作垃圾邮件跳板
        if (!MailAddress::isLocal($address)) {
            $this->reply(550, "Relay access denied");
            return;
        }

        $user = UserService::findByEmail($address);

        if ($user === null) {
            $this->reply(550, "No such user here");
            return;
        }

        if ((int) $user["status"] !== 1) {
            $this->reply(550, "Mailbox unavailable");
            return;
        }

        # 同一地址重复声明时去重
        foreach ($this->rcptTo as $existing) {
            if ($existing["address"] === $address) {
                $this->reply(250, "OK");
                return;
            }
        }

        $this->rcptTo[] = ["address" => $address, "user" => $user];
        $this->state = self::STATE_RCPT;

        $this->reply(250, "OK");
    }

    /**
     * DATA：开始接收报文内容
     *
     * @return void
     */
    private function cmdData()
    {
        if ($this->state !== self::STATE_RCPT || empty($this->rcptTo)) {
            $this->reply(503, "Bad sequence of commands, send RCPT TO first");
            $this->countError();
            return;
        }

        # 建立临时文件，报文将边收边写入
        $dir = AttachmentService::tmpRoot() . "/inbound";

        if (!ensure_dir($dir)) {
            $this->reply(451, "Server storage error");
            return;
        }

        $this->dataPath = $dir . "/" . uuid_v4() . ".eml";
        $this->dataFile = @fopen($this->dataPath, "wb");

        if ($this->dataFile === false) {
            $this->dataFile = null;
            $this->reply(451, "Server storage error");
            return;
        }

        $this->dataSize = 0;
        $this->dataTail = "";
        $this->state = self::STATE_DATA;

        # 写入 Received 信头，记录本次投递的来源信息
        $received = "Received: from " . $this->helo . " (" . $this->remoteIp . ")" . "\r\n"
            . "\tby " . DotNetRegistry::Read("MAIL_HOSTNAME", "localhost")
            . " with SMTP; " . date("r") . "\r\n";

        fwrite($this->dataFile, $received);
        $this->dataSize += strlen($received);

        $this->reply(354, "End data with <CR><LF>.<CR><LF>");
    }

    /**
     * DATA 阶段：接收报文内容
     *
     * 边收边写盘，只在内存中保留少量尾部字节用于跨块检测结束标记。
     *
     * @param string $data
     * @return void
     */
    private function feedData($data)
    {
        if ($this->dataFile === null) {
            return;
        }

        # 把上一轮的残留尾部与本次数据拼接，
        # 以便正确检测跨越数据块边界的结束标记
        $buffer = $this->dataTail . $data;
        $this->dataTail = "";

        # ---- 查找结束标记 \r\n.\r\n ----
        $endPos = strpos($buffer, "\r\n.\r\n");

        # 兼容只用 \n 的非标准实现
        if ($endPos === false) {
            $endPos = strpos($buffer, "\n.\n");
            $endLen = 3;
        } else {
            $endLen = 5;
        }

        if ($endPos !== false) {
            # 找到结束标记：写入之前的内容（含结尾的 CRLF），完成接收
            $content = substr($buffer, 0, $endPos + 2);
            $this->writeData($content);
            $this->finishData();
            return;
        }

        # ---- 未找到结束标记 ----
        # 保留末尾若干字节到下一轮，防止结束标记恰好被切断。
        # 标记最长 5 字节，保留 4 字节即可覆盖所有跨界情形
        $keep = 4;

        if (strlen($buffer) > $keep) {
            $content = substr($buffer, 0, strlen($buffer) - $keep);
            $this->dataTail = substr($buffer, -$keep);
            $this->writeData($content);
        } else {
            $this->dataTail = $buffer;
        }
    }

    /**
     * 写入报文内容，并执行透明填充还原与体积检查
     *
     * 透明填充（dot-stuffing）：发送方会把行首的点号变成两个点，
     * 接收方必须还原，否则报文内容会被破坏。
     *
     * @param string $content
     * @return void
     */
    private function writeData($content)
    {
        if ($content === "") {
            return;
        }

        # 还原透明填充：行首的两个点还原为一个点
        $content = str_replace("\r\n..", "\r\n.", $content);

        $maxSize = (int) DotNetRegistry::Read("SMTPD_MAX_SIZE", 104857600);

        if ($this->dataSize + strlen($content) > $maxSize) {
            # 超限：立即停止写盘并拒收，避免磁盘被恶意占满
            $this->abortData();
            $this->reply(552, "Message size exceeds maximum of " . $maxSize . " bytes");
            $this->state = self::STATE_GREETED;
            return;
        }

        fwrite($this->dataFile, $content);
        $this->dataSize += strlen($content);
    }

    /**
     * 报文接收完成：解析并投递
     *
     * @return void
     */
    private function finishData()
    {
        fclose($this->dataFile);
        $this->dataFile = null;

        $rawPath = $this->dataPath;
        $size = $this->dataSize;
        $recipients = $this->rcptTo;
        $from = $this->mailFrom;

        # 无论投递成败都要重置事务，使连接可继续接收下一封邮件
        $this->resetTransaction();
        $this->state = self::STATE_GREETED;

        try {
            $delivered = $this->deliver($rawPath, $size, $recipients);

            if ($delivered > 0) {
                $this->reply(250, "OK: message accepted for delivery");
                $this->logResult("accepted", $from, $recipients, $size,
                    "delivered to " . $delivered . " recipient(s)");
            } else {
                $this->reply(451, "Requested action aborted: local error in processing");
                $this->logResult("error", $from, $recipients, $size, "delivery failed");
            }
        } catch (Exception $ex) {
            $this->reply(451, "Requested action aborted: " . $ex->getMessage());
            $this->logResult("error", $from, $recipients, $size, $ex->getMessage());
            mail_log("smtpd", "delivery exception: " . $ex->getMessage());
        }
    }

    /**
     * 解析报文并投递给各收件人
     *
     * @param string $rawPath 临时报文路径
     * @param integer $size
     * @param array $recipients
     * @return integer 成功投递的收件人数
     */
    private function deliver($rawPath, $size, $recipients)
    {
        # 流式解析：内存占用与报文体积无关
        $parser = new MimeParser($rawPath);
        $parsed = $parser->parse();

        if (!$parsed["ok"]) {
            mail_log("smtpd", "mime parse failed: " . $parsed["error"]);
            @unlink($rawPath);
            return 0;
        }

        # 归档原始报文，按年月分目录存放
        $archiveDir = AttachmentService::rawRoot() . "/" . date("Ym");
        ensure_dir($archiveDir);

        $archiveName = date("Ym") . "/" . uuid_v4() . ".eml";
        $archivePath = AttachmentService::rawRoot() . "/" . $archiveName;

        if (!@rename($rawPath, $archivePath)) {
            @copy($rawPath, $archivePath);
            @unlink($rawPath);
        }

        $count = 0;

        foreach ($recipients as $rcpt) {
            # 附件的临时文件会在首个收件人投递时被移动走，
            # 因此为后续收件人重新解析一次，保证每人都能拿到附件
            if ($count > 0) {
                $reparser = new MimeParser($archivePath);
                $reparsed = $reparser->parse();

                if (!$reparsed["ok"]) {
                    continue;
                }

                $payload = $reparsed;
            } else {
                $payload = $parsed;
            }

            $mailId = MailService::deliverInbound($rcpt["user"], $payload, $archiveName, $size);

            if ($mailId > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 中止 DATA 接收并清理临时文件
     *
     * @return void
     */
    private function abortData()
    {
        if ($this->dataFile !== null) {
            fclose($this->dataFile);
            $this->dataFile = null;
        }

        if ($this->dataPath !== "" && is_file($this->dataPath)) {
            @unlink($this->dataPath);
        }

        $this->dataPath = "";
        $this->dataSize = 0;
        $this->dataTail = "";
    }

    /**
     * 重置事务状态（保留 EHLO 结果）
     *
     * @return void
     */
    private function resetTransaction()
    {
        $this->mailFrom = "";
        $this->rcptTo = [];
        $this->dataSize = 0;
        $this->dataTail = "";
        $this->dataPath = "";

        if ($this->dataFile !== null) {
            fclose($this->dataFile);
            $this->dataFile = null;
        }
    }

    /**
     * 累计协议错误，超过阈值则断开连接
     *
     * 防止恶意客户端持续发送非法指令占用连接资源。
     *
     * @return void
     */
    private function countError()
    {
        $this->errorCount++;

        $max = (int) DotNetRegistry::Read("SMTPD_MAX_ERRORS", 10);

        if ($this->errorCount >= $max) {
            $this->reply(421, "Too many errors, closing connection");
            $this->closed = true;
        }
    }

    /**
     * 记录收信结果
     *
     * @param string $result
     * @param string $from
     * @param array $recipients
     * @param integer $size
     * @param string $message
     * @return void
     */
    private function logResult($result, $from, $recipients, $size, $message)
    {
        $addresses = [];

        foreach ($recipients as $r) {
            $addresses[] = $r["address"];
        }

        try {
            (new Table(SMTP_LOG_TABLE))->add([
                "remote_ip"    => $this->remoteIp,
                "helo"         => mb_substr($this->helo, 0, 250),
                "from_address" => mb_substr($from, 0, 250),
                "to_address"   => mb_substr(implode(",", $addresses), 0, 1000),
                "size"         => (int) $size,
                "result"       => $result,
                "message"      => mb_substr($message, 0, 500),
                "create_time"  => now_time()
            ]);
        } catch (Exception $ex) {
            mail_log("smtpd", "failed to write smtp log: " . $ex->getMessage());
        }
    }

    /**
     * 发送单行响应
     *
     * @param integer $code
     * @param string $text
     * @return void
     */
    public function reply($code, $text)
    {
        $this->write($code . " " . $text . "\r\n");
    }

    /**
     * 发送多行响应
     *
     * 除最后一行外均以 `code-` 开头，最后一行以 `code ` 开头。
     *
     * @param integer $code
     * @param string[] $lines
     * @return void
     */
    public function replyMulti($code, $lines)
    {
        $count = count($lines);
        $out = "";

        foreach ($lines as $i => $line) {
            $separator = ($i === $count - 1) ? " " : "-";
            $out .= $code . $separator . $line . "\r\n";
        }

        $this->write($out);
    }

    /**
     * 向 socket 写数据
     *
     * @param string $data
     * @return void
     */
    private function write($data)
    {
        if (!is_resource($this->socket)) {
            return;
        }

        # socket_write 可能只写出部分数据，需循环直到全部写完
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $result = @socket_write($this->socket, substr($data, $written), $length - $written);

            if ($result === false) {
                $this->closed = true;
                return;
            }

            $written += $result;
        }
    }

    /**
     * 清理会话资源（连接关闭时调用）
     *
     * @return void
     */
    public function cleanup()
    {
        $this->abortData();
    }
}
