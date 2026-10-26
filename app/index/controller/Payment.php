<?php
namespace app\index\controller;

use \think\Request;
use app\api\controller\Payment as ApiPayment;

class Payment extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function notify()
    {
        $param = input();
        $pay_type = $param['pay_type'] ?? '';

        if (empty($pay_type)) {
            echo 'pay_type is required';
            exit;
        }

        $pay_config = $GLOBALS['config']['pay'];
        $cfg = (isset($pay_config[$pay_type]) && is_array($pay_config[$pay_type])) ? $pay_config[$pay_type] : [];

        if (!ApiPayment::isPayChannelReady($pay_type, $cfg)) {
            echo lang('index/payment_status');
            exit;
        }

        $cp = 'app\\common\\extend\\pay\\' . ucfirst($pay_type);
        if (class_exists($cp)) {
            $c = new $cp;
            $c->notify();
        }
        else{
            echo lang('index/payment_not');
            exit;
        }
    }
}
