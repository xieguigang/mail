<?php
/**
 * access.php —— 访问控制器
 *
 * 执行时序（框架文档）：
 *   Hook($app) → doValidation()（IP → 方法 → 必需参数）
 *              → accessControl() → Restrictions() → sendContentType()
 *              → 控制器方法执行
 *
 * 本控制器承担三件事：
 *   1) 双认证模式统一化：Token（Authorization 头 / token 参数）优先，其次 Session。
 *      任一成功即固化当前用户上下文，后续领域服务不再关心认证来源。
 *   2) 频率限制：基于 @rate 标签做计数拦截，用于防登录暴力破解。
 *   3) 错误响应 JSON 化：纯 API 服务不返回 HTML 错误页。
 */

imports("MVC.controller");

class accessController extends controller
{
    function __construct()
    {
        parent::__construct();
    }

    /**
     * 权限校验总入口
     *
     * 返回 true 放行；返回 false 框架会自动调用 Redirect(403)。
     *
     * @return boolean
     */
    public function accessControl()
    {
        # 维护模式：除探活接口外一律拦下
        if (defined("MAINTENANCE_MODE") && MAINTENANCE_MODE && $this->ref !== "index/health") {
            return false;
        }

        # 无论接口是否公开，都先尝试解析身份。
        # 这样公开接口（如 login）也能在用户已携带有效凭证时拿到上下文，
        # 同时不会因为解析失败而拒绝访问。
        $user = $this->resolveIdentity();

        if ($user) {
            set_current_user($user);
        }

        # 标注了 @access * 的公开接口：直接放行
        if ($this->AccessByEveryOne()) {
            return true;
        }

        # 非公开接口：必须解析出有效用户
        if (!$user) {
            return false;
        }

        # 账号被停用则拒绝访问
        if ((int) $user["status"] !== 1) {
            return false;
        }

        # 角色校验：@access 声明了具体角色组时，要求用户角色匹配
        $level = $this->getAccessLevel();

        if (!empty($level) && $level !== "*") {
            $allowed = array_map("trim", explode("|", $level));
            $role = isset($user["role"]) ? $user["role"] : "user";

            if (!in_array($role, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 统一身份解析：Token 优先，其次 Session
     *
     * Token 采用「随机串 + 服务端存储」而非自包含签名令牌，
     * 以支持主动吊销与「登出即失效」。
     *
     * @return array|null 解析成功返回用户记录，否则 null
     */
    private function resolveIdentity()
    {
        # ---- 方式一：Token ----
        $token = $this->extractToken();

        if (!empty($token)) {
            $user = TokenService::resolve($token);

            if ($user) {
                return $user;
            }

            # Token 存在但无效（过期或已吊销）：不再回退到 Session，
            # 避免客户端因携带过期 Token 而静默地以其他身份操作
            return null;
        }

        # ---- 方式二：Session ----
        if (isset($_SESSION["mail_user_id"])) {
            $uid = (int) $_SESSION["mail_user_id"];

            if ($uid > 0) {
                $user = UserService::findById($uid);

                if ($user) {
                    return $user;
                }

                # Session 中的用户已不存在，清除脏数据
                unset($_SESSION["mail_user_id"]);
            }
        }

        return null;
    }

    /**
     * 从请求中提取 Token
     *
     * 支持三种携带方式：
     *   1) Authorization: Bearer <token>   （推荐）
     *   2) X-Auth-Token: <token>
     *   3) URL/表单参数 token=<token>      （兼容不便设置请求头的客户端）
     *
     * @return string|null
     */
    private function extractToken()
    {
        $header = null;

        # 部分 SAPI 下 Authorization 头不会进入 $_SERVER，需多路探测
        if (isset($_SERVER["HTTP_AUTHORIZATION"])) {
            $header = $_SERVER["HTTP_AUTHORIZATION"];
        } else if (isset($_SERVER["REDIRECT_HTTP_AUTHORIZATION"])) {
            $header = $_SERVER["REDIRECT_HTTP_AUTHORIZATION"];
        } else if (function_exists("apache_request_headers")) {
            $headers = apache_request_headers();

            foreach ($headers as $k => $v) {
                if (strcasecmp($k, "Authorization") === 0) {
                    $header = $v;
                    break;
                }
            }
        }

        if (!empty($header) && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return $m[1];
        }

        if (isset($_SERVER["HTTP_X_AUTH_TOKEN"]) && trim($_SERVER["HTTP_X_AUTH_TOKEN"]) !== "") {
            return trim($_SERVER["HTTP_X_AUTH_TOKEN"]);
        }

        $param = WebRequest::get("token", null);

        if (!empty($param)) {
            return trim($param);
        }

        return null;
    }

    /**
     * 频率限制
     *
     * 返回 true 表示已超配额（触发 429）；false 表示放行。
     *
     * 计数维度为「客户端 IP + 接口标识」，存放于 session。
     * 主要用途是防止登录接口被暴力破解。
     *
     * @return boolean
     */
    public function Restrictions()
    {
        if (!$this->HasRateLimits()) {
            return false;
        }

        $limits = $this->getRateLimits();

        if (empty($limits)) {
            return false;
        }

        $quota = $this->parseRateQuota($limits);

        if ($quota === null) {
            return false;
        }

        $ip = Utils::UserIPAddress();
        $key = "rate_" . md5($ip . "|" . $this->ref);
        $now = time();

        # 取出当前窗口的计数状态，窗口过期则重置
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])
            || $_SESSION[$key]["expires"] <= $now) {
            $_SESSION[$key] = [
                "count"   => 0,
                "expires" => $now + $quota["window"]
            ];
        }

        $_SESSION[$key]["count"] += 1;

        return $_SESSION[$key]["count"] > $quota["limit"];
    }

    /**
     * 解析 @rate 标签，取出「每分钟」这一档配额
     *
     * 支持写法如：30/min,500/hour,2000/day
     * 优先取 min 档；无 min 档时依次回退 hour、day。
     *
     * @param string|array $limits
     * @return array{limit:int, window:int}|null
     */
    private function parseRateQuota($limits)
    {
        # getRateLimits() 可能返回字符串或已解析的数组，统一转为字符串处理
        if (is_array($limits)) {
            $text = implode(",", array_map(function ($k, $v) {
                return is_int($k) ? $v : $v . "/" . $k;
            }, array_keys($limits), array_values($limits)));
        } else {
            $text = (string) $limits;
        }

        $windows = ["min" => 60, "hour" => 3600, "day" => 86400];
        $found = [];

        foreach (explode(",", $text) as $item) {
            $item = trim($item);

            if ($item === "" || strpos($item, "/") === false) {
                continue;
            }

            list($num, $unit) = explode("/", $item, 2);
            $unit = strtolower(trim($unit));
            $num = (int) trim($num);

            if ($num > 0 && isset($windows[$unit])) {
                $found[$unit] = ["limit" => $num, "window" => $windows[$unit]];
            }
        }

        foreach (["min", "hour", "day"] as $unit) {
            if (isset($found[$unit])) {
                return $found[$unit];
            }
        }

        return null;
    }

    /**
     * 自定义错误响应
     *
     * 纯 API 服务统一以 JSON 返回错误，而非渲染 HTML 错误页。
     * controller::error() 内部会 exit，因此无需额外终止。
     *
     * @param integer $code
     * @return void
     */
    public function Redirect($code)
    {
        switch ($code) {
            case 400:
                controller::error("bad request: missing or invalid arguments", 400);
                break;
            case 403:
                controller::error("access denied: valid credentials required", 403);
                break;
            case 405:
                controller::error("method not allowed", 405);
                break;
            case 429:
                # 框架会自动附加 Retry-After 响应头
                controller::error("too many requests, please retry later", 429);
                break;
            default:
                controller::error("request rejected with status " . $code, $code);
        }
    }

    /**
     * 自定义 404 行为
     *
     * 框架文档要求：重写此函数后必须终止脚本执行，
     * 否则后续的反射调用会报错。controller::error() 内部已 exit。
     *
     * @return void
     */
    public function handleNotFound()
    {
        controller::error("api endpoint not found", 404);
    }
}
