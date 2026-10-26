<?php
namespace app\common\extend\push;

class Telegram
{
    public $name = 'Telegram';
    public $ver = '1.0';

    public function submit($title, $content, array $context = [], array $config = [])
    {
        $token = isset($config['telegram_token']) ? trim((string)$config['telegram_token']) : '';
        $chatId = isset($config['telegram_chat_id']) ? trim((string)$config['telegram_chat_id']) : '';
        if ($token === '' || $chatId === '') {
            return ['code' => 905, 'msg' => 'telegram_token / telegram_chat_id not configured'];
        }

        $text = self::renderHtml($title, $content);

        $body = json_encode([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['code' => 906, 'msg' => 'failed to encode telegram payload'];
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $res = PushHttp::post($url, $body, PushHttp::jsonHeaders());
        return ['code' => $res['code'], 'msg' => $res['msg']];
    }

    public static function renderHtml($title, $content)
    {
        $t = htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8');
        $c = htmlspecialchars((string)$content, ENT_QUOTES, 'UTF-8');
        return '<b>' . $t . '</b>' . "\n" . $c;
    }
}
