<?php
namespace app\common\extend\push;

class Dingtalk
{
    public $name = 'DingTalk';
    public $ver = '1.0';

    public function submit($title, $content, array $context = [], array $config = [])
    {
        $token = isset($config['dingtalk_token']) ? trim((string)$config['dingtalk_token']) : '';
        if ($token === '') {
            return ['code' => 905, 'msg' => 'dingtalk_token not configured'];
        }
        $secret = isset($config['dingtalk_secret']) ? trim((string)$config['dingtalk_secret']) : '';

        $url = 'https://oapi.dingtalk.com/robot/send?access_token=' . urlencode($token);
        if ($secret !== '') {
            $timestamp = (string)(time() * 1000);
            $url .= '&timestamp=' . $timestamp . '&sign=' . urlencode(self::sign($timestamp, $secret));
        }

        $body = json_encode([
            'msgtype'  => 'markdown',
            'markdown' => [
                'title' => (string)$title,
                'text'  => '### ' . (string)$title . "\n\n" . (string)$content,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['code' => 906, 'msg' => 'failed to encode dingtalk payload'];
        }

        $res = PushHttp::post($url, $body, PushHttp::jsonHeaders());
        if ($res['code'] === 1) {
            $json = json_decode($res['body'], true);
            if (is_array($json) && isset($json['errcode']) && intval($json['errcode']) !== 0) {
                return ['code' => intval($json['errcode']), 'msg' => isset($json['errmsg']) ? (string)$json['errmsg'] : 'dingtalk error'];
            }
        }
        return ['code' => $res['code'], 'msg' => $res['msg']];
    }

    public static function sign($timestamp, $secret)
    {
        $stringToSign = (string)$timestamp . "\n" . (string)$secret;
        return base64_encode(hash_hmac('sha256', $stringToSign, (string)$secret, true));
    }
}
