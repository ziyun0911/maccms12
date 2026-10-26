<?php
namespace app\common\extend\pay;

class Zhapay {
    public $name = '幻兮支付';
    public $ver = '1.0';

    public function submit($user,$order,$param,$return_only=false)
    {
        $pay_type = 1;
        if(!empty($param['paytype'])){
            $pay_type = intval($param['paytype']);
        }

        $data = array(
            "mch_uid" => trim( $GLOBALS['config']['pay']['zhapay']['appid'] ),
            "pay_type_id" => $pay_type,
            "total_fee" => $order['order_price'],
            "out_trade_no" => $order['order_code'],
            "notify_url" => $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/payment/notify/pay_type/zhapay',
            "return_url" => $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/payment/notify/pay_type/zhapay',
            "debug" => 1,
            "mepay_type" => trim( $GLOBALS['config']['pay']['zhapay']['act'] ),
            "return_type" =>1,
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

        $query = $urls . '&sign='.md5($sign.trim( $GLOBALS['config']['pay']['zhapay']['appkey'] ));
        $url = "https://www.zhapay.com/mapay.html?{$query}";

        if ($return_only) {
            return '<script>location.href="' . $url . '";</script>';
        }
        mac_redirect($url);
    }

    public function notify()
    {
        $param = $_POST;

        ksort($param);
        reset($param);
        $sign = '';
        foreach ($param as $key => $val) {
            if ($val == '' || $key == 'sign') continue;
            if ($sign) $sign .= '&';
            $sign .= "$key=$val";
        }

        $GLOBALS['config']['pay'] = config('maccms.pay');

        if (!$param['transaction_id'] || md5($sign. $GLOBALS['config']['pay']['zhapay']['appkey']) != $param['sign']) {
            echo 'fail';
        }
        else{
            $res = (new \app\common\model\Order())->notify($param['out_trade_no'],'zhapay');
            if($res['code'] >1){
                echo 'fail2';
            }
            else {
                echo 'success';
            }
        }
    }
}
