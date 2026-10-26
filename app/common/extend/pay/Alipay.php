<?php
namespace app\common\extend\pay;

class Alipay {
    public $name = '支付宝';
    public $ver = '1.0';

    public function submit($user,$order,$param,$return_only=false)
    {
        $data = array();
        $data['service'] = 'create_direct_pay_by_user';
        $data['payment_type'] = '1';
        $data['quantity'] = '1';
        $data['_input_charset'] = 'utf-8';
        $data['partner'] = trim($GLOBALS['config']['pay']['alipay']['appid']);
        $data['seller_email'] = trim($GLOBALS['config']['pay']['alipay']['account']);
        $data['out_trade_no'] = $order['order_code'];
        $data['notify_url'] = $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/payment/notify/pay_type/alipay';
        $data['return_url'] = $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/payment/notify/pay_type/alipay';
        $data['subject'] = '积分充值（UID：'.$user['user_id'].'）';
        $data['total_fee'] = sprintf("%.2f",$order['order_price']);

        $para = $this->buildRequestPara($data);
        $sHtml = "<form id='alipaysubmit' name='alipaysubmit' action='https://mapi.alipay.com/gateway.do?_input_charset=utf-8' method='POST'>";
        foreach ($para as $key => $val) {
            $sHtml.= "<input type='hidden' name='".$key."' value='".$val."'/>";
        }
        $sHtml = $sHtml."<input type='submit' value='正在提交'></form>";
        $sHtml = $sHtml."<script>document.forms['alipaysubmit'].submit();</script>";
        if ($return_only) {
            return $sHtml;
        }
        echo $sHtml;
        die;
    }

    public function notify()
    {
        $param = input();
        $GLOBALS['config']['pay'] = config('maccms.pay');
        unset($param['/payment/notify/pay_type/alipay']);
        unset($param['pay_type']);

        $isSign = $this->getSignVeryfy($param, $param["sign"]);
        if($isSign) {
            if ($param['trade_status'] == 'TRADE_SUCCESS') {
                $res = (new \app\common\model\Order())->notify($param['out_trade_no'],'alipay');
                if($res['code']>1){
                    echo "fail2";
                }
                else{
                    echo "success2";
                }
            }
            else {
                echo "success";
            }
        }else{
            echo "fail";
        }
    }

    public function buildRequestPara($para_temp) {
        $para_filter = $this->paraFilter($para_temp);

        $para_sort = $this->argSort($para_filter);

        $mysign = $this->buildRequestMysign($para_sort);

        $para_sort['sign'] = $mysign;
        $para_sort['sign_type'] = 'MD5';

        return $para_sort;
    }

    public function paraFilter($para) {
        $para_filter = array();
        foreach ($para as $key => $val) {
            if($key == "sign" || $key == "sign_type" || $val == "")continue;
            else	$para_filter[$key] = $para[$key];
        }
        return $para_filter;
    }

    public function argSort($para) {
        ksort($para);
        reset($para);
        return $para;
    }

    public function buildRequestMysign($para_sort) {
        $prestr = $this->createLinkstring($para_sort);

        $mysign = $this->md5Sign($prestr, $GLOBALS['config']['pay']['alipay']['appkey']);

        return $mysign;
    }

    public function createLinkstring($para) {
        $arg  = "";
        foreach ($para as $key => $val) {
            $arg.=$key."=".$val."&";
        }
        $arg = rtrim($arg, '&');

        if(function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()){$arg = stripslashes($arg);}

        return $arg;
    }

    public function md5Sign($prestr, $key) {
        $prestr = $prestr . $key;
        return md5($prestr);
    }

    public function getSignVeryfy($para_temp, $sign) {
        $para_filter = $this->paraFilter($para_temp);

        $para_sort = $this->argSort($para_filter);

        $prestr = $this->createLinkstring($para_sort);

        $isSgin = false;
        $isSgin = $this->md5Verify($prestr, $sign, $GLOBALS['config']['pay']['alipay']['appkey']);

        return $isSgin;
    }

    public function md5Verify($prestr, $sign, $key) {
        $prestr = $prestr . $key;
        $mysgin = md5($prestr);
        if($mysgin == $sign) {
            return true;
        }
        else {
            return false;
        }
    }
}
