<?php
namespace app\common\extend\push;

class Webhook
{
    public $name = 'Webhook';
    public $ver = '1.0';

    public function submit($title, $content, array $context = [], array $config = [])
    {
        $url = isset($config['webhook_url']) ? trim((string)$config['webhook_url']) : '';
        if ($url === '') {
            return ['code' => 905, 'msg' => 'webhook_url not configured'];
        }

        $payload = array_merge([
            'title'   => (string)$title,
            'content' => (string)$content,
            'site'    => isset($GLOBALS['config']['site']['site_name']) ? (string)$GLOBALS['config']['site']['site_name'] : '',
            'ts'      => time(),
        ], $context);

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['code' => 906, 'msg' => 'failed to encode webhook payload'];
        }

        $headers = PushHttp::jsonHeaders();
        $secret = isset($config['webhook_secret']) ? trim((string)$config['webhook_secret']) : '';
        if ($secret !== '') {
            $headers[] = 'X-Maccms-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $res = PushHttp::post($url, $body, $headers);
        return ['code' => $res['code'], 'msg' => $res['msg']];
    }

    public static function sign($body, $secret)
    {
        return 'sha256=' . hash_hmac('sha256', (string)$body, (string)$secret);
    }
}
