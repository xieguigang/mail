-- =====================================================================
-- 邮件服务器数据库结构
--
-- 字符集统一使用 utf8mb4，以完整支持 emoji 与各类多字节字符
-- （邮件主题中出现 emoji 极为常见，utf8 三字节编码会导致写入失败）。
-- 存储引擎统一使用 InnoDB，以获得事务与行级锁支持。
--
-- 重要：每张表都必须有 AUTO_INCREMENT 主键，
--       因为框架 Table::add() 依赖自增主键才能返回新插入行的 id。
--
-- 导入方式：
--   mysql -u root -p < install.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `mailserver`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `mailserver`;

-- ---------------------------------------------------------------------
-- users：用户账号
-- 每个用户对应一个邮箱地址（username@domain）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    -- 邮箱用户名部分（@ 之前），小写存储
    `username`      VARCHAR(64)     NOT NULL,
    -- 邮箱域名部分（@ 之后），小写存储
    `domain`        VARCHAR(128)    NOT NULL,
    -- 完整邮箱地址，冗余存储以便直接等值查询，避免拼接后再比较
    `email`         VARCHAR(255)    NOT NULL,
    -- 密码哈希（password_hash 产生的 bcrypt 串，长度预留 255）
    -- 绝不存储明文密码
    `password_hash` VARCHAR(255)    NOT NULL,
    -- 显示名，用于发信时的 From 显示名
    `nickname`      VARCHAR(128)    NOT NULL DEFAULT '',
    -- 角色，供 @access 标签做角色组校验
    `role`          VARCHAR(32)     NOT NULL DEFAULT 'user',
    -- 账号状态：1 启用，0 停用
    `status`        TINYINT         NOT NULL DEFAULT 1,
    -- 邮箱容量配额（字节）
    `quota`         BIGINT UNSIGNED NOT NULL DEFAULT 5368709120,
    -- 已使用容量（字节），随邮件与附件增删同步维护
    `used_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `create_time`   DATETIME        NOT NULL,
    `last_login`    DATETIME        NULL DEFAULT NULL,
    `last_login_ip` VARCHAR(64)     NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    -- 邮箱地址全局唯一，从数据库层面杜绝重复注册
    UNIQUE KEY `uk_email` (`email`),
    KEY `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- tokens：API 访问令牌
-- 采用「随机串 + 服务端存储」模式，支持主动吊销与登出即失效。
-- 仅存储 token 的哈希值，明文不落库，防止库泄露后被直接冒用。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tokens`;
CREATE TABLE `tokens` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    -- token 明文的 sha256 哈希（64 位十六进制）
    `token_hash`  CHAR(64)     NOT NULL,
    `create_time` DATETIME     NOT NULL,
    `expire_time` DATETIME     NOT NULL,
    `last_used`   DATETIME     NULL DEFAULT NULL,
    `client_ip`   VARCHAR(64)  NOT NULL DEFAULT '',
    -- 1 有效，0 已吊销
    `status`      TINYINT      NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    -- 校验令牌时按哈希等值查找，必须唯一且有索引
    UNIQUE KEY `uk_token_hash` (`token_hash`),
    -- 清理过期令牌与按用户批量吊销时使用
    KEY `idx_user_status` (`user_id`, `status`),
    KEY `idx_expire` (`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- folders：邮件文件夹
