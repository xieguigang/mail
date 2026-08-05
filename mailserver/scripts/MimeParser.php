<?php
/**
 * MimeParser.php —— MIME 报文流式解析器
 *
 * 框架内没有任何 MIME 解析实现，本类完全自研。
 *
 * 核心设计：内存占用与邮件体积无关（O(1)）
 *   1) 基于文件句柄逐行读取，绝不使用 file_get_contents 全量载入
 *   2) 递归切分多层嵌套结构时，只记录每个分段在原始文件中的
 *      字节偏移与长度，不复制任何内容
 *   3) 正文分段按偏移读取并解码
 *   4) 附件分段以分块流式解码直接写入目标文件
 *      （Base64 解码按 4 字节对齐分块处理）
 *
 * 由此一封 500MB 的带附件邮件也只占用几十 KB 内存。
 */

class MimeParser
{
    /** @var string 原始报文文件路径 */
    private $path;

    /** @var resource 文件句柄 */
    private $fp;

    /**
     * @param string $path 原始报文文件的绝对路径
     */
    public function __construct($path)
    {
        $this->path = $path;
    }

    /**
     * 解析整封邮件
     *
     * @return array{ok:bool, error?:string, headers?:array, subject?:string,
     *               from?:array, to?:array, cc?:array, date?:string,
     *               message_id?:string, in_reply_to?:string, references?:string,
     *               text?:string, html?:string, attachments?:array}
     */
    public function parse()
    {
        if (!is_file($this->path)) {
            return ["ok" => false, "error" => "raw message file not found"];
        }

        $this->fp = @fopen($this->path, "rb");

        if ($this->fp === false) {
            return ["ok" => false, "error" => "failed to open raw message file"];
        }

        try {
            $size = filesize($this->path);

            # 解析最外层信头
            $headerBlock = $this->readHeaderBlock(0);
            $headers = self::parseHeaders($headerBlock["text"]);

            # 正文区间：信头结束之后直到文件末尾
            $bodyStart = $headerBlock["end"];
            $bodyLength = $size - $bodyStart;

            $collected = [
                "text"        => "",
                "html"        => "",
                "attachments" => []
            ];

            # 递归解析正文结构
            $this->parsePart($headers, $bodyStart, $bodyLength, $collected, 0);

            fclose($this->fp);

            return [
                "ok"          => true,
                "headers"     => $headers,
                "subject"     => self::decodeHeaderValue(self::headerValue($headers, "subject")),
                "from"        => MailAddress::parseOne(self::decodeHeaderValue(self::headerValue($headers, "from"))),
                "to"          => MailAddress::parseList(self::decodeHeaderValue(self::headerValue($headers, "to"))),
                "cc"          => MailAddress::parseList(self::decodeHeaderValue(self::headerValue($headers, "cc"))),
                "date"        => self::parseDate(self::headerValue($headers, "date")),
                "message_id"  => trim(self::headerValue($headers, "message-id"), " <>"),
                "in_reply_to" => trim(self::headerValue($headers, "in-reply-to"), " <>"),
                "references"  => trim(self::headerValue($headers, "references")),
                "text"        => $collected["text"],
                "html"        => $collected["html"],
                "attachments" => $collected["attachments"]
            ];
        } catch (Exception $ex) {
            if (is_resource($this->fp)) {
                fclose($this->fp);
            }

            return ["ok" => false, "error" => "parse failed: " . $ex->getMessage()];
        }
    }

    /**
     * 从指定偏移读取一段信头（直到空行为止）
     *
     * @param integer $offset 起始偏移
     * @return array{text:string, end:int} text 为信头原文，end 为正文起始偏移
     */
    private function readHeaderBlock($offset)
    {
        fseek($this->fp, $offset);

        $lines = [];

        while (!feof($this->fp)) {
            $line = fgets($this->fp);

            if ($line === false) {
                break;
            }

            # 空行标志信头结束
            if (rtrim($line, "\r\n") === "") {
                break;
            }

            $lines[] = rtrim($line, "\r\n");

            # 防御：单封邮件的信头行数异常多时中止，避免恶意报文耗尽内存
            if (count($lines) > 2000) {
                break;
            }
        }

        return [
            "text" => implode("\r\n", $lines),
            "end"  => ftell($this->fp)
        ];
    }

