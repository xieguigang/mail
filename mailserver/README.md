# 邮件服务器（Mail Server）

基于 PHP + Apache + MySQL，使用 `framework/php.NET` 框架构建的**纯 API 邮件服务系统**。

## 功能概览

- **HTTP API 服务**：JSON 接口全覆盖，账号管理、邮件收发、文件夹管理、会话与草稿、全文搜索、大附件分片上传与断点续传下载
- **SMTP 收信守护进程**：PHP CLI 常驻进程（25 端口），非阻塞 socket_select 多路复用，流式处理超大来信
- **发信队列投递**：异步队列 + 指数退避重试，本域直投 + 外域 MX 查询投递
- **MIME 流式编解码**：自研解析器与构建器，流式处理避免内存峰值
- **大附件支持**：分片上传 / 秒传 / 断点续传 / Range 下载

## 环境依赖

| 组件 | 最低版本 | 说明 |
|------|----------|------|
| PHP | 7.4+ | 推荐 8.0+ |
| Apache | 2.4+ | 需启用 mod_rewrite |
| MySQL | 5.7+ | InnoDB，utf8mb4 |

### PHP 扩展

```ini
extension=sockets       # SMTP 守护进程必需
extension=openssl       # STARTTLS 加密
extension=mbstring      # 多字节字符处理
extension=fileinfo      # MIME 类型检测
extension=pdo_mysql     # 数据库连接
```

## 快速部署

### 1. 克隆项目

确保 `framework/php.NET/` 与 `mailserver/` 在同级目录下：

```
d:/mail/
├── framework/php.NET/   # 框架（只读，勿修改）
└── mailserver/          # 本项目
```

### 2. 导入数据库

```bash
mysql -u root -p < mailserver/scripts/install.sql
```

### 3. 修改配置

编辑 `mailserver/.etc/config.ini.php`，至少修改以下项：

```php
return [
    // 数据库连接
    "DB_HOST"     => "127.0.0.1",
    "DB_NAME"     => "mailserver",
    "DB_USER"     => "root",
    "DB_PASS"     => "your_password",
    "DB_PORT"     => 3306,
    "DB_CHARSET"  => "utf8mb4",

    // 服务域名（用于生成邮箱地址）
    "MAIL_DOMAIN" => "your-domain.com",

    // SMTP 监听
    "SMTP_HOST"   => "0.0.0.0",
    "SMTP_PORT"   => 25,

    // 存储路径（建议置于 Web 根目录之外）
    "STORAGE_ROOT" => __DIR__ . "/../storage",

    // Token 有效期（秒，默认 7 天）
    "TOKEN_TTL"   => 604800,

    // 附件与邮件体积上限（字节）
    "MAX_MAIL_SIZE"     => 52428800,   // 50MB
    "MAX_ATTACH_SIZE"   => 209715200,  // 200MB
    "CHUNK_SIZE"        => 4194304,    // 4MB

    // 发信投递
    "SENDER_INTERVAL"   => 5,          // 轮询间隔（秒）
    "SENDER_BATCH"      => 10,         // 单批处理数
    "SENDER_MAX_RETRY"  => 5,          // 最大重试

    // 清理策略
    "TRASH_RETAIN_DAYS" => 30,         // 回收站保留天数
    "UPLOAD_SESSION_TTL" => 86400,     // 上传会话有效期（秒）
];
```

### 4. 配置 Apache

在 Apache 虚拟主机配置中启用 URL 重写与 .htaccess：

```apache
<VirtualHost *:80>
    ServerName mail.your-domain.com
    DocumentRoot "D:/mail/mailserver"

    <Directory "D:/mail/mailserver">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

或直接修改 `httpd.conf`：

```apache
<Directory "D:/mail/mailserver">
    AllowOverride All
</Directory>
```

### 5. 调整 php.ini

```ini
upload_max_filesize = 256M
post_max_size = 256M
max_execution_time = 300
memory_limit = 512M
max_input_time = 300
```

### 6. 启动守护进程

```bash
# 启动 SMTP 收信守护进程（监听 25 端口，需管理员/root 权限）
php daemon/smtpd.php

# 启动发信队列守护进程
php daemon/sender.php

