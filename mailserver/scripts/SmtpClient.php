<?php
/**
 * SmtpClient.php —— SMTP 投递客户端
 *
 * 框架内没有任何 SMTP 实现，本类完全自研。
 *
 * 投递流程：
 *   1) 查询目标域的 MX 记录（失败则回退到域名本身的 A 记录）
 *   2) 按优先级依次尝试连接各 MX 主机
 *   3) EHLO → STARTTLS（若对端支持）→ MAIL FROM → RCPT TO → DATA
 *   4) 报文以流式方式从文件写入 socket，不整体载入内存
 *
 * 内存安全：DATA 阶段逐行读取报文文件并写入 socket，
 * 因此投递带 1GB 附件的邮件时内存占用依然恒定。
 */

class SmtpClient
{
    /** @var resource|null socket 连接 */
    private $socket = null;

    /** @var string 最近一次的错误描述 */
    private $error = "";

    /** @var array 对端 EHLO 响应中声明支持的扩展 */
    private $capabilities = [];

    /** @var integer 连接与读写超时（秒） */
    private $timeout;

    /** @var string 本机标识，用于 EHLO */
    private $hostname;

    public function __construct()
    {
        $this->timeout = (int) DotNetRegistry::Read("SMTP_TIMEOUT", 30);
        $this->hostname = DotNetRegistry::Read("MAIL_HOSTNAME", "localhost");
    }

    /**
     * 取得最近一次错误描述
     *
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 查询域名的邮件交换主机列表
     *
     * 按 MX 优先级从小到大排序（数值越小优先级越高）。
     * 没有 MX 记录时按规范回退到域名本身（隐式 MX）。
     *
     * @param string $domain
     * @return string[] 主机名列表
     */
    public static function resolveMx($domain)
    {
        $domain = trim(strtolower($domain));

        if ($domain === "") {
            return [];
        }

        $hosts = [];
        $weights = [];

        # getmxrr 在部分 Windows 环境下不可用，需做存在性检查
        if (function_exists("getmxrr") && @getmxrr($domain, $hosts, $weights)) {
            if (!empty($hosts)) {
                # 按优先级升序排序
                array_multisort($weights, SORT_ASC, SORT_NUMERIC, $hosts);
                return $hosts;
            }
        }

        # 无 MX 记录：回退到域名本身（隐式 MX，RFC 5321 规定的行为）
        if (@checkdnsrr($domain, "A") || @gethostbyname($domain) !== $domain) {
            return [$domain];
        }

        return [];
    }

    /**
     * 向指定主机投递一封邮件
     *
     * @param string $host 目标 SMTP 主机
     * @param string $fromAddress 信封发件人
     * @param string[] $recipients 信封收件人列表（同域可批量）
     * @param string $messagePath 报文文件的绝对路径
     * @param integer $port 端口，默认 25
     * @return boolean 成功返回 true
     */
    public function deliver($host, $fromAddress, $recipients, $messagePath, $port = 25)
    {
        $this->error = "";

        if (!is_file($messagePath)) {
            $this->error = "message file not found";
            return false;
        }

        if (!$this->connect($host, $port)) {
            return false;
        }

        try {
            # ---- 读取服务器问候语（220）----
            $greeting = $this->readResponse();

            if (!$this->isCode($greeting, 220)) {
                $this->error = "unexpected greeting: " . $greeting["text"];
                $this->close();
                return false;
            }

            # ---- EHLO ----
            if (!$this->ehlo()) {
                $this->close();
                return false;
            }

            # ---- STARTTLS 加密升级 ----
            $useTls = DotNetRegistry::Read("SMTP_USE_STARTTLS", true);

            if ($useTls && isset($this->capabilities["STARTTLS"])) {
                if ($this->startTls()) {
                    # TLS 握手后必须重新 EHLO：
                    # 加密通道建立后服务器可能声明不同的扩展集
                    if (!$this->ehlo()) {
                        $this->close();
                        return false;
                    }
                }
                # STARTTLS 失败时继续以明文投递，保证可达性优先
            }

            # ---- MAIL FROM ----
            $response = $this->command("MAIL FROM:<" . $fromAddress . ">");

            if (!$this->isCode($response, 250)) {
                $this->error = "MAIL FROM rejected: " . $response["text"];
                $this->quit();
                return false;
            }

            # ---- RCPT TO：逐个声明收件人 ----
            $accepted = 0;

            foreach ($recipients as $rcpt) {
                $response = $this->command("RCPT TO:<" . $rcpt . ">");

                # 250 接受，251 已转发
                if ($this->isCode($response, 250) || $this->isCode($response, 251)) {
                    $accepted++;
                } else {
                    $this->error = "RCPT TO <" . $rcpt . "> rejected: " . $response["text"];
                }
            }

            if ($accepted === 0) {
                $this->quit();
                return false;
            }

            # ---- DATA ----
            $response = $this->command("DATA");

            if (!$this->isCode($response, 354)) {
                $this->error = "DATA rejected: " . $response["text"];
                $this->quit();
                return false;
            }

            if (!$this->writeMessage($messagePath)) {
                $this->quit();
                return false;
            }

            # 结束标记：单独一行的点号
            $response = $this->command(".");

            if (!$this->isCode($response, 250)) {
                $this->error = "message rejected: " . $response["text"];
                $this->quit();
                return false;
            }

            $this->quit();

            return true;
        } catch (Exception $ex) {
            $this->error = "smtp exception: " . $ex->getMessage();
            $this->close();
            return false;
        }
    }

