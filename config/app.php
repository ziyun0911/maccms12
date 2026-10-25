<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2026 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
// 苹果CMS v12 —— 基于 ThinkPHP 8 重写的应用配置
return [
    // 应用调试模式
    'app_debug'           => true,
    // 应用 Trace
    'app_trace'           => false,
    // 应用状态
    'app_status'          => '',
    // 应用命名空间（多应用模式须留空，由框架按 app\{应用名} 自动设置）
    'app_namespace'       => '',
    // 默认时区
    'default_timezone'    => 'Asia/Shanghai',
    // 默认语言
    'default_lang'        => 'zh-cn',
    // 语言包切换
    'lang_switch_on'      => false,
    // 默认全局过滤方法
    'default_filter'      => '',
    // 默认输出类型
    'default_return_type' => 'html',
    // 默认AJAX 数据返回格式
    'default_ajax_return' => 'json',
    // 默认JSONP格式返回的处理方法
    'default_jsonp_handler' => 'jsonpReturn',
    // 默认JSONP处理方法
    'var_jsonp_handler'   => 'callback',

    // 默认模块名
    'default_module'      => 'index',
    // 禁止访问模块
    'deny_module_list'    => ['common', 'data', 'extra', 'lang'],
    // 默认控制器名
    'default_controller'  => 'Index',
    // 默认操作名
    'default_action'      => 'index',

    // PATHINFO 变量名
    'var_pathinfo'        => 's',
    // PATHINFO 兼容模式获取变量
    'pathinfo_fetch'      => ['ORIG_PATH_INFO', 'REDIRECT_PATH_INFO', 'REDIRECT_URL'],
    // pathinfo 分隔符
    'pathinfo_depr'       => '/',
    // URL伪静态后缀
    'url_html_suffix'     => 'html|htm|shtm|shtml|xml',
    // URL普通方式参数
    'url_common_param'    => false,
    // 路由是否完全匹配
    'route_complete_match' => false,
    // 是否强制使用路由
    'url_route_must'      => false,
    // 是否自动转换URL中的控制器和操作名
    'url_convert'         => true,

    // 默认跳转页面对应的模板文件
    'dispatch_success_tmpl' => root_path() . 'app/admin/view/public/jump.html',
    'dispatch_error_tmpl'   => root_path() . 'app/admin/view/public/jump.html',

    // 异常页面的模板文件
    'exception_tmpl'      => '',
    // 错误显示信息,非调试模式有效
    'error_message'       => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'      => true,
    // 异常处理handle类 留空使用 \think\exception\Handle
    'exception_handle'    => '',

    // 请求缓存
    'request_cache'          => false,
    'request_cache_expire'   => null,
    'request_cache_except'   => [],

    // 域名绑定
    'domain_bind'         => [],
];

