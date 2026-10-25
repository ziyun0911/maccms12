<?php
// +----------------------------------------------------------------------
// | 模板设置 (ThinkTemplate)
// +----------------------------------------------------------------------
return [
    // 模板引擎类型 支持 php think 支持扩展
    'type'         => 'Think',
    // 默认模板渲染规则 1 解析为小写+下划线 2 全部转换小写 3 保持操作方法
    'auto_rule'    => 1,
    // 模板路径 (前台由 common/behavior/Init 动态切换为 template/{主题}/{html}/)
    'view_path'    => '',
    // 模板后缀
    'view_suffix'  => 'html',
    // 模板文件名分隔符
    'view_depr'    => DIRECTORY_SEPARATOR,
    // 模板引擎普通标签开始标记
    'tpl_begin'    => '{',
    // 模板引擎普通标签结束标记
    'tpl_end'      => '}',
    // 标签库标签开始标记
    'taglib_begin' => '{',
    // 标签库标签结束标记
    'taglib_end'   => '}',
    // 预加载的标签库
    'taglib_pre_load' => 'app\common\taglib\Maccms',
    // 内置标签库名称(标签使用不必指定标签库名称) 以逗号分隔
    'taglib_build_in' => 'cx',
    // 默认过滤方法 用于普通标签输出
    'default_filter'  => '',
    // 是否开启模板缓存
    'tpl_cache'       => true,
    // 是否强制编译
    'tpl_force'       => false,
    // 是否在模板中禁止使用 PHP
    'tpl_deny_php'    => false,
    // 模板内容替换
    'tpl_replace_string' => [],
    // 模板变量过滤
    'filter'          => '',
    // 模板是否去除空格
    'strip_space'     => false,
    // 模板引擎缓存目录
    'cache_path'      => root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR,
];
