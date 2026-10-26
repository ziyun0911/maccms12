<?php
return [
    \think\middleware\SessionInit::class,

    \app\common\middleware\SessionSameSite::class,
    \app\common\middleware\Init::class,
    \app\common\middleware\AddonInit::class,
    \app\common\middleware\RequestSecurity::class,
    \app\common\middleware\Begin::class,
    \app\common\middleware\IpBlock::class,
    \app\common\middleware\AntiScrape::class,
    \app\common\middleware\MonitorRequest::class,
    \app\common\middleware\PreviewLinks::class,
    \app\common\middleware\SecurityHeaders::class,
    \app\common\middleware\CookieSameSite::class,
    \app\common\middleware\AdminAudit::class,
];
