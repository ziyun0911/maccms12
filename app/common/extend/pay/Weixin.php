<?php
namespace app\common\extend\pay;

class Weixin {
    public $name = '微信支付';
    public $ver = '1.0';

    public function submit($user,$order,$param)
    {
        $total_fee = $order['order_price'];
        $data = array();
        $data['appid'] =  trim($GLOBALS['config']['pay']['weixin']['appid']);
        $data['mch_id'] =  trim($GLOBALS['config']['pay']['weixin']['mchid']);
        $data['nonce_str'] =  mac_get_rndstr();
        $data['body'] =  '积分充值（UID：'.$user['user_id'].'）';
        $data['fee_type'] =  'CNY';
        $data['out_trade_no'] = $order['order_code'];
        $data['total_fee'] = $total_fee*100;
        $data['spbill_create_ip'] =  mac_get_client_ip();
        $data['notify_url'] =  $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/payment/notify/pay_type/weixin';
        $data['trade_type'] =  'NATIVE';
        $data['product_id'] = '1';
        $data['sign'] =  $this->makeSign($data);
        $data_xml = mac_array2xml($data);
        $res = mac_curl_post('https://api.mch.weixin.qq.com/pay/unifiedorder', $data_xml);
        $res = mac_xml2array($res);

        if($res['return_code']=='SUCCESS' && $res['result_code']=='SUCCESS'){
            $res = [
                'user_id'=>$user['user_id'],
                'total_fee'=>$total_fee,
                'out_trade_no'=>$data['out_trade_no'],
                'code_url'=>$res['code_url']
            ];

            return $res;
        }
        return false;
    }

    public function notify()
    {
        $xml = file_get_contents('php://input');
        $config = config('maccms.pay');

        $data = mac_xml2array($xml);
        $data_sign = $data['sign'];
        unset($data['sign']);
        $sign = $this->makeSign($data);
        if ( ($sign===$data_sign) && ($data['return_code']=='SUCCESS') && ($data['result_code']=='SUCCESS') ) {
            $res = (new \app\common\model\Order())->notify($data['out_trade_no'],'weixin');
            echo '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        }
        else{
            echo '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[签名失败]]></return_msg></xml>';
        }
    }

    public function makeSign($data){
        $key = trim($GLOBALS['config']['pay']['weixin']['appkey']);
        $data=array_filter($data);
        ksort($data);
        $string_a=http_build_query($data);
        $string_a=urldecode($string_a);
        $string_sign_temp=$string_a."&key=".$key;
        $sign = md5($string_sign_temp);
        $result=strtoupper($sign);
        return $result;
    }
}
