<?php
namespace app\common\extend\pay;

class Codepay {
    public $name = '码支付';
    public $ver = '1.0';

    public function submit($user,$order,$param,$return_only=false)
    {
        $pay_type = 1;
        if(!empty($param['paytype'])){
            $pay_type = intval($param['paytype']);
        }

        $data = array(
            "id" => trim( $GLOBALS['config']['pay']['codepay']['appid'] ),
            "type" => $pay_type,
            "price" => $order['order_price'],
            "pay_id" => $order['order_code'],
            "notify_url" => $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/payment/notify/pay_type/codepay',
            "return_url" => $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/payment/notify/pay_type/codepay',
            "debug" => 1,
            "ac" => trim( $GLOBALS['config']['pay']['codepay']['ac'] ),
            "param" => "",
        );

        ksort($data);
        reset($data);

        $sign = '';
        $urls = '';

        foreach ($data as $key => $val) {
            if ($val == ''||$key == 'sign') continue;
            if ($sign != '') {
                $sign .= "&";
                $urls .= "&";
            }
            $sign .= "$key=$val";
            $urls .= "$key=" . urlencode($val);
        }

        $query = $urls . '&sign='.md5($sign.trim( $GLOBALS['config']['pay']['codepay']['appkey'] ));
        $url = "https://api.xiuxiu888.com/creat_order/?{$query}";

        if ($return_only) {
            return '<script>location.href="' . $url . '";</script>';
        }
        mac_redirect($url);
    }

    public function notify()
    {
        $param = $_POST;

        unset($param['/payment/notify/pay_type/codepay']);
        unset($param['paytype']);

        ksort($param);
        reset($param);
        $sign = '';
        foreach ($param as $key => $val) {
            if ($val == '') continue;
            if ($key != 'sign') {
                $sign .= "$key=$val&";
            }
        }

        $GLOBALS['config']['pay'] = config('maccms.pay');

        if (!$param['pay_no'] || md5(substr($sign,0,-1).trim( $GLOBALS['config']['pay']['codepay']['appkey'])) != $param['sign']) {
            echo 'fail';
        }
        else{
            $res = (new \app\common\model\Order())->notify($param['pay_id'],'codepay');
            if($res['code'] >1){
                echo 'fail2';
            }
            else {
                echo 'success';
            }
        }
    }
}
