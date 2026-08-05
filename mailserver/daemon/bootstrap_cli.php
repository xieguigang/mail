<?php
/**
 * bootstrap_cli.php —— 命令行守护进程引导脚本
 *
 * 与 Web 侧的 bootstrap.php 加载同一套 .etc 配置与 scripts 领域服务层，
 * 区别在于不加载控制器、不启动 session、不分发 HTTP 请求。
 *
 * 框架的 package.php 内建了 IS_CLI 常量，并在 CLI 模式下
 * 把 SITE_PATH 回退为 getcwd()，因此同一套加载逻辑可安全复用。
 */

# 拒绝从 Web 访问守护进程脚本
if (PHP_SAPI !== "cli") {
    header("HTTP/1.1 403 Forbidden");
    exit("This script can only be executed from the command line.\n");
}

# 项目根目录（daemon 的上一级）
define("APP_PATH", dirname(__DIR__));
define("PHP_DOTNET_HOME", dirname(APP_PATH) . "/framework/php.NET");

if (!defined("APP_DEBUG")) {
    define("APP_DEBUG", false);
}

# 守护进程为常驻运行，必须取消脚本执行时间限制
set_time_limit(0);
# 关闭输出缓冲，让日志能实时输出到控制台
ob_implicit_flush(true);

# 加载框架运行时
include PHP_DOTNET_HOME . "/package.php";

# 站点公共函数（mail_log、now_time、uuid_v4 等）
include APP_PATH . "/.etc/registry.php";

# 领域服务层
require_once APP_PATH . "/scripts/loader.php";

# 读取配置并初始化数据库连接
dotnet::AutoLoad(APP_PATH . "/.etc/config.ini.php");

/**
 * 输出一行带时间戳的控制台日志
 *
 * @param string $message
 * @return void
 */
function cli_echo($message)
{
    echo "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL;
}

/**
 * 检查数据库连接是否可用，断开则重连
 *
 * 守护进程生命周期很长，MySQL 会在 wait_timeout（默认 8 小时）后
 * 主动断开空闲连接，因此每轮循环前都需要探测并按需重连。
 *
 * @return boolean
 */
function ensure_database()
{
    try {
        # 用一次极轻量的查询探测连接是否存活
        $probe = new Table(USERS_TABLE);
        $probe->limit(1)->ExecuteScalar("count(`id`)");

        return true;
    } catch (Exception $ex) {
        cli_echo("database connection lost, reconnecting: " . $ex->getMessage());

        try {
            # 重新读取配置以重建连接
            dotnet::AutoLoad(APP_PATH . "/.etc/config.ini.php");
            return true;
        } catch (Exception $ex2) {
            cli_echo("reconnect failed: " . $ex2->getMessage());
            return false;
        }
    }
}