    /**
     * 建立到目标主机的 TCP 连接
     *
     * @param string $host
     * @param integer $port
     * @return boolean
     */
    private function connect($host, $port)
    {
        $errno = 0;
        $errstr = "";

        # stream_socket_client 支持超时控制与后续的 TLS 升级，
        # 相比 fsockopen 更适合需要 STARTTLS 的场景
        $this->socket = @stream_socket_client(
            "tcp://" . $host . ":" . $port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($this->socket === false) {
            $this->socket = null;
            $this->error = "connection to " . $host . ":" . $port . " failed: " . $errstr;
            return false;
        }

        # 设置读写超时，避免对端无响应时永久阻塞
        stream_set_timeout($this->socket, $this->timeout);

        return true;
    }

    /**
     * 发送 EHLO 并解析对端支持的扩展
     *
     * @return boolean
     */
    private function ehlo()
    {
        $response = $this->command("EHLO " . $this->hostname);

        if (!$this->isCode($response, 250)) {
            # 部分老服务器不支持 EHLO，回退到 HELO
            $response = $this->command("HELO " . $this->hostname);

            if (!$this->isCode($response, 250)) {
                $this->error = "EHLO/HELO rejected: " . $response["text"];
                return false;
            }

            $this->capabilities = [];
            return true;
        }

        # 解析多行响应中声明的扩展能力
        $this->capabilities = [];

        foreach ($response["lines"] as $line) {
            # 去掉前 4 个字符的状态码与分隔符
            $text = trim(substr($line, 4));

            if ($text === "") {
                continue;
            }

            $parts = preg_split('/\s+/', $text);
            $name = strtoupper($parts[0]);

            $this->capabilities[$name] = count($parts) > 1
                ? array_slice($parts, 1)
                : true;
        }

        return true;
    }

    /**
     * 执行 STARTTLS 加密升级
     *
     * @return boolean
     */
    private function startTls()
    {
        $response = $this->command("STARTTLS");

        if (!$this->isCode($response, 220)) {
            return false;
        }

        # 在既有连接上启用 TLS。
        # 优先使用 ANY_CLIENT 以兼容不同 PHP 版本支持的协议集
        $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        if (defined("STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT")) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        if (defined("STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT")) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }

        $ok = @stream_socket_enable_crypto($this->socket, true, $crypto);

        if ($ok !== true) {
            $this->error = "STARTTLS handshake failed";
            return false;
        }

        return true;
    }

    /**
     * 发送一条指令并读取响应
     *
     * @param string $command
     * @return array{code:int, text:string, lines:string[]}
     */
    private function command($command)
    {
        $this->writeLine($command);

        return $this->readResponse();
    }

    /**
     * 写入一行（自动补 CRLF）
     *
     * @param string $line
     * @return boolean
     */
    private function writeLine($line)
    {
        if (!is_resource($this->socket)) {
            return false;
        }

        return @fwrite($this->socket, $line . "\r\n") !== false;
    }

