<?php
// +----------------------------------------------------------------------
// | 会话设置
// +----------------------------------------------------------------------
return [
    'id'             => '',
    // SESSION_ID的提交变量,解决flash上传跨域
    'var_session_id' => '',
    // SESSION 前缀
    'prefix'         => 'think',
    // 驱动方式 支持file redis memcache memcached（TP8 必须指定具体驱动，空值会命中 createDriver 覆盖方法报错）
    'type'           => 'file',
    // 是否自动开启 SESSION
    'auto_start'     => true,
    // 过期时间（秒）
    // TP8 文件驱动用 filemtime >= time() - expire 判断有效性：0 会导致上一秒写入的会话立即被判过期，
    // 登录态无法跨请求保持，因此必须使用正数有效期。
    'expire'         => 86400,
    // PHP 7.3+ Cookie SameSite，减轻跨站请求伪造（需 HTTPS 站点可将 secure 设为 true）
    'samesite'       => 'Lax',
];
