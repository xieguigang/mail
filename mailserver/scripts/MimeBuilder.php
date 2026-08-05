<?php
/**
 * MimeBuilder.php —— MIME 报文构建器
 *
 * 把数据库中的邮件记录组装为符合 RFC 5322 / 2045 的完整报文。
 *
 * 关键设计：附件以 Base64 分块编码流式写出到临时文件，
 * 绝不把整个附件读入内存再拼接字符串。
 * 一封带 1GB 附件的邮件，构建过程内存占用仍恒定在数百 KB。
 *
 * 生成的报文结构（有附件且有 HTML 正文时）：
 *   multipart/mixed
 *   ├── multipart/alternative
 *   │   ├── text/plain
 *   │   └── text/html
 *   ├── application/pdf  (附件)
 *   └── image/png        (附件)
 */

class MimeBuilder
{
    /** @var string 行分隔符，SMTP 要求使用 CRLF */
    const CRLF = "\r\n";

    /**
     * 构建完整邮件报文并写入临时文件
     *
     * @param array $mail 邮件记录（mails 表的一行）
     * @param array $attachments 附件记录列表
     * @return string|false 报文文件的绝对路径，失败返回 false
     */
    public static function build($mail, $attachments = [])
    {
        $tmpDir = AttachmentService::tmpRoot() . "/outbox";

        if (!ensure_dir($tmpDir)) {
            return false;
        }

        $path = $tmpDir . "/" . uuid_v4() . ".eml";
        $fp = @fopen($path, "wb");

        if ($fp === false) {
            return false;
        }

        $hasAttachment = !empty($attachments);
        $hasHtml = trim((string) $mail["body_html"]) !== "";
        $hasText = trim((string) $mail["body_text"]) !== "";

        # 纯文本为空时由 HTML 生成一份，保证不支持 HTML 的客户端也能阅读
        $text = $hasText
            ? $mail["body_text"]
            : ($hasHtml ? self::htmlToText($mail["body_html"]) : "");

        $mixedBoundary = self::newBoundary("mixed");
        $altBoundary = self::newBoundary("alt");

        # ---- 写信头 ----
        self::writeHeaders($fp, $mail, $hasAttachment, $hasHtml, $mixedBoundary, $altBoundary);

        # ---- 写正文 ----
        if ($hasAttachment) {
            # 最外层是 multipart/mixed，正文作为第一个分段
            fwrite($fp, self::CRLF . "--" . $mixedBoundary . self::CRLF);

            if ($hasHtml) {
                # 正文本身又是 multipart/alternative
                fwrite($fp, "Content-Type: multipart/alternative; boundary=\"" . $altBoundary . "\"" . self::CRLF);
                fwrite($fp, self::CRLF);
                self::writeAlternative($fp, $altBoundary, $text, $mail["body_html"]);
            } else {
                self::writeTextPart($fp, $text);
            }

            # ---- 逐个写附件 ----
            foreach ($attachments as $att) {
                self::writeAttachment($fp, $mixedBoundary, $att);
            }

            # 结束标记
            fwrite($fp, self::CRLF . "--" . $mixedBoundary . "--" . self::CRLF);
        } else {
            if ($hasHtml) {
                fwrite($fp, self::CRLF);
                self::writeAlternative($fp, $altBoundary, $text, $mail["body_html"]);
                fwrite($fp, self::CRLF . "--" . $altBoundary . "--" . self::CRLF);
            } else {
                fwrite($fp, self::CRLF);
                fwrite($fp, self::encodeQuotedPrintable($text));
                fwrite($fp, self::CRLF);
            }
        }

        fclose($fp);

        return $path;
    }

    /**
     * 写入邮件信头
     *
     * @param resource $fp
     * @param array $mail
     * @param boolean $hasAttachment
     * @param boolean $hasHtml
     * @param string $mixedBoundary
     * @param string $altBoundary
     * @return void
     */
    private static function writeHeaders($fp, $mail, $hasAttachment, $hasHtml, $mixedBoundary, $altBoundary)
    {
        $from = MailAddress::format([
            "name"    => $mail["from_name"],
            "address" => $mail["from_address"]
        ]);

        fwrite($fp, "Date: " . date("r", strtotime($mail["mail_time"])) . self::CRLF);
        fwrite($fp, "From: " . self::encodeAddress($mail["from_name"], $mail["from_address"]) . self::CRLF);

        # 收件人列表从 mail_recipients 表读取
        $recipients = MailService::recipientsOf($mail["id"]);
        $to = [];
        $cc = [];

        foreach ($recipients as $r) {
            if ($r["type"] === "to") {
                $to[] = self::encodeAddress($r["name"], $r["address"]);
            } else if ($r["type"] === "cc") {
                $cc[] = self::encodeAddress($r["name"], $r["address"]);
            }
            # bcc 绝不写入信头：密送地址对其他收件人不可见，
            # 仅在 SMTP 会话的 RCPT TO 指令中出现
        }

        if (!empty($to)) {
            fwrite($fp, "To: " . implode(", ", $to) . self::CRLF);
        }

        if (!empty($cc)) {
            fwrite($fp, "Cc: " . implode(", ", $cc) . self::CRLF);
        }

        fwrite($fp, "Subject: " . self::encodeHeaderText($mail["subject"]) . self::CRLF);
        fwrite($fp, "Message-ID: <" . $mail["message_id"] . ">" . self::CRLF);

        if (!empty($mail["in_reply_to"])) {
            fwrite($fp, "In-Reply-To: <" . $mail["in_reply_to"] . ">" . self::CRLF);
        }

        if (!empty($mail["references"])) {
            fwrite($fp, "References: " . $mail["references"] . self::CRLF);
        }

        fwrite($fp, "MIME-Version: 1.0" . self::CRLF);
        fwrite($fp, "X-Mailer: " . DotNetRegistry::Read("APP_NAME", "php-mail-server") . self::CRLF);

        # 依据结构写 Content-Type
        if ($hasAttachment) {
            fwrite($fp, "Content-Type: multipart/mixed; boundary=\"" . $mixedBoundary . "\"" . self::CRLF);
        } else if ($hasHtml) {
            fwrite($fp, "Content-Type: multipart/alternative; boundary=\"" . $altBoundary . "\"" . self::CRLF);
        } else {
            fwrite($fp, "Content-Type: text/plain; charset=UTF-8" . self::CRLF);
            fwrite($fp, "Content-Transfer-Encoding: quoted-printable" . self::CRLF);
        }
    }

