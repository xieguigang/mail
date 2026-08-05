<?php
/**
 * tables.php —— 数据表名常量
 *
 * 集中定义所有表名，避免在各处硬编码字符串，改表名时只需改这一处。
 *
 * 单独抽出为一个文件而非分散在各服务类中，是为了消除加载顺序依赖：
 * 各服务之间存在相互引用（如 FolderService 需要用到 mails 表），
 * 若常量分散定义，就会因 loader.php 的 require 顺序不同而出现未定义常量。
 */

define("USERS_TABLE", "users");
define("TOKENS_TABLE", "tokens");
define("FOLDERS_TABLE", "folders");
define("THREADS_TABLE", "threads");
define("MAILS_TABLE", "mails");
define("RECIPIENTS_TABLE", "mail_recipients");
define("ATTACHMENTS_TABLE", "attachments");
define("UPLOAD_SESSIONS_TABLE", "upload_sessions");
define("UPLOAD_CHUNKS_TABLE", "upload_chunks");
define("SEND_QUEUE_TABLE", "send_queue");
define("SMTP_LOG_TABLE", "smtp_log");
