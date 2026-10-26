<?php
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
return [
    'app_debug'           => true,
    'app_trace'           => false,
    'app_status'          => '',
    'app_namespace'       => '',
    'default_timezone'    => 'Asia/Shanghai',
    'default_lang'        => 'zh-cn',
    'lang_switch_on'      => false,
    'default_filter'      => '',
    'default_return_type' => 'html',
    'default_ajax_return' => 'json',
    'default_jsonp_handler' => 'jsonpReturn',
    'var_jsonp_handler'   => 'callback',

    'default_module'      => 'index',
    'deny_module_list'    => ['common', 'data', 'extra', 'lang'],
    'default_controller'  => 'Index',
    'default_action'      => 'index',

    'var_pathinfo'        => 's',
    'pathinfo_fetch'      => ['ORIG_PATH_INFO', 'REDIRECT_PATH_INFO', 'REDIRECT_URL'],
    'pathinfo_depr'       => '/',
    'url_html_suffix'     => 'html|htm|shtm|shtml|xml',
    'url_common_param'    => false,
    'route_complete_match' => false,
    'url_route_must'      => false,
    'url_convert'         => true,

    'dispatch_success_tmpl' => root_path() . 'app/admin/view/public/jump.html',
    'dispatch_error_tmpl'   => root_path() . 'app/admin/view/public/jump.html',

    'exception_tmpl'      => '',
    'error_message'       => '页面错误！请稍后再试～',
    'show_error_msg'      => true,
    'exception_handle'    => '',

    'request_cache'          => false,
    'request_cache_expire'   => null,
    'request_cache_except'   => [],

    'domain_bind'         => [],
];