    /**
     * 写 multipart/alternative 的两个正文分段
     *
     * 纯文本在前、HTML 在后：按规范客户端应优先展示最后一个能渲染的分段。
     *
     * @param resource $fp
     * @param string $boundary
     * @param string $text
     * @param string $html
     * @return void
     */
    private static function writeAlternative($fp, $boundary, $text, $html)
    {
        fwrite($fp, "--" . $boundary . self::CRLF);
        fwrite($fp, "Content-Type: text/plain; charset=UTF-8" . self::CRLF);
        fwrite($fp, "Content-Transfer-Encoding: quoted-printable" . self::CRLF);
        fwrite($fp, self::CRLF);
        fwrite($fp, self::encodeQuotedPrintable($text));
        fwrite($fp, self::CRLF);

        fwrite($fp, "--" . $boundary . self::CRLF);
        fwrite($fp, "Content-Type: text/html; charset=UTF-8" . self::CRLF);
        fwrite($fp, "Content-Transfer-Encoding: quoted-printable" . self::CRLF);
        fwrite($fp, self::CRLF);
        fwrite($fp, self::encodeQuotedPrintable($html));
    }

    /**
     * 写单个纯文本正文分段
     *
     * @param resource $fp
     * @param string $text
     * @return void
     */
    private static function writeTextPart($fp, $text)
    {
        fwrite($fp, "Content-Type: text/plain; charset=UTF-8" . self::CRLF);
        fwrite($fp, "Content-Transfer-Encoding: quoted-printable" . self::CRLF);
        fwrite($fp, self::CRLF);
        fwrite($fp, self::encodeQuotedPrintable($text));
    }

    /**
     * 写一个附件分段，内容以 Base64 分块编码流式写出
     *
     * 每次从源文件读取 3 的倍数字节（Base64 每 3 字节编码为 4 字符），
     * 保证分块编码结果与整体编码完全一致。
     *
     * @param resource $fp
     * @param string $boundary
     * @param array $att 附件记录
     * @return void
     */
    private static function writeAttachment($fp, $boundary, $att)
    {
        $path = AttachmentService::absPath($att["store_path"]);

        if (!is_file($path)) {
            return;
        }

        $filename = $att["filename"];

        fwrite($fp, self::CRLF . "--" . $boundary . self::CRLF);
        fwrite($fp, "Content-Type: " . $att["mime_type"]
            . "; name=\"" . self::encodeHeaderText($filename) . "\"" . self::CRLF);
        fwrite($fp, "Content-Transfer-Encoding: base64" . self::CRLF);

        $disposition = ((int) $att["is_inline"] === 1) ? "inline" : "attachment";

        fwrite($fp, "Content-Disposition: " . $disposition
            . "; filename=\"" . self::encodeHeaderText($filename) . "\""
            . "; size=" . (int) $att["size"] . self::CRLF);

        if (!empty($att["content_id"])) {
            fwrite($fp, "Content-ID: <" . $att["content_id"] . ">" . self::CRLF);
        }

        fwrite($fp, self::CRLF);

        $in = @fopen($path, "rb");

        if ($in === false) {
            return;
        }

        # 每次读取 57 * 1024 * 3 字节：
        #   57 字节编码后正好是 76 字符（Base64 建议的行宽）
        #   取其倍数可保证每块编码结果都能按 76 字符整齐折行
        $blockSize = 57 * 1024 * 3;

        while (!feof($in)) {
            $buf = fread($in, $blockSize);

            if ($buf === false || $buf === "") {
                break;
            }

            # chunk_split 按 76 字符插入换行，符合 RFC 2045 对行长的限制
            fwrite($fp, chunk_split(base64_encode($buf), 76, self::CRLF));
        }

        fclose($in);
    }

