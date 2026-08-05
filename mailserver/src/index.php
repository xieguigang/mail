<?php
/**
 * src/index.php —— 公开接口控制器 App
 *
 * 挂载于 index.php 入口，承载无需登录即可访问的接口：
 * 注册、登录、探活；以及需登录的账号自服务接口：登出、改密、资料。
 *
 * 类的每一个 public 方法映射为一个 Web 接口：
 *   /index.php?app=方法名     →  /api/auth/方法名（重写后）
 *   不带 app 参数时调用 index()
 *
 * 元数据标签只有在 dotnet::HandleRequest() 传入访问控制器实例时才生效，
 * bootstrap.php 已按要求传入第二个参数。
 *
 * 注意：@method 未声明时默认只接受 GET，
 *       所有写操作必须显式声明 @method POST，否则返回 405。
 */

class App
{
    /**
     * 服务信息
     *
     * @uses api
     * @access *
     * @method GET
     * @origin *
     */
    public function index()
    {
        controller::success([
            "service" => DotNetRegistry::Read("APP_TITLE", "PHP Mail Server"),
            "version" => DotNetRegistry::Read("APP_VERSION", "1.0.0"),
            "domains" => MailAddress::localDomains(),
            "endpoints" => [
                "auth"       => "/api/auth/{register|login|logout|profile|password}",
                "mail"       => "/api/mail/{send|get|list|delete|resend|status}",
                "folder"     => "/api/folder/{list|create|rename|remove|move|mark|star|batch}",
                "thread"     => "/api/thread/{list|detail|draft_save|draft_get|draft_send}",
                "search"     => "/api/search/query",
                "attachment" => "/api/attachment/{init|chunk|progress|complete|download|remove}"
            ]
        ]);
    }

    /**
     * 健康探活
     *
     * 返回数据库连通性与队列积压情况，供监控系统轮询。
     *
     * @uses api
     * @access *
     * @method GET
     * @origin *
     */
    public function health()
    {
        $dbOk = false;
        $pending = 0;

        try {
            # 用一次轻量查询探测数据库连通性
            $queue = new Table(SEND_QUEUE_TABLE);
            $pending = (int) $queue->where(["status" => "pending"])->count();
            $dbOk = true;
        } catch (Exception $ex) {
            $dbOk = false;
        }

        controller::success([
            "status"        => $dbOk ? "ok" : "degraded",
            "database"      => $dbOk,
            "queue_pending" => $pending,
            "time"          => now_time()
        ]);
    }

    /**
     * 注册新账号
     *
     * @uses api
     * @access *
     * @method POST
     * @origin *
     * @require email=string|password=string
     * @rate 10/min,50/hour
     */
    public function register()
    {
        $email = input("email", "");
        $password = input("password", "");
        $nickname = input("nickname", "");

        $result = UserService::register($email, $password, $nickname);

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        $user = $result["user"];

        # 注册成功后直接签发令牌，省去客户端再调一次登录
        $token = TokenService::issue($user["id"]);

        if ($token === false) {
            controller::error("account created but failed to issue token", 500);
        }

        controller::success([
            "user"        => UserService::publicView($user),
            "token"       => $token["token"],
            "expire_time" => $token["expire_time"]
        ]);
    }

    /**
     * 登录并换取访问令牌
     *
     * 同时建立 Session 登录态（供网页端）与返回 Token（供 API 客户端），
     * 两种认证方式并存，客户端按需选用。
     *
     * @uses api
     * @access *
     * @method POST
     * @origin *
     * @require email=string|password=string
     * @rate 10/min,100/hour
     */
    public function login()
    {
        $email = input("email", "");
        $password = input("password", "");

        $result = UserService::verifyLogin($email, $password);

        if (!$result["ok"]) {
            # 统一的失败描述，不透露「邮箱不存在」还是「密码错误」，
            # 避免被用于枚举有效邮箱
            controller::error($result["error"], 401);
        }

        $user = $result["user"];

        $token = TokenService::issue($user["id"]);

        if ($token === false) {
            controller::error("failed to issue token", 500);
        }

        # 建立 Session 登录态
        $_SESSION["mail_user_id"] = (int) $user["id"];

        UserService::touchLogin($user["id"]);

        controller::success([
            "user"        => UserService::publicView($user),
            "token"       => $token["token"],
            "expire_time" => $token["expire_time"]
        ]);
    }

    /**
     * 登出：吊销当前令牌并清除 Session
     *
     * @uses api
     * @access *
     * @method POST
     * @origin *
     */
    public function logout()
    {
        # 吊销请求中携带的令牌（若有）
        $token = WebRequest::get("token", null);

        if (empty($token)) {
            $header = isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "";

            if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
                $token = $m[1];
            }
        }

        if (!empty($token)) {
            TokenService::revoke($token);
        }

        # 清除 Session 登录态
        unset($_SESSION["mail_user_id"]);

        controller::success(["message" => "logged out"]);
    }

    /**
     * 查询当前登录用户资料
     *
     * @uses api
     * @access user|admin
     * @method GET
     * @origin *
     */
    public function profile()
    {
        $user = require_login();

        controller::success(UserService::publicView($user));
    }

    /**
     * 修改显示名
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require nickname=string
     */
    public function update_profile()
    {
        $user = require_login();
        $nickname = input("nickname", "");

        if (!UserService::updateProfile($user["id"], $nickname)) {
            controller::error("invalid nickname", 400);
        }

        controller::success(UserService::publicView(UserService::findById($user["id"])));
    }

    /**
     * 修改密码
     *
     * 成功后会吊销该用户的全部令牌，所有客户端需重新登录。
     *
     * @uses api
     * @access user|admin
     * @method POST
     * @origin *
     * @require old_password=string|new_password=string
     * @rate 5/min,20/hour
     */
    public function password()
    {
        $user = require_login();

        $result = UserService::changePassword(
            $user["id"],
            input("old_password", ""),
            input("new_password", "")
        );

        if (!$result["ok"]) {
            controller::error($result["error"], 400);
        }

        # 密码已变更，同时清除当前 Session
        unset($_SESSION["mail_user_id"]);

        controller::success([
            "message" => "password updated, please login again"
        ]);
    }
}
