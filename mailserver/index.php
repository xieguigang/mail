<?php
/**
 * index.php —— 公开接口入口
 *
 * 承载无需鉴权的接口：注册、登录、登出、探活。
 * 对应控制器类 App（src/index.php）。
 *
 * 访问形式：
 *   /index.php?app=register
 *   /index.php?app=login
 *   重写后：/api/auth/register
 */

require_once __DIR__ . "/bootstrap.php";
