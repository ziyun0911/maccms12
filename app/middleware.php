<?php
// +----------------------------------------------------------------------
// | 全局中间件定义 (苹果CMS v12 —— 由 TP5 的 tags.php 行为迁移而来)
// | ThinkPHP 8 全局中间件文件：app/middleware.php
// +----------------------------------------------------------------------
return [
    // 框架内置：Session 初始化
    \think\middleware\SessionInit::class,

    // 苹果CMS 中间件（顺序即执行顺序；request 阶段在 $next 前，response 阶段在 $next 后）
    \app\common\middleware\SessionSameSite::class,   // Session SameSite
    \app\common\middleware\Init::class,              // 站点初始化（$GLOBALS['config']、常量、视图路径）
    \app\common\middleware\AddonInit::class,         // 已启用插件的 app_init 钩子
    \app\common\middleware\RequestSecurity::class,   // 请求 XSS 清洗
    \app\common\middleware\Begin::class,             // 入口/模块守卫（TP8 下入口已绑定，基本空转）
    \app\common\middleware\IpBlock::class,           // 全站 IP 封禁
    \app\common\middleware\AntiScrape::class,        // 防爬虫限流
    \app\common\middleware\MonitorRequest::class,    // 请求级指标埋点（注册 shutdown 打点）
    \app\common\middleware\PreviewLinks::class,      // 主题设计预览链接（response 内容注入）
    \app\common\middleware\SecurityHeaders::class,   // 安全响应头（response 头注入）
    \app\common\middleware\CookieSameSite::class,    // 业务 Cookie SameSite（response 头注入）
    \app\common\middleware\AdminAudit::class,        // 后台操作审计（response 后落库）
];
