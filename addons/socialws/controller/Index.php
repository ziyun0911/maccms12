<?php

namespace addons\socialws\controller;

use app\common\addon\Controller;

class Index extends Controller
{
    public function index()
    {
        exit('当前插件暂无前台页面');
    }

    public function config()
    {
        $info = addon_info('socialws');
        if (empty($info) || empty($info['state'])) {
            return json(['code' => 1, 'msg' => '', 'data' => ['enabled' => false]]);
        }

        $config = addon_config('socialws');
        return json(['code' => 1, 'msg' => '', 'data' => [
            'enabled'   => true,
            'ws_url'    => $config['ws_url'],
            'heartbeat' => (int)$config['heartbeat'],
        ]]);
    }
}
