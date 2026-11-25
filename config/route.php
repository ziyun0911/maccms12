<?php
return [
    'route_complete_match'   => true,
    'url_route_must'         => false,
    // 关闭「非路由变量走普通参数」：命中路由规则时，规则内变量一律 urlencode，避免中文裸拼进 Location/静态路径产生双重编码乱码
    'url_common_param'       => false,
    'url_convert'            => true,
    'route_config_file'      => ['route'],
    'url_lowercase'          => false,
    'app_multi'              => true,
    'empty_controller'       => 'MyError',
    'url_html_suffix'        => 'html|htm|shtm|shtml|xml',
    'action_bind_param'      => 'param',
];
