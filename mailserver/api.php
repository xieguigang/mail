<?php
/**
 * api.php —— 业务接口入口（全部需鉴权）
 *
 * 单独设立第二个入口的收益：
 *   1) 访问控制器可依据入口名快速判定是否强制鉴权
 *   2) Apache 层可对本入口单独放宽上传体积与执行超时（附件分片走这里）
 *
 * 访问形式：
 *   /api.php?ctl=mail&app=send
 *   /api.php?ctl=attachment&app=chunk
 *   重写后：/api/mail/send
 */

require_once __DIR__ . "/bootstrap.php";