-- 系统文件夹（收件箱/已发送/草稿/垃圾邮件/回收站）在用户注册时自动创建，
-- 用 is_system=1 标记，不允许重命名或删除。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `folders`;
CREATE TABLE `folders` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    -- 文件夹显示名
    `name`        VARCHAR(128) NOT NULL,
    -- 系统文件夹类型标识：inbox/sent/drafts/spam/trash，自建文件夹为 custom
    `type`        VARCHAR(32)  NOT NULL DEFAULT 'custom',
    -- 1 系统文件夹（不可删改），0 用户自建
    `is_system`   TINYINT      NOT NULL DEFAULT 0,
    `create_time` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    -- 同一用户下文件夹名不可重复
    UNIQUE KEY `uk_user_name` (`user_id`, `name`),
    KEY `idx_user_type` (`user_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- threads：会话线程
-- 依据邮件的 References / In-Reply-To 引用关系聚合。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `threads`;
CREATE TABLE `threads` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED NOT NULL,
    -- 会话主题（取首封邮件主题，去除 Re:/Fwd: 前缀后的规范形式）
    `subject`      VARCHAR(512) NOT NULL DEFAULT '',
    -- 会话内邮件数量
    `mail_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    -- 会话内未读邮件数量
    `unread_count` INT UNSIGNED NOT NULL DEFAULT 0,
    -- 最后一封邮件的时间，用于会话列表排序
    `last_time`    DATETIME     NOT NULL,
    `create_time`  DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    -- 会话列表按用户 + 时间倒序查询
    KEY `idx_user_time` (`user_id`, `last_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- mails：邮件主体
-- 每一封邮件在每一个用户的邮箱中都是一条独立记录
-- （发件人的「已发送」与收件人的「收件箱」是两条记录），
-- 这样文件夹归属、已读状态、删除操作都能各自独立，互不影响。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `mails`;
CREATE TABLE `mails` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    -- 邮件归属用户
    `user_id`      INT UNSIGNED    NOT NULL,
    `folder_id`    INT UNSIGNED    NOT NULL,
    `thread_id`    INT UNSIGNED    NOT NULL DEFAULT 0,
    -- 全局唯一的邮件标识（RFC 5322 Message-ID），用于会话聚合与去重
    `message_id`   VARCHAR(255)    NOT NULL DEFAULT '',
    -- 回复关系：所回复邮件的 Message-ID
    `in_reply_to`  VARCHAR(255)    NOT NULL DEFAULT '',
    -- 完整引用链，空格分隔的 Message-ID 序列
    `references`   TEXT            NULL,
    -- 发件人
    `from_address` VARCHAR(255)    NOT NULL DEFAULT '',
    `from_name`    VARCHAR(255)    NOT NULL DEFAULT '',
    -- 收件人摘要（逗号分隔），用于列表展示，避免列表查询时联表
    `to_summary`   VARCHAR(1024)   NOT NULL DEFAULT '',
    `subject`      VARCHAR(512)    NOT NULL DEFAULT '',
    -- 纯文本正文
    `body_text`    LONGTEXT        NULL,
    -- HTML 正文
    `body_html`    LONGTEXT        NULL,
    -- 正文摘要片段，列表接口只取此列，避免读取正文大字段造成不必要 I/O
    `summary`      VARCHAR(512)    NOT NULL DEFAULT '',
    -- 邮件总大小（字节），含附件
    `size`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    -- 是否含附件：1 是，0 否。列表筛选用，避免联表统计
    `has_attach`   TINYINT         NOT NULL DEFAULT 0,
    -- 1 已读，0 未读
    `is_read`      TINYINT         NOT NULL DEFAULT 0,
    -- 1 星标
    `is_starred`   TINYINT         NOT NULL DEFAULT 0,
    -- 1 草稿
    `is_draft`     TINYINT         NOT NULL DEFAULT 0,
    -- 邮件方向：in 收到的，out 发出的
    `direction`    VARCHAR(8)      NOT NULL DEFAULT 'in',
    -- 投递状态：draft/queued/sending/sent/failed/received
    `send_status`  VARCHAR(16)     NOT NULL DEFAULT 'received',
    -- 原始报文归档文件的相对路径（SMTP 收信时保存）
    `raw_path`     VARCHAR(512)    NOT NULL DEFAULT '',
    -- 邮件时间（信头 Date，收信时以此为准）
    `mail_time`    DATETIME        NOT NULL,
    `create_time`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    -- 核心列表查询：某用户某文件夹按时间倒序分页
    KEY `idx_user_folder_time` (`user_id`, `folder_id`, `mail_time`),
    -- 会话内邮件按时间排序
    KEY `idx_thread` (`thread_id`, `mail_time`),
    -- 会话聚合时按 Message-ID 反查
    KEY `idx_message_id` (`message_id`),
    -- 未读数统计
    KEY `idx_user_read` (`user_id`, `is_read`),
    -- 发信队列按状态扫描
    KEY `idx_send_status` (`send_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- mail_recipients：邮件收件人明细
-- 一封邮件的 To/Cc/Bcc 多个地址在此展开为多行，
-- 便于按收件人检索，也便于发信队列按地址逐个跟踪投递结果。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `mail_recipients`;
CREATE TABLE `mail_recipients` (
    `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mail_id` INT UNSIGNED NOT NULL,
    -- 收件人类型：to / cc / bcc
    `type`    VARCHAR(8)   NOT NULL DEFAULT 'to',
    `address` VARCHAR(255) NOT NULL,
    `name`    VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_mail` (`mail_id`),
    KEY `idx_address` (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- attachments：附件元数据
-- 二进制内容存放于本地磁盘（两级哈希目录），数据库只存元数据与路径。
-- mail_id = 0 表示「已上传但尚未关联到邮件」的游离附件，
-- 由 cleanup.php 在超期后回收。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `attachments`;
CREATE TABLE `attachments` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `mail_id`     INT UNSIGNED    NOT NULL DEFAULT 0,
    -- 上传者 / 归属用户
    `user_id`     INT UNSIGNED    NOT NULL DEFAULT 0,
    -- 原始文件名（用户可见）
    `filename`    VARCHAR(255)    NOT NULL,
    -- MIME 类型
    `mime_type`   VARCHAR(128)    NOT NULL DEFAULT 'application/octet-stream',
    `size`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    -- 磁盘相对存储路径（相对于 STORAGE_ROOT/attachments）
    `store_path`  VARCHAR(512)    NOT NULL,
    -- 文件内容的 sha256 校验值，用于秒传命中判定与完整性校验
    `checksum`    CHAR(64)        NOT NULL DEFAULT '',
    -- 内嵌图片的 Content-ID，普通附件为空
    `content_id`  VARCHAR(255)    NOT NULL DEFAULT '',
    -- 1 为正文内嵌资源，0 为普通附件
    `is_inline`   TINYINT         NOT NULL DEFAULT 0,
    `create_time` DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_mail` (`mail_id`),
    -- 秒传：按校验值 + 大小查找已有文件
    KEY `idx_checksum` (`checksum`, `size`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- upload_sessions：分片上传会话
-- 记录一次大文件上传的整体状态，支持断点续传。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `upload_sessions`;
CREATE TABLE `upload_sessions` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    -- 上传会话标识（UUID），返回给客户端作为后续分片上传的凭据
    `upload_id`    CHAR(36)        NOT NULL,
    `user_id`      INT UNSIGNED    NOT NULL,
    `filename`     VARCHAR(255)    NOT NULL,
    `mime_type`    VARCHAR(128)    NOT NULL DEFAULT 'application/octet-stream',
    -- 文件总大小（字节）
    `total_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    -- 分片大小（字节）
    `chunk_size`   INT UNSIGNED    NOT NULL DEFAULT 4194304,
    -- 分片总数
    `total_chunks` INT UNSIGNED    NOT NULL DEFAULT 0,
    -- 已完成分片数
    `uploaded`     INT UNSIGNED    NOT NULL DEFAULT 0,
    -- 整文件校验值（客户端提供，用于秒传判定与合并后校验）
    `checksum`     CHAR(64)        NOT NULL DEFAULT '',
    -- 分片临时目录（相对于 STORAGE_ROOT/tmp）
    `temp_dir`     VARCHAR(512)    NOT NULL DEFAULT '',
    -- 会话状态：uploading / completed / aborted
    `status`       VARCHAR(16)     NOT NULL DEFAULT 'uploading',
    -- 合并完成后生成的附件 id
    `attachment_id` INT UNSIGNED   NOT NULL DEFAULT 0,
    `create_time`  DATETIME        NOT NULL,
    `expire_time`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_upload_id` (`upload_id`),
    KEY `idx_user_status` (`user_id`, `status`),
    -- cleanup.php 扫描过期会话
    KEY `idx_expire` (`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- upload_chunks：分片记录
-- 每成功接收一个分片写入一行，进度查询与断点续传依据此表。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `upload_chunks`;
CREATE TABLE `upload_chunks` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `upload_id`   CHAR(36)     NOT NULL,
    -- 分片序号，从 0 开始
    `chunk_index` INT UNSIGNED NOT NULL,
    `chunk_size`  INT UNSIGNED NOT NULL DEFAULT 0,
    `checksum`    CHAR(64)     NOT NULL DEFAULT '',
    `create_time` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    -- 同一会话内分片序号唯一，保证分片幂等（重复上传同序号covered为更新）
    UNIQUE KEY `uk_upload_chunk` (`upload_id`, `chunk_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- send_queue：发信队列
-- 发信接口只入队即返回，实际投递由 daemon/sender.php 异步执行，
-- 避免 HTTP 请求被网络投递长时间阻塞。
-- 一封邮件的每个收件人对应一条队列记录，便于分别重试。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `send_queue`;
CREATE TABLE `send_queue` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mail_id`       INT UNSIGNED NOT NULL,
    -- 信封发件人（SMTP MAIL FROM）
    `from_address`  VARCHAR(255) NOT NULL,
    -- 信封收件人（SMTP RCPT TO）
    `to_address`    VARCHAR(255) NOT NULL,
    -- 收件人域名，用于按域分组合并投递以减少连接数
    `to_domain`     VARCHAR(128) NOT NULL DEFAULT '',
    -- 队列状态：pending / sending / sent / failed
    `status`        VARCHAR(16)  NOT NULL DEFAULT 'pending',
    -- 已重试次数
    `attempts`      INT UNSIGNED NOT NULL DEFAULT 0,
    -- 下次重试时间，指数退避写入
    `next_retry`    DATETIME     NOT NULL,
    -- 最近一次错误描述
    `last_error`    VARCHAR(1024) NOT NULL DEFAULT '',
    `create_time`   DATETIME     NOT NULL,
    `update_time`   DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    -- 守护进程核心查询：按状态 + 下次重试时间取待发任务
    KEY `idx_status_retry` (`status`, `next_retry`),
    KEY `idx_mail` (`mail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- smtp_log：SMTP 收信日志
-- 记录每一次入站投递的结果，用于排障与滥用分析。
-- 为避免高并发下日志爆炸，仅记录会话摘要而非完整报文。
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `smtp_log`;
CREATE TABLE `smtp_log` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `remote_ip`    VARCHAR(64)     NOT NULL DEFAULT '',
    `helo`         VARCHAR(255)    NOT NULL DEFAULT '',
    `from_address` VARCHAR(255)    NOT NULL DEFAULT '',
    -- 收件人列表（逗号分隔摘要）
    `to_address`   VARCHAR(1024)   NOT NULL DEFAULT '',
    `size`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    -- 处理结果：accepted / rejected / error
    `result`       VARCHAR(16)     NOT NULL DEFAULT 'accepted',
    `message`      VARCHAR(512)    NOT NULL DEFAULT '',
    `create_time`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_time` (`create_time`),
    KEY `idx_remote` (`remote_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
