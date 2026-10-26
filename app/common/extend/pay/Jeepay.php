<?php
namespace app\common\extend\pay;

class Jeepay {
    public $name = 'JeePay';
    public $ver = '1.0';

    public function submit($user, $order, $param, $return_only = false)
    {
        $jeepay_config = $GLOBALS['config']['pay']['jeepay'];
        $api_url = rtrim(trim($jeepay_config['api_url']), '/');
        $mch_no  = trim($jeepay_config['mch_no']);
        $app_id  = trim($jeepay_config['appid']);
        $app_secret = trim($jeepay_config['appkey']);

        $way_code = 'ALI_WAP';
        $way_codes_str = trim($jeepay_config['way_codes'] ?? '');
        $available_ways = array_filter(array_map('trim', explode(',', $way_codes_str)));

        if (!empty($param['paytype'])) {
            $paytype = strtoupper(trim($param['paytype']));
            if (in_array($paytype, $available_ways)) {
                $way_code = $paytype;
            }
        } elseif (!empty($available_ways)) {
            $way_code = $available_ways[0];
        }

        $amount = intval(round(floatval($order['order_price']) * 100));
        if ($amount < 1) {
            $amount = 1;
        }

        $site_url = trim($GLOBALS['config']['site']['site_url'] ?? '');
        if (empty($site_url)) {
            if ($return_only) {
                return '<script>alert("site_url not configured");history.back();</script>';
            }
            echo 'site_url not configured';
            exit;
        }
        $base_url = $GLOBALS['http_type'] . rtrim($site_url, '/');
        $notify_url = $base_url . '/index.php/payment/notify/pay_type/jeepay';
        $return_url = $base_url . '/index.php/user/bindpay';

        $data = [
            'version'    => '1.0',
            'signType'   => 'MD5',
            'reqTime'    => (string) round(microtime(true) * 1000),
            'mchNo'      => $mch_no,
            'appId'      => $app_id,
            'mchOrderNo' => $order['order_code'],
            'wayCode'    => $way_code,
            'amount'     => $amount,
            'currency'   => 'CNY',
            'clientIp'   => mac_get_client_ip(),
            'subject'    => sprintf(lang('pay/jeepay/recharge'), $user['user_id']),
            'body'       => lang('pay/jeepay/recharge_body'),
            'notifyUrl'  => $notify_url,
            'returnUrl'  => $return_url,
            'channelExtra' => json_encode(['payDataType' => 'payurl']),
        ];

        $data['sign'] = $this->makeSign($data, $app_secret);

        $url = $api_url . '/api/pay/unifiedOrder';
        $response = $this->httpPostJson($url, $data);

        if ($response === false) {
            if ($return_only) {
                return '<script>alert("' . addslashes(lang('pay/jeepay/request_fail')) . '");history.back();</script>';
            }
            echo lang('pay/jeepay/request_fail');
            exit;
        }

        $result = json_decode($response, true);

        if (!$result || $result['code'] != 0) {
            $err_msg = $result['msg'] ?? 'unknown error';
            if ($return_only) {
                return '<script>alert("' . addslashes(sprintf(lang('pay/jeepay/pay_fail'), $err_msg)) . '");history.back();</script>';
            }
            echo sprintf(lang('pay/jeepay/pay_fail'), htmlspecialchars($err_msg));
            exit;
        }

        $pay_data = $result['data'] ?? [];
        $pay_url = '';

        if (!empty($pay_data['payData'])) {
            $pay_url = $pay_data['payData'];
        }

        if (empty($pay_url)) {
            if ($return_only) {
                return '<script>alert("' . addslashes(lang('pay/jeepay/no_pay_url')) . '");history.back();</script>';
            }
            echo lang('pay/jeepay/no_pay_url');
            exit;
        }

        if ($return_only) {
            return '<script>location.href="' . $pay_url . '";</script>';
        }
        mac_redirect($pay_url);
    }

    public function notify()
    {
        $param = $_POST;
        if (empty($param)) {
            echo 'invalid request';
            exit;
        }

        $jeepay_config = $GLOBALS['config']['pay']['jeepay'];
        $app_secret = trim($jeepay_config['appkey']);

        $sign = $param['sign'] ?? '';
        if (empty($sign)) {
            echo 'sign is empty';
            exit;
        }

        $calc_sign = $this->makeSign($param, $app_secret);
        if ($calc_sign !== $sign) {
            echo 'sign verify failed';
            exit;
        }

        $state = intval($param['state'] ?? 0);
        $mch_order_no = $param['mchOrderNo'] ?? '';

        if (empty($mch_order_no)) {
            echo 'mchOrderNo is empty';
            exit;
        }

        if ($state != 2) {
            echo 'fail';
            exit;
        }

        $callback_amount = intval($param['amount'] ?? 0);
        $order = \think\facade\Db::name('order')->where('order_code', $mch_order_no)->find();
        if (empty($order)) {
            echo 'order not found';
            exit;
        }
        $expect_amount = intval(round(floatval($order['order_price']) * 100));
        if ($callback_amount !== $expect_amount) {
            echo 'amount mismatch';
            exit;
        }

        $currency = strtoupper(trim($param['currency'] ?? ''));
        if ($currency !== '' && $currency !== 'CNY') {
            echo 'currency mismatch';
            exit;
        }

        $res = (new \app\common\model\Order())->notify($mch_order_no, 'jeepay');
        if ($res['code'] > 1) {
            echo 'fail';
            exit;
        }
        echo 'SUCCESS';
    }

    private function makeSign($params, $app_secret)
    {
        unset($params['sign']);

        $filtered = [];
        foreach ($params as $key => $value) {
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            $filtered[$key] = $value;
        }

        uksort($filtered, 'strcasecmp');

        $pairs = [];
        foreach ($filtered as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        $signStr = implode('&', $pairs) . '&key=' . $app_secret;
        return strtoupper(md5($signStr));
    }

    private function httpPostJson($url, $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $verify_ssl = isset($GLOBALS['config']['pay']['jeepay']['verify_ssl'])
            ? intval($GLOBALS['config']['pay']['jeepay']['verify_ssl'])
            : 1;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool) $verify_ssl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify_ssl ? 2 : 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json),
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code != 200 || $response === false) {
            return false;
        }

        return $response;
    }
}
