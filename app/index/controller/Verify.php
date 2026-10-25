<?php
namespace app\index\controller;
use think\captcha\facade\Captcha;
use app\common\controller\All;


class Verify extends All
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index($id=null)
    {
        ob_end_clean();
        return Captcha::create($id);
    }

    public function check($verify,$id='')
    {
        if(!captcha_check((string)$verify)){
            return 0;
        }
        else{
            return 1;
        }
    }

}