    /**
     * 解析信头文本为关联数组
     *
     * 处理折行续行（RFC 5322 folding）：
     * 以空格或制表符开头的行是上一行的延续。
     *
     * @param string $text
     * @return array 键为小写字段名，值为字符串或字符串数组（同名字段多次出现时）
     */
    public static function parseHeaders($text)
    {
        $headers = [];
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $current = null;
        $buffer = "";

        foreach ($lines as $line) {
            if ($line === "") {
                continue;
            }

            # 折行续行：以空白字符开头，属于上一个字段
            if (($line[0] === " " || $line[0] === "\t") && $current !== null) {
                $buffer .= " " . trim($line);
                continue;
            }

            # 保存上一个字段
            if ($current !== null) {
                self::pushHeader($headers, $current, $buffer);
            }

            $pos = strpos($line, ":");

            if ($pos === false) {
                # 不含冒号的行不是合法字段，忽略
                $current = null;
                $buffer = "";
                continue;
            }

            $current = strtolower(trim(substr($line, 0, $pos)));
            $buffer = trim(substr($line, $pos + 1));
        }

        if ($current !== null) {
            self::pushHeader($headers, $current, $buffer);
        }

        return $headers;
    }

    /**
     * 写入信头字段，同名字段自动转为数组
     *
     * @param array $headers
     * @param string $key
     * @param string $value
     * @return void
     */
    private static function pushHeader(&$headers, $key, $value)
    {
        if (!isset($headers[$key])) {
            $headers[$key] = $value;
            return;
        }

        if (is_array($headers[$key])) {
            $headers[$key][] = $value;
        } else {
            $headers[$key] = [$headers[$key], $value];
        }
    }

    /**
     * 取得信头字段值（同名多次出现时取第一个）
     *
     * @param array $headers
     * @param string $key
     * @param string $default
     * @return string
     */
    public static function headerValue($headers, $key, $default = "")
    {
        $key = strtolower($key);

        if (!isset($headers[$key])) {
            return $default;
        }

        $v = $headers[$key];

        return is_array($v) ? (string) $v[0] : (string) $v;
    }

