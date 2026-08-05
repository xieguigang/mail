<?php
/**
 * loader.php —— 领域服务层加载器
 *
 * 被 Web 入口（bootstrap.php）与 CLI 守护进程（bootstrap_cli.php）共享，
 * 确保两种运行方式加载完全相同的一套领域服务。
 *
 * 使用 require_once 而非 include，避免重复加载导致类重定义致命错误。
 */

$__svc = __DIR__;

# 表名常量必须最先加载：各服务之间存在相互引用，
# 集中定义可消除因加载顺序导致的未定义常量问题
require_once $__svc . "/tables.php";

require_once $__svc . "/MailAddress.php";
require_once $__svc . "/UserService.php";
require_once $__svc . "/TokenService.php";
require_once $__svc . "/FolderService.php";
require_once $__svc . "/AttachmentService.php";
require_once $__svc . "/MimeParser.php";
require_once $__svc . "/MimeBuilder.php";
require_once $__svc . "/MailService.php";
require_once $__svc . "/SearchService.php";
require_once $__svc . "/QueueService.php";
require_once $__svc . "/SmtpClient.php";
require_once $__svc . "/SmtpSession.php";

unset($__svc);
