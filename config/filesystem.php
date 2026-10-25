<?php
// +----------------------------------------------------------------------
// | 文件系统设置 (think-filesystem)
// +----------------------------------------------------------------------
return [
    // 默认磁盘
    'default' => 'local',
    // 磁盘列表
    'disks'   => [
        'local'  => [
            'type' => 'local',
            'root' => root_path() . 'storage',
        ],
        'public' => [
            // 磁盘类型
            'type'       => 'local',
            // 磁盘路径
            'root'       => root_path() . 'public/static',
            // 磁盘路径对应的外部URL路径
            'url'        => '/static',
            // 可见性
            'visibility' => 'public',
        ],
        // 更多的磁盘配置信息
    ],
];
