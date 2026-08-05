<?php
/**
 * config.ini.php —— 站点注册表配置
 *
 * 本文件是一个「返回键值对数组的普通 PHP 脚本」。
 * 框架通过 dotnet::AutoLoad() 读取它并写入 DotNetRegistry，
 * 之后任意位置可用 DotNetRegistry::Read("键名", $默认值) 取用。
 *
 * 部署时请重点修改：DB_*、MAIL_DOMAIN、DEFAULT_AUTH_KEY。
 */

return [
    # =================================================================
    # 一、数据库连接配置（Table 数据模型目前只支持 mysql）
    # =================================================================
    "DB_TYPE" => "mysql",
    "DB_HOST" => "localhost",
    "DB_NAME" => "mailserver",
    "DB_USER" => "root",
    "DB_PWD"  => "123456",
    "DB_PORT" => 3306,

    # =================================================================
    # 二、应用信息
    # =================================================================
    "APP_NAME"    => "php-mail-server",
    "APP_TITLE"   => "PHP 邮件服务器",
    "APP_VERSION" => "1.0.0",

    # 纯 API 服务不使用视图，此项仅为满足框架读取，指向空目录
    "MVC_VIEW_ROOT" => APP_PATH . "/views/",

    # 关闭视图缓存（纯 API 无模板渲染）
    "CACHE"        => FALSE,
    "CACHE.MINIFY" => FALSE,

    # =================================================================
    # 三、错误处理
    # =================================================================
    # 纯 API 服务不使用 HTML 错误页，统一由访问控制器以 JSON 返回错误
    "ERR_HANDLER_DISABLE" => false,
    # 生产环境设为 false，避免泄露服务器路径
    "show.stacktrace"     => false,

    # =================================================================
    # 四、安全与路由
    # =================================================================
    # 加解密默认密钥，生产环境务必改为随机长字符串
    "DEFAULT_AUTH_KEY" => "CHANGE-ME-mail-server-secret-key",
    # 必须与 .htaccess 中的 RewriteEngine 指令成对开启
    "REWRITE_ENGINE"   => TRUE,

    # =================================================================
    # 五、邮件服务自定义配置
    # =================================================================

    # 本服务负责投递的域名。发往这些域的邮件走「本域直投」（直接入库），
    # 不经过网络。多个域名用英文逗号分隔。
    "MAIL_DOMAIN"  => "example.com",
    # 服务器主机名，用于 SMTP 会话的 EHLO 标识与 Message-ID 生成
    "MAIL_HOSTNAME" => "mail.example.com",

    # ---- SMTP 收信守护进程（daemon/smtpd.php）----
    # 监听地址：0.0.0.0 表示接受所有网卡的连接
    "SMTPD_BIND"        => "0.0.0.0",
    # 监听端口：标准 SMTP 为 25。Linux 下绑定 1024 以下端口需 root 权限
    "SMTPD_PORT"        => 25,
    # 最大并发连接数
    "SMTPD_MAX_CONN"    => 64,
    # 单连接空闲超时（秒），超时未收到指令即断开
    "SMTPD_IDLE_TIMEOUT" => 300,
    # 单封邮件最大体积（字节），超限以 552 拒绝。默认 100MB
    "SMTPD_MAX_SIZE"    => 104857600,
    # 单连接最大收件人数量，超限以 452 拒绝
    "SMTPD_MAX_RCPT"    => 100,
    # 单连接允许的连续协议错误次数，超过即断开
    "SMTPD_MAX_ERRORS"  => 10,

    # ---- 发信队列守护进程（daemon/sender.php）----
    # 轮询间隔（秒）
    "SENDER_INTERVAL"    => 5,
    # 单批处理任务数
    "SENDER_BATCH"       => 10,
    # 最大重试次数，超过后置为最终失败
    "SENDER_MAX_RETRY"   => 5,
    # 指数退避基数（秒）：第 n 次重试间隔 = BASE * 2^(n-1)
    "SENDER_RETRY_BASE"  => 60,
    # SMTP 客户端连接与读写超时（秒）
    "SMTP_TIMEOUT"       => 30,
    # 投递时是否尝试 STARTTLS 加密升级
    "SMTP_USE_STARTTLS"  => TRUE,

    # ---- 附件存储 ----
    # 存储根目录。必须位于 Web 可访问范围之外，或由 .htaccess 拒绝直接访问。
    # 所有下载都必须经 API 鉴权后由 PHP 流式输出。
    "STORAGE_ROOT"       => APP_PATH . "/storage",
    # 单个附件最大体积（字节），默认 2GB
    "ATTACHMENT_MAX_SIZE" => 2147483648,
    # 分片上传的默认分片大小（字节），默认 4MB
    "CHUNK_SIZE"         => 4194304,
    # 上传会话过期时间（秒），过期后由 cleanup.php 清理，默认 24 小时
    "UPLOAD_SESSION_TTL" => 86400,
    # 下载时的流式输出缓冲块大小（字节），默认 256KB
    "DOWNLOAD_BUFFER"    => 262144,

    # ---- 账号与令牌 ----
    # Token 有效期（秒），默认 7 天
    "TOKEN_TTL"          => 604800,
    # 单用户邮箱默认配额（字节），默认 5GB
    "USER_QUOTA"         => 5368709120,
    # 回收站邮件保留天数，超期由 cleanup.php 彻底删除
    "TRASH_RETAIN_DAYS"  => 30,

    # ---- 日志 ----
    "LOG_DIR"            => APP_PATH . "/logs"
];