    /**
     * 读取一条完整响应
     *
     * SMTP 多行响应的格式：前几行用 `250-`，最后一行用 `250 `（空格）。
     * 依据第 4 个字符是否为空格来判断响应是否结束。
     *
     * @return array{code:int, text:string, lines:string[]}
     */
    private function readResponse()
    {
        $lines = [];
        $code = 0;

        while (is_resource($this->socket)) {
            $line = @fgets($this->socket, 998);

            if ($line === false) {
                break;
            }

            $line = rtrim($line, "\r\n");
            $lines[] = $line;

            # 响应行至少包含 3 位状态码
            if (strlen($line) < 3) {
                break;
            }

            $code = (int) substr($line, 0, 3);

            # 第 4 个字符为空格表示这是最后一行
            if (strlen($line) === 3 || $line[3] === " ") {
                break;
            }

            # 防御：异常的超长多行响应
            if (count($lines) > 100) {
                break;
            }
        }

        return [
            "code"  => $code,
            "text"  => implode(" | ", $lines),
            "lines" => $lines
        ];
    }

    /**
     * 判断响应码是否匹配
     *
     * @param array $response
     * @param integer $expected
     * @return boolean
     */
    private function isCode($response, $expected)
    {
        return (int) $response["code"] === (int) $expected;
    }

    /**
     * 把报文文件流式写入 socket
     *
     * 关键处理：
     *   1) 透明填充（dot-stuffing）：行首若为点号，必须再加一个点，
     *      否则会被对端误判为报文结束标记
     *   2) 换行统一为 CRLF
     *   3) 逐行处理，内存占用与报文体积无关
     *
     * @param string $path
     * @return boolean
     */
    private function writeMessage($path)
    {
        $fp = @fopen($path, "rb");

        if ($fp === false) {
            $this->error = "failed to open message file";
            return false;
        }

        $buffer = "";

        while (!feof($fp)) {
            $line = fgets($fp);

            if ($line === false) {
                break;
            }

            $line = rtrim($line, "\r\n");

            # 透明填充：行首的点号需要转义为两个点
            if (isset($line[0]) && $line[0] === ".") {
                $line = "." . $line;
            }

            $buffer .= $line . "\r\n";

            # 累积到 64KB 再写出，减少系统调用次数
            if (strlen($buffer) >= 65536) {
                if (@fwrite($this->socket, $buffer) === false) {
                    fclose($fp);
                    $this->error = "failed to write message data";
                    return false;
                }

                $buffer = "";
            }
        }

        fclose($fp);

        if ($buffer !== "") {
            if (@fwrite($this->socket, $buffer) === false) {
                $this->error = "failed to write message data";
                return false;
            }
        }

        return true;
    }

    /**
     * 发送 QUIT 并关闭连接
     *
     * @return void
     */
    private function quit()
    {
        if (is_resource($this->socket)) {
            $this->writeLine("QUIT");
            # 读掉服务器的 221 响应，让连接干净地关闭
            $this->readResponse();
        }

        $this->close();
    }

    /**
     * 关闭连接
     *
     * @return void
     */
    private function close()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    /**
     * 向某个域投递（自动查询 MX 并按优先级重试）
     *
     * @param string $domain 目标域
     * @param string $fromAddress
     * @param string[] $recipients 该域下的收件人列表
     * @param string $messagePath
     * @return array{ok:bool, error?:string, host?:string}
     */
    public function deliverToDomain($domain, $fromAddress, $recipients, $messagePath)
    {
        $hosts = self::resolveMx($domain);

        if (empty($hosts)) {
            return ["ok" => false, "error" => "no MX record found for domain " . $domain];
        }

        $lastError = "";

        # 按优先级依次尝试，任一主机成功即返回
        foreach ($hosts as $host) {
            if ($this->deliver($host, $fromAddress, $recipients, $messagePath)) {
                return ["ok" => true, "host" => $host];
            }

            $lastError = $this->error;
            mail_log("sender", "delivery to " . $host . " failed: " . $lastError);
        }

        return ["ok" => false, "error" => $lastError === "" ? "all MX hosts failed" : $lastError];
    }
}
