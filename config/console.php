<?php
return [
    'default'     => 'list',
    'commands'    => [
        'app\command\SeoAiGenerate',
        'app\command\FrontBundleBuild',
        'app\command\BlocklistSync',
        'app\command\Doctor',
        'app\command\UpgradeRun',
    ],
];
