<?php
// +----------------------------------------------------------------------
// | 路由设置
// +----------------------------------------------------------------------
return [
    // 默认是否开启路由完整匹配
    // 必须开启：生成的规则含可选变量（如 actor-【page】），否则会前缀匹配劫持 detail/type/show 等路由
    'route_complete_match'   => true,
    // 是否强制使用路由
    'url_route_must'         => false,
    // 是否自动转换URL中的控制器和操作名
    'url_convert'            => true,
    // 路由规则定义文件
    'route_config_file'      => ['route'],
    // 请求类型 是否严格区分大小写
    'url_lowercase'          => false,
    // 允许访问的模块列表
    'app_multi'              => true,
    // 未命中控制器的兜底类（app/<模块>/controller/MyError.php）。
    // 不设则 TP8 用框架内置 'Error'，类不存在时直接抛 HttpException「controller not exists」，
    // 调试模式下渲染成带 GET/POST/Cookie/Session 的调试页；改为 MyError 后由各模块基类的
    // __call 返回站点自己的 404/错误响应。
    'empty_controller'       => 'MyError',
    // URL伪静态后缀（含 RSS 的 xml）
    'url_html_suffix'        => 'html|htm|shtm|shtml|xml',
    // 操作方法参数绑定：TP8 默认 'get' 只绑定 GET+路由变量，
    // 本程序大量后台动作（database/export、vodplayer/field 等）靠形参接收 POST，
    // 取 'param' 恢复 TP5 的 GET+POST+路由合并绑定，否则 POST 形参恒为空
    'action_bind_param'      => 'param',
];