# 定时清理任务（建议 crontab 每小时执行一次）
# * */1 * * * php /path/to/mailserver/daemon/cleanup.php
```

**Windows 上可使用任务计划程序**：
1. 打开「任务计划程序」
2. 创建基本任务 → 触发器「每小时」→ 操作「启动程序」
3. 程序：`php.exe`，参数：`daemon/smtpd.php`，起始于：`D:\mail\mailserver\`

### 7. 域名 DNS 配置

为使外界能向你的服务器发送邮件，需添加以下 DNS 记录：

```
类型    名称              值
A      mail              你的服务器 IP
MX     @                 mail.your-domain.com (优先级 10)
TXT    @                 v=spf1 mx -all
```

### 8. 防火墙放行

- **入站 TCP 25**：让外界 SMTP 服务器能够连接你的 SMTP 守护进程
- **入站 TCP 80/443**：HTTP API 访问
- 部分 VPS 服务商默认封锁 25 端口，可能需在控制台申请解封

## 守护进程管理

### 手动启动

```bash
# SMTP 收信
php daemon/smtpd.php &

# 发信投递
php daemon/sender.php &

# 单次清理
php daemon/cleanup.php
```

### 系统服务配置（Linux systemd）

`/etc/systemd/system/mail-smtpd.service`：

```ini
[Unit]
Description=Mail SMTP Daemon
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/mailserver
ExecStart=/usr/bin/php daemon/smtpd.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable mail-smtpd
systemctl start mail-smtpd
```

## API 文档

详见 [docs/API.md](docs/API.md)。

## 项目结构

```
mailserver/
├── index.php              # 公开入口（注册/登录/探活）
├── api.php                # 业务入口（需鉴权）
├── bootstrap.php          # Web 引导
├── .htaccess              # URL 重写
├── .etc/
│   ├── config.ini.php     # 配置文件
│   ├── access.php         # 访问控制器（鉴权/限流）
│   └── registry.php       # 公共辅助函数
├── src/                   # 控制器层
│   ├── index.php          # 认证接口
│   ├── mail.php           # 邮件接口
│   ├── folder.php         # 文件夹接口
│   ├── thread.php         # 会话/草稿接口
│   ├── search.php         # 搜索接口
│   └── attachment.php     # 附件上传/下载接口
├── scripts/               # 领域服务层（Web + CLI 共享）
│   ├── install.sql        # 建库建表
│   ├── tables.php         # 表名常量
│   ├── loader.php         # 服务自动加载
│   ├── UserService.php    # 用户
│   ├── TokenService.php   # 令牌
│   ├── MailService.php    # 邮件编排
│   ├── FolderService.php  # 文件夹
│   ├── SearchService.php  # 搜索
│   ├── QueueService.php   # 队列
│   ├── AttachmentService.php # 附件存储
│   ├── MimeParser.php     # MIME 解析
│   ├── MimeBuilder.php    # MIME 构建
│   ├── SmtpClient.php     # SMTP 客户端（发信）
│   ├── SmtpSession.php    # SMTP 服务端会话
│   └── MailAddress.php    # 地址工具
├── daemon/                # CLI 守护进程
│   ├── bootstrap_cli.php  # CLI 引导
│   ├── smtpd.php          # SMTP 收信
│   ├── sender.php         # 发信投递
│   └── cleanup.php        # 定时清理
├── storage/               # 附件与原始报文
├── logs/                  # 运行日志
└── docs/
    └── API.md             # API 文档
```

## 安全注意事项

1. **`APP_DEBUG` 生产环境必须为 `false`**，在 `bootstrap.php` 中设置
2. **附件目录 `storage/` 须置于 Web 根目录之外**，或通过 `.htaccess` 拒绝直接访问
3. **密码使用 `password_hash()` bcrypt 存储**，Token 仅存哈希值
4. **不记录**密码明文、Token 明文、邮件正文全文到日志
5. **所有 SQL 通过 Table 模型构建**，禁止字符串拼接
6. **严禁使用 `~` 前缀原生表达式承载用户输入**
7. **定期运行 `daemon/cleanup.php`** 清理过期 Token、临时文件和回收站邮件