    /**
     * 递归解析一个 MIME 分段
     *
     * @param array $headers 本分段的信头
     * @param integer $offset 本分段正文在文件中的起始偏移
     * @param integer $length 本分段正文的字节长度
     * @param array $collected 输出：累积的正文与附件
     * @param integer $depth 递归深度，用于防御恶意的深层嵌套
     * @return void
     */
    private function parsePart($headers, $offset, $length, &$collected, $depth)
    {
        # 防御「MIME 炸弹」：限制嵌套层数
        if ($depth > 20 || $length <= 0) {
            return;
        }

        $contentType = self::parseContentType(self::headerValue($headers, "content-type", "text/plain"));
        $mime = $contentType["type"];
        $encoding = strtolower(trim(self::headerValue($headers, "content-transfer-encoding", "7bit")));
        $disposition = self::parseContentType(self::headerValue($headers, "content-disposition", ""));

        # ---- multipart：递归切分子分段 ----
        if (strpos($mime, "multipart/") === 0) {
            $boundary = isset($contentType["params"]["boundary"])
                ? $contentType["params"]["boundary"]
                : "";

            if ($boundary === "") {
                return;
            }

            $parts = $this->splitByBoundary($offset, $length, $boundary);

            foreach ($parts as $part) {
                # 每个子分段自身又有信头 + 正文
                $subHeaderBlock = $this->readHeaderBlock($part["start"]);
                $subHeaders = self::parseHeaders($subHeaderBlock["text"]);
                $subBodyStart = $subHeaderBlock["end"];
                $subBodyLength = $part["end"] - $subBodyStart;

                if ($subBodyLength > 0) {
                    $this->parsePart($subHeaders, $subBodyStart, $subBodyLength, $collected, $depth + 1);
                }
            }

            return;
        }

        # ---- 判定是否为附件 ----
        $filename = self::extractFilename($contentType, $disposition, $headers);
        $isAttachment = ($disposition["type"] === "attachment")
            || ($filename !== "")
            || ($disposition["type"] === "inline" && strpos($mime, "text/") !== 0);

        if ($isAttachment) {
            $contentId = trim(self::headerValue($headers, "content-id"), " <>");
            $isInline = ($disposition["type"] === "inline") || $contentId !== "";

            # 附件分块流式解码落盘，内存占用恒定
            $tempFile = $this->extractToFile($offset, $length, $encoding);

            if ($tempFile !== false) {
                if ($filename === "") {
                    $filename = "attachment-" . (count($collected["attachments"]) + 1);
                }

                $collected["attachments"][] = [
                    "filename"   => $filename,
                    "mime_type"  => $mime,
                    "temp_path"  => $tempFile,
                    "content_id" => $contentId,
                    "is_inline"  => $isInline
                ];
            }

            return;
        }

        # ---- 正文分段 ----
        if (strpos($mime, "text/") === 0) {
            $charset = isset($contentType["params"]["charset"])
                ? $contentType["params"]["charset"]
                : "utf-8";

            # 正文体积上限保护：超大「正文」按附件处理更合理，
            # 这里直接截断，避免把数百 MB 文本读进内存
            $maxBody = 5 * 1024 * 1024;
            $readLength = $length > $maxBody ? $maxBody : $length;

            $raw = $this->readRange($offset, $readLength);
            $decoded = self::decodeContent($raw, $encoding);
            $decoded = self::toUtf8($decoded, $charset);

            if ($mime === "text/html") {
                $collected["html"] .= $decoded;
            } else {
                $collected["text"] .= $decoded;
            }
        }
    }

    /**
     * 按 boundary 切分 multipart 正文，返回各子分段的字节区间
     *
     * 逐行扫描并记录偏移，不把内容读入内存。
     *
     * @param integer $offset
     * @param integer $length
     * @param string $boundary
     * @return array 每项为 ["start" => int, "end" => int]
     */
    private function splitByBoundary($offset, $length, $boundary)
    {
        $delimiter = "--" . $boundary;
        $terminator = "--" . $boundary . "--";
        $limit = $offset + $length;

        fseek($this->fp, $offset);

        $parts = [];
        $currentStart = null;

        while (ftell($this->fp) < $limit && !feof($this->fp)) {
            $lineStart = ftell($this->fp);
            $line = fgets($this->fp);

            if ($line === false) {
                break;
            }

            $trimmed = rtrim($line, "\r\n");

            if ($trimmed === $terminator) {
                # 结束标记：闭合最后一个分段
                if ($currentStart !== null) {
                    $parts[] = ["start" => $currentStart, "end" => $lineStart];
                    $currentStart = null;
                }
                break;
            }

            if ($trimmed === $delimiter) {
                # 分隔标记：闭合上一个分段，并开启下一个
                if ($currentStart !== null) {
                    $parts[] = ["start" => $currentStart, "end" => $lineStart];
                }
                $currentStart = ftell($this->fp);
            }
        }

        # 没有遇到结束标记时，用区间末尾闭合
        if ($currentStart !== null && $currentStart < $limit) {
            $parts[] = ["start" => $currentStart, "end" => $limit];
        }

        return $parts;
    }