    /**
     * 生成 boundary 分隔串
     *
     * 必须足够随机，以确保不会与正文或附件内容意外重合。
     *
     * @param string $prefix
     * @return string
     */
    public static function newBoundary($prefix = "part")
    {
        return "----=_" . $prefix . "_" . bin2hex(random_bytes(16));
    }

    /**
     * 编码信头中的文本（RFC 2047 编码字）
     *
     * 纯 ASCII 直接返回；含非 ASCII 字符时使用 Base64 编码字，
     * 并按规范折行（每个编码字不超过 75 字符）。
     *
     * @param string $text
     * @return string
     */
    public static function encodeHeaderText($text)
    {
        $text = (string) $text;

        if ($text === "") {
            return "";
        }

        # 移除可能引发信头注入的换行符
        $text = str_replace(["\r", "\n"], " ", $text);

        # 纯 ASCII 且不含特殊字符时无需编码
        if (preg_match('/^[\x20-\x7E]*$/', $text)) {
            return $text;
        }

        $encoded = [];
        # 每个编码字的 Base64 载荷上限：75 - 编码字固定开销，
        # 按 UTF-8 最长 4 字节字符切分，取 45 字节较为安全
        $chunkBytes = 45;
        $length = mb_strlen($text, "UTF-8");
        $buffer = "";

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1, "UTF-8");

            # 累积到接近上限时切分，确保不在多字节字符中间断开
            if (strlen($buffer) + strlen($char) > $chunkBytes) {
                $encoded[] = "=?UTF-8?B?" . base64_encode($buffer) . "?=";
                $buffer = "";
            }

            $buffer .= $char;
        }

        if ($buffer !== "") {
            $encoded[] = "=?UTF-8?B?" . base64_encode($buffer) . "?=";
        }

        # 编码字之间用 CRLF + 空格折行
        return implode(self::CRLF . " ", $encoded);
    }

    /**
     * 编码带显示名的邮件地址
     *
     * @param string $name
     * @param string $address
     * @return string
     */
    public static function encodeAddress($name, $address)
    {
        $name = trim((string) $name);
        $address = trim((string) $address);

        if ($name === "") {
            return $address;
        }

        return self::encodeHeaderText($name) . " <" . $address . ">";
    }

    /**
     * Quoted-Printable 编码
     *
     * 使用 PHP 内置实现，并把换行统一为 CRLF。
     *
     * @param string $text
     * @return string
     */
    public static function encodeQuotedPrintable($text)
    {
        $text = (string) $text;

        if ($text === "") {
            return "";
        }

        # 先统一换行符，避免编码结果中出现裸 LF
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\n", self::CRLF, $text);

        if (function_exists("quoted_printable_encode")) {
            return quoted_printable_encode($text);
        }

        # 兜底：手工实现
        return self::fallbackQpEncode($text);
    }

    /**
     * quoted_printable_encode 不可用时的兜底实现
     *
     * @param string $text
     * @return string
     */
    private static function fallbackQpEncode($text)
    {
        $result = "";
        $lineLength = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];
            $ord = ord($ch);

            # 保留 CRLF 原样，并重置行长计数
            if ($ch === "\r" && $i + 1 < $length && $text[$i + 1] === "\n") {
                $result .= self::CRLF;
                $lineLength = 0;
                $i++;
                continue;
            }

            # 可打印 ASCII（排除 = 号）直接输出
            if ($ord >= 33 && $ord <= 126 && $ch !== "=") {
                $encoded = $ch;
            } else if ($ch === " " || $ch === "\t") {
                $encoded = $ch;
            } else {
                $encoded = sprintf("=%02X", $ord);
            }

            # 行长超过 75 时插入软换行
            if ($lineLength + strlen($encoded) > 75) {
                $result .= "=" . self::CRLF;
                $lineLength = 0;
            }

            $result .= $encoded;
            $lineLength += strlen($encoded);
        }

        return $result;
    }

    /**
     * HTML 转纯文本（用于生成 text/plain 备选正文）
     *
     * @param string $html
     * @return string
     */
    public static function htmlToText($html)
    {
        $text = (string) $html;

        # 移除脚本与样式块（含内容）
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', "", $text);
        # 块级标签转换为换行
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text);
        $text = preg_replace('/<\/\s*(p|div|tr|li|h[1-6])\s*>/i', "\n", $text);
        # 剥离剩余标签
        $text = strip_tags($text);
        # 还原 HTML 实体
        $text = html_entity_decode($text, ENT_QUOTES, "UTF-8");
        # 压缩连续空行
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * 生成符合规范的 Message-ID
     *
     * @param string|null $domain 为空则取配置中的主机名
     * @return string 不含尖括号
     */
    public static function newMessageId($domain = null)
    {
        if ($domain === null) {
            $domain = DotNetRegistry::Read("MAIL_HOSTNAME", "localhost");
        }

        return date("YmdHis") . "." . bin2hex(random_bytes(12)) . "@" . $domain;
    }
}
