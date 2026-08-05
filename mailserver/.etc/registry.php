<?php
/**
 * registry.php —— 站点公共函数
 *
 * 定义全局辅助函数：当前用户上下文、JSON 请求体解析、
 * UUID 生成、统一分页参数读取等。
 *
 * 当前用户上下文由访问控制器 accessControl() 在鉴权成功后固化，
 * 后续所有控制器与领域服务只依赖 current_user()，不再关心认证来源
 * （Token 还是 Session）。
 */

# 当前请求的用户上下文。由 set_current_user() 写入，current_user() 读取。
$GLOBALS["__MAIL_CURRENT_USER"] = null;

/**
 * 固化当前请求的用户上下文（仅由访问控制器调用）
 *
 * @param array|null $user 用户记录数组
 * @return void
 */
function set_current_user($user)
{
    $GLOBALS["__MAIL_CURRENT_USER"] = $user;
}

/**
 * 取得当前登录用户
 *
 * @return array|null 未登录返回 null
 */
function current_user()
{
    return $GLOBALS["__MAIL_CURRENT_USER"];
}

/**
 * 取得当前登录用户的 id
 *
 * @return integer 未登录返回 0
 */
function current_user_id()
{
    $u = current_user();
    return $u ? (int) $u["id"] : 0;
}

/**
 * 要求必须已登录，否则以 JSON 返回 403 并终止脚本
 *
 * 正常情况下访问控制器已经拦截，此函数是领域层的第二道防线。
 *
 * @return array 当前用户
 */
function require_login()
{
    $u = current_user();

    if (!$u) {
        controller::error("authentication required", 403);
    }

    return $u;
}

/**
 * 解析 JSON 请求体
 *
 * 客户端以 Content-Type: application/json 提交时，数据不在 $_POST 中，
 * 需要从 php://input 读取。结果做静态缓存，避免重复读取输入流
 * （php://input 在部分 SAPI 下不可重复读）。
 *
 * @return array 解析失败或非 JSON 请求返回空数组
 */
function json_body()
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : "";

    if (stripos($contentType, "application/json") === false) {
        $cached = [];
        return $cached;
    }

    $raw = file_get_contents("php://input");

    if ($raw === false || trim($raw) === "") {
        $cached = [];
        return $cached;
    }

    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : [];

    return $cached;
}

/**
 * 统一读取请求参数：优先 JSON 请求体，其次 GET/POST
 *
 * 使得同一个接口既能接受表单提交，也能接受 JSON 提交。
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function input($key, $default = null)
{
    $body = json_body();

    if (array_key_exists($key, $body)) {
        return $body[$key];
    }

    $value = WebRequest::get($key, null);

    return $value === null ? $default : $value;
}

/**
 * 读取整数型请求参数
 *
 * @param string $key
 * @param integer $default
 * @return integer
 */
function input_int($key, $default = 0)
{
    $value = input($key, null);

    if ($value === null || $value === "") {
        return $default;
    }

    if (is_numeric($value)) {
        return (int) $value;
    }

    return $default;
}

/**
 * 读取布尔型请求参数，兼容 1/0、true/false、yes/no 等写法
 *
 * @param string $key
 * @param boolean $default
 * @return boolean
 */
function input_bool($key, $default = false)
{
    $value = input($key, null);

    if ($value === null || $value === "") {
        return $default;
    }

    if (is_bool($value)) {
        return $value;
    }

    $s = strtolower(trim((string) $value));

    return in_array($s, ["1", "true", "yes", "on"], true);
}

/**
 * 读取数组型请求参数，兼容 JSON 数组与逗号分隔字符串
 *
 * @param string $key
 * @return array
 */
function input_array($key)
{
    $value = input($key, null);

    if ($value === null || $value === "") {
        return [];
    }

    if (is_array($value)) {
        return $value;
    }

    return array_values(array_filter(array_map("trim", explode(",", (string) $value)), function ($s) {
        return $s !== "";
    }));
}

/**
 * 读取分页参数，并将其约束在合理区间内，防止超大 limit 拖垮数据库
 *
 * @param integer $defaultSize
 * @param integer $maxSize
 * @return array{page:int, size:int, offset:int}
 */
function input_paging($defaultSize = 20, $maxSize = 100)
{
    $page = input_int("page", 1);
    $size = input_int("page_size", $defaultSize);

    if ($page < 1) {
        $page = 1;
    }
    if ($size < 1) {
        $size = $defaultSize;
    }
    if ($size > $maxSize) {
        $size = $maxSize;
    }

    return [
        "page"   => $page,
        "size"   => $size,
        "offset" => ($page - 1) * $size
    ];
}

/**
 * 组装统一的分页返回结构
 *
 * @param array $rows 本页数据
 * @param integer $total 总条数
 * @param array $paging input_paging() 的返回值
 * @return array
 */
function paged_result($rows, $total, $paging)
{
    $total = (int) $total;
    $size = (int) $paging["size"];

    return [
        "page"         => is_array($rows) ? array_values($rows) : [],
        "total"        => $total,
        "total_page"   => $total > 0 ? (int) ceil($total / $size) : 0,
        "current_page" => (int) $paging["page"],
        "page_size"    => $size
    ];
}

/**
 * 生成 UUID v4（用于上传会话标识、Message-ID 等）
 *
 * @return string
 */
function uuid_v4()
{
    $data = random_bytes(16);
    # 设置版本号为 4
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    # 设置变体为 RFC 4122
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * 生成加密安全的随机 token 字符串
 *
 * @param integer $bytes 随机字节数
 * @return string 十六进制字符串
 */
function random_token($bytes = 32)
{
    return bin2hex(random_bytes($bytes));
}

/**
 * 取得当前时间的 MySQL DATETIME 字符串
 *
 * @param integer|null $timestamp 为空则取当前时间
 * @return string
 */
function now_time($timestamp = null)
{
    return date("Y-m-d H:i:s", $timestamp === null ? time() : $timestamp);
}

/**
 * 确保目录存在，不存在则递归创建
 *
 * @param string $dir
 * @return boolean
 */
function ensure_dir($dir)
{
    if (is_dir($dir)) {
        return true;
    }
    # 第三个参数 true 表示递归创建多级目录
    return @mkdir($dir, 0750, true) || is_dir($dir);
}

/**
 * 写入运行日志
 *
 * 严禁记录密码、Token 明文与邮件正文全文。
 *
 * @param string $channel 日志分类（如 smtpd / sender / api）
 * @param string $message
 * @return void
 */
function mail_log($channel, $message)
{
    $dir = DotNetRegistry::Read("LOG_DIR", APP_PATH . "/logs");

    if (!ensure_dir($dir)) {
        return;
    }

    $file = $dir . "/" . preg_replace('/[^a-z0-9_\-]/i', "", $channel) . "-" . date("Ymd") . ".log";
    $line = "[" . now_time() . "] " . str_replace(["\r", "\n"], " ", $message) . PHP_EOL;

    # FILE_APPEND 追加写入，LOCK_EX 加锁避免多进程写入交错
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
