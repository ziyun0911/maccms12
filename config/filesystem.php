<?php
return [
    'default' => 'local',
    'disks'   => [
        'local'  => [
            'type' => 'local',
            'root' => root_path() . 'storage',
        ],
        'public' => [
            'type'       => 'local',
            'root'       => root_path() . 'public/static',
            'url'        => '/static',
            'visibility' => 'public',
        ],
    ],
];
