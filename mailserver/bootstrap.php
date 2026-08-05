<?php
/**
 * bootstrap.php —— 邮件服务器引导脚本
 *
 * 职责：定义路径常量 → 启动 session → 加载框架 → 加载站点脚本
 *      → 读取注册表配置 → 把请求分发到控制器。
 *
 * 本文件被两个 Web 入口共享：
 *   - index.php  公开入口（注册 / 登录 / 探活）
 *   - api.php    业务入口（其余全部需鉴权接口）
 *
 * 同时 daemon/ 下的 CLI 守护进程复用 bootstrap_cli.php，
 * 两者共享 .etc 配置与 scripts 领域服务层。
 */

# =====================================================================
# 第一步：路径常量（必须在加载框架之前定义）
# =====================================================================

define("APP_PATH", __DIR__);

# 框架根目录：本项目将框架放在与项目平级的 framework/php.NET 下
define("PHP_DOTNET_HOME", dirname(APP_PATH) . "/framework/php.NET");

# =====================================================================
# 第二步：调试开关 —— 必须放在 include package.php 之前
# =====================================================================

# 生产环境必须为 false，否则会泄露服务器内部信息并拖慢性能
if (!defined("APP_DEBUG")) {
    define("APP_DEBUG", false);
}

# 维护模式：true 时访问控制器会拦下除探活外的所有请求
if (!defined("MAINTENANCE_MODE")) {
    define("MAINTENANCE_MODE", false);
}

# =====================================================================
# 第三步：启动会话（框架自 20191205 版本起不再自动管理 session）
# =====================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

# =====================================================================
# 第四步：加载框架运行时
# =====================================================================

include PHP_DOTNET_HOME . "/package.php";

# =====================================================================
# 第五步：加载站点级脚本
# =====================================================================

# 访问控制器：必须在 HandleRequest 之前加载
include APP_PATH . "/.etc/access.php";
# 站点公共函数
include APP_PATH . "/.etc/registry.php";

# 领域服务层（Web 与 CLI 共享）
require_once APP_PATH . "/scripts/loader.php";

# 控制器层
include APP_PATH . "/src/index.php";
include APP_PATH . "/src/mail.php";
include APP_PATH . "/src/folder.php";
include APP_PATH . "/src/thread.php";
include APP_PATH . "/src/search.php";
include APP_PATH . "/src/attachment.php";

# =====================================================================
# 第六步：读取注册表配置并分发请求
# =====================================================================

dotnet::AutoLoad(APP_PATH . "/.etc/config.ini.php");

# 依据入口脚本名选择要实例化的控制器类。
# 框架的 Router 会用 ?app=方法名 在该类中定位控制器方法。
$entry = basename($_SERVER["SCRIPT_FILENAME"], ".php");

switch ($entry) {
    case "api":
        # 业务入口：由 URL 的 ctl 参数决定挂载哪一个业务控制器类
        $ctl = isset($_GET["ctl"]) ? strtolower(trim($_GET["ctl"])) : "mail";
        $map = [
            "mail"       => "MailApp",
            "folder"     => "FolderApp",
            "thread"     => "ThreadApp",
            "search"     => "SearchApp",
            "attachment" => "AttachmentApp"
        ];

        if (!array_key_exists($ctl, $map)) {
            header("Content-Type: application/json");
            echo json_encode([
                "code" => 404,
                "info" => "unknown controller: " . htmlspecialchars($ctl)
            ]);
            exit(404);
        }

        $className = $map[$ctl];
        $app = new $className();
        break;

    default:
        # 公开入口
        $app = new App();
        break;
}

# 重要：只有传入第二个参数（访问控制器实例），
# @access / @require / @method / @rate 等元数据标签才会真正生效。
dotnet::HandleRequest($app, new accessController());
