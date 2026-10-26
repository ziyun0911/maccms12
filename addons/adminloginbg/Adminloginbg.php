<?php

namespace addons\adminloginbg;

use app\common\addon\Addon;

class Adminloginbg extends Addon
{
    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    public function adminLoginInit(\think\Request &$request)
    {
        $info = $this->getInfo();
        if($info['state'] ==1) {
            $config = $this->getConfig();
            if ($config['mode'] == 'random' || $config['mode'] == 'daily') {
                $index = $config['mode'] == 'random' ? mt_rand(1, 4000) : date("Ymd") % 4000;
                $background = "http://img.infinitynewtab.com/wallpaper/" . $index . ".jpg";
            } else {
                $background = $config['image'];
            }
            \think\facade\View::assign('background', $background);
        }
    }
}