    /**
     * 读取指定字节区间的原始内容
     *
     * @param integer $offset
     * @param integer $length
     * @return string
     */
    private function readRange($offset, $length)
    {
        if ($length <= 0) {
            return "";
        }

        fseek($this->fp, $offset);

        $data = "";
        $remaining = $length;

        while ($remaining > 0 && !feof($this->fp)) {
            $chunk = fread($this->fp, $remaining > 65536 ? 65536 : $remaining);

            if ($chunk === false || $chunk === "") {
                break;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * 把一个分段的内容分块解码并写入临时文件
     *
     * Base64 解码按 4 字符对齐分块处理：Base64 每 4 个字符对应 3 字节，
     * 只要保证每次送入 base64_decode 的长度是 4 的倍数，
     * 就可以分块解码而不破坏数据。
     *
     * @param integer $offset
     * @param integer $length
     * @param string $encoding
     * @return string|false 临时文件路径，失败返回 false
     */
    private function extractToFile($offset, $length, $encoding)
    {
        $tmpDir = AttachmentService::tmpRoot() . "/mime";

        if (!ensure_dir($tmpDir)) {
            return false;
        }

        $tempFile = $tmpDir . "/" . uuid_v4() . ".bin";
        $out = @fopen($tempFile, "wb");

        if ($out === false) {
            return false;
        }

        fseek($this->fp, $offset);

        $encoding = strtolower(trim($encoding));
        $remaining = $length;
        # Base64 累积缓冲：保存不足 4 字符对齐的尾部
        $carry = "";

        while ($remaining > 0 && !feof($this->fp)) {
            $read = $remaining > 262144 ? 262144 : $remaining;
            $chunk = fread($this->fp, $read);

            if ($chunk === false || $chunk === "") {
                break;
            }

            $remaining -= strlen($chunk);

            if ($encoding === "base64") {
                # 剔除所有空白字符（Base64 正文按 76 字符折行）
                $clean = preg_replace('/[^A-Za-z0-9+\/=]/', "", $chunk);
                $carry .= $clean;

                # 只解码 4 的整数倍长度，余数留到下一轮
                $usable = strlen($carry) - (strlen($carry) % 4);

                if ($usable > 0) {
                    $decodable = substr($carry, 0, $usable);
                    $carry = substr($carry, $usable);
                    fwrite($out, base64_decode($decodable));
                }
            } else if ($encoding === "quoted-printable") {
                # QP 的软换行（行尾 =）可能跨块，保留尾部到下一轮处理
                $carry .= $chunk;
                # 在最后一个换行处切分，保证不截断编码序列
                $lastBreak = strrpos($carry, "\n");

                if ($lastBreak !== false) {
                    $decodable = substr($carry, 0, $lastBreak + 1);
                    $carry = substr($carry, $lastBreak + 1);
                    fwrite($out, quoted_printable_decode($decodable));
                }
            } else {
                # 7bit / 8bit / binary：原样写出
                fwrite($out, $chunk);
            }
        }

        # 处理残留缓冲
        if ($carry !== "") {
            if ($encoding === "base64") {
                fwrite($out, base64_decode($carry));
            } else if ($encoding === "quoted-printable") {
                fwrite($out, quoted_printable_decode($carry));
            } else {
                fwrite($out, $carry);
            }
        }

        fclose($out);

        return $tempFile;
    }

    // =================================================================
    // 静态解码工具
    // =================================================================

    /**
     * 解析 Content-Type / Content-Disposition 这类带参数的字段
     *
     * 例：multipart/mixed; boundary="----=_Part_1"
     *
     * @param string $value
     * @return array{type:string, params:array}
     */
    public static function parseContentType($value)
    {
        $value = trim((string) $value);

        if ($value === "") {
            return ["type" => "", "params" => []];
        }

        $parts = self::splitSemicolon($value);
        $type = strtolower(trim(array_shift($parts)));
        $params = [];

        foreach ($parts as $p) {
            $pos = strpos($p, "=");

            if ($pos === false) {
                continue;
            }

            $k = strtolower(trim(substr($p, 0, $pos)));
            $v = trim(substr($p, $pos + 1));
            $v = trim($v, " \t\"'");

            $params[$k] = $v;
        }

        return ["type" => $type, "params" => $params];
    }

    /**
     * 按分号切分，但忽略引号内的分号
     *
     * @param string $text
     * @return string[]
     */
    private static function splitSemicolon($text)
    {
        $parts = [];
        $buffer = "";
        $inQuote = false;
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];

            if ($ch === '"') {
                $inQuote = !$inQuote;
                $buffer .= $ch;
            } else if ($ch === ";" && !$inQuote) {
                $parts[] = $buffer;
                $buffer = "";
            } else {
                $buffer .= $ch;
            }
        }

        if (trim($buffer) !== "") {
            $parts[] = $buffer;
        }

        return $parts;
    }

    /**
     * 从各类字段中提取附件文件名
     *
     * 依次尝试：Content-Disposition 的 filename*、filename，
     * 再退回 Content-Type 的 name。
     *
     * @param array $contentType
     * @param array $disposition
     * @param array $headers
     * @return string
     */
    private static function extractFilename($contentType, $disposition, $headers)
    {
        # RFC 5987 编码形式优先（filename*=UTF-8''%E4%B8%AD%E6%96%87.txt）
        if (isset($disposition["params"]["filename*"])) {
            $decoded = self::decodeExtendedParam($disposition["params"]["filename*"]);

            if ($decoded !== "") {
                return $decoded;
            }
        }

        if (isset($disposition["params"]["filename"])) {
            return self::decodeHeaderValue($disposition["params"]["filename"]);
        }

        if (isset($contentType["params"]["name*"])) {
            $decoded = self::decodeExtendedParam($contentType["params"]["name*"]);

            if ($decoded !== "") {
                return $decoded;
            }
        }

        if (isset($contentType["params"]["name"])) {
            return self::decodeHeaderValue($contentType["params"]["name"]);
        }

        return "";
    }

    /**
     * 解码 RFC 5987 扩展参数（charset'lang'percent-encoded）
     *
     * @param string $value
     * @return string
     */
    public static function decodeExtendedParam($value)
    {
        $value = trim((string) $value, " \t\"'");
        $parts = explode("'", $value, 3);

        if (count($parts) < 3) {
            return rawurldecode($value);
        }

        $charset = $parts[0];
        $text = rawurldecode($parts[2]);

        return self::toUtf8($text, $charset);
    }

    /**
     * 解码信头中的编码字（RFC 2047）
     *
     * 形如 =?UTF-8?B?5Lit5paH?= 或 =?GBK?Q?=D6=D0=CE=C4?=
     * 一个字段中可能出现多个编码字，且与明文混排。
     *
     * @param string $text
     * @return string UTF-8 文本
     */
    public static function decodeHeaderValue($text)
    {
        $text = (string) $text;

        if ($text === "" || strpos($text, "=?") === false) {
            return trim($text);
        }

        # 匹配所有编码字
        $pattern = '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/';

        $result = preg_replace_callback($pattern, function ($m) {
            $charset = $m[1];
            $scheme = strtoupper($m[2]);
            $data = $m[3];

            if ($scheme === "B") {
                $decoded = base64_decode($data);
            } else {
                # Q 编码：下划线代表空格，其余同 quoted-printable
                $decoded = quoted_printable_decode(str_replace("_", " ", $data));
            }

            return MimeParser::toUtf8($decoded, $charset);
        }, $text);

        # 相邻编码字之间的空白按规范应当移除
        $result = preg_replace('/\?=\s+=\?/', "?==?", $result);

        return trim($result);
    }

    /**
     * 将任意字符集的文本转换为 UTF-8
     *
     * @param string $text
     * @param string $charset 源字符集
     * @return string
     */
    public static function toUtf8($text, $charset)
    {
        $charset = strtoupper(trim((string) $charset));

        if ($text === "") {
            return "";
        }

        # 剥离可能存在的引号与多余参数
        $charset = trim($charset, " \t\"'");

        if ($charset === "" || $charset === "UTF-8" || $charset === "UTF8" || $charset === "US-ASCII") {
            # 已是 UTF-8：仍需校验合法性，非法字节会导致 json_encode 失败
            if ($charset === "UTF-8" || $charset === "UTF8") {
                return self::ensureValidUtf8($text);
            }
            return self::ensureValidUtf8($text);
        }

        # GB2312 实际使用中多为 GBK 的子集，统一按 GBK 处理以避免转换失败
        if ($charset === "GB2312" || $charset === "GB_2312-80") {
            $charset = "GBK";
        }

        $converted = false;

        if (function_exists("mb_convert_encoding")) {
            $supported = array_map("strtoupper", mb_list_encodings());

            if (in_array($charset, $supported, true)) {
                $converted = @mb_convert_encoding($text, "UTF-8", $charset);
            }
        }

        if ($converted === false && function_exists("iconv")) {
            # //IGNORE 跳过无法转换的字符，避免整体转换失败返回空串
            $converted = @iconv($charset, "UTF-8//IGNORE", $text);
        }

        if ($converted === false) {
            return self::ensureValidUtf8($text);
        }

        return self::ensureValidUtf8($converted);
    }

    /**
     * 确保字符串是合法 UTF-8
     *
     * 非法字节序列会导致 json_encode 静默返回 false，
     * 进而使整个 API 响应变成空内容，因此必须清洗。
     *
     * @param string $text
     * @return string
     */
    public static function ensureValidUtf8($text)
    {
        if ($text === "") {
            return "";
        }

        if (function_exists("mb_check_encoding") && mb_check_encoding($text, "UTF-8")) {
            return $text;
        }

        if (function_exists("mb_convert_encoding")) {
            # 以 UTF-8 为源做一次转换，非法字节会被替换掉
            return mb_convert_encoding($text, "UTF-8", "UTF-8");
        }

        if (function_exists("iconv")) {
            $r = @iconv("UTF-8", "UTF-8//IGNORE", $text);
            return $r === false ? "" : $r;
        }

        return $text;
    }

    /**
     * 按传输编码解码内容
     *
     * @param string $data
     * @param string $encoding
     * @return string
     */
    public static function decodeContent($data, $encoding)
    {
        $encoding = strtolower(trim((string) $encoding));

        switch ($encoding) {
            case "base64":
                return base64_decode($data);
            case "quoted-printable":
                return quoted_printable_decode($data);
            default:
                # 7bit / 8bit / binary 原样返回
                return $data;
        }
    }

    /**
     * 解析信头 Date 字段为 MySQL DATETIME
     *
     * @param string $value
     * @return string 解析失败时返回当前时间
     */
    public static function parseDate($value)
    {
        $value = trim((string) $value);

        if ($value !== "") {
            $ts = strtotime($value);

            if ($ts !== false && $ts > 0) {
                return date("Y-m-d H:i:s", $ts);
            }
        }

        return now_time();
    }

    /**
     * 从正文生成摘要片段（用于列表展示）
     *
     * @param string $text 纯文本正文
     * @param string $html HTML 正文（纯文本为空时从此提取）
     * @param integer $limit 摘要长度上限
     * @return string
     */
    public static function makeSummary($text, $html, $limit = 200)
    {
        $source = trim((string) $text);

        if ($source === "" && $html !== "") {
            # 去掉脚本与样式再剥离标签，避免把 CSS/JS 混进摘要
            $clean = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', " ", $html);
            $source = strip_tags($clean);
            $source = html_entity_decode($source, ENT_QUOTES, "UTF-8");
        }

        # 压缩连续空白
        $source = preg_replace('/\s+/u', " ", $source);
        $source = trim($source);

        if (mb_strlen($source, "UTF-8") > $limit) {
            $source = mb_substr($source, 0, $limit, "UTF-8") . "...";
        }

        return $source;
    }
}
