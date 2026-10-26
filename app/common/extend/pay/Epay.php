<?php
namespace app\common\extend\pay;

class Epay {
    public $name = '易支付';
    public $ver = '1.0';

    public function submit($user,$order,$param,$return_only=false)
    {
        $pay_type = 0;
        $pay_type_map = [1 => 'alipay', 2 => 'qqpay', 3 => 'wxpay',];
        if(!empty($param['paytype']) && isset($pay_type_map[$param['paytype']])){
            $pay_type = $pay_type_map[$param['paytype']];
        }
        $epay_config = $GLOBALS['config']['pay']['epay'];
        $data = array(
            "pid"          => trim($epay_config['appid']),
            "type"         => $pay_type,
            "notify_url"   => $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/payment/notify/pay_type/epay',
            "return_url"   => $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/payment/notify/pay_type/epay',
            "out_trade_no" => $order['order_code'],
            "name"         => '积分充值（UID：'.$user['user_id'].'）',
            "money"        => (float)$order['order_price'],
        );
        if (empty($data['type'])) {
            unset($data['type']);
        }
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

        $query = $urls . '&sign='.md5($sign.trim( $epay_config['appkey'] ));
        $url = $epay_config['api_url'] . "submit.php?{$query}";
        if ($return_only) {
            return '<script>location.href="' . $url . '";</script>';
        }
        mac_redirect($url);
    }

    public function notify()
    {
        $param = $_REQUEST;

        unset($param['/payment/notify/pay_type/epay']);
        unset($param['paytype']);
        unset($param['s']);

        ksort($param);
        reset($param);
        $sign = '';
        foreach ($param as $key => $val) {
            if ($val == '') continue;
            if ($key != 'sign' && $key != 'sign_type') {
                $sign .= "$key=$val&";
            }
        }

        $epay_config = $GLOBALS['config']['pay']['epay'];
        if (!$param['out_trade_no'] || md5(substr($sign, 0, -1) . trim($epay_config['appkey'])) != $param['sign']) {
            echo 'fail';
        }
        else{
            $res = (new \app\common\model\Order())->notify($param['out_trade_no'], 'epay');
            if($res['code'] >1){
                echo 'fail2';
            }
            else {
                echo 'success';
            }
        }
    }
}
