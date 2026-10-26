<?php
return [
    'default'      => 'file',
    'level'        => [],
    'type_channel' => [],
    'close'        => false,
    'format'       => '[%s][%s] %s',
    'realtime_write' => false,
    'channels'     => [
        'file' => [
            'type'        => 'File',
            'path'        => '',
            'single'      => false,
            'time_format' => 'Y-m-d H:i:s',
            'json'        => false,
            'max_files'   => 0,
        ],
    ],
];
