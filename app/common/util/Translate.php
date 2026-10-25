<?php
namespace app\common\util;

/**
 * 多供应商机器翻译适配器（maccms12 / ThinkPHP 8）
 *
 * 支持：baidu（百度翻译开放平台）/ tencent（腾讯云机器翻译）/ deepl（DeepL API）/ openai（OpenAI 兼容 Chat 接口）
 * 配置来源：app\extra\translate.php（后台"翻译设置"页保存，mac_load_extra() 自动加载）
 *
 * 语言码统一使用 maccms 内容语言码（zh-cn/zh-tw/en-us/ja-jp/ko-kr/ru-ru），
 * 内部经 mapLang() 转换为各供应商自己的语言码；不支持的语言对返回 null（调用方保留原文）。
 */
class Translate
{
    /**
     * 翻译一段文本。成功返回译文字符串；未配置、语言不受支持或接口失败返回 null（调用方应保留原文）。
     */
    public static function translate($text, $from, $to)
    {
        $text = (string)$text;
        if (trim($text) === '') {
            return '';
        }
        $cfg = config('translate');
        if (empty($cfg['type'])) {
            return null;
        }
        $from = self::mapLang($cfg['type'], $from);
        $to   = self::mapLang($cfg['type'], $to);
        if ($from === '' || $to === '') {
            return null;
        }
        try {
            switch ($cfg['type']) {
                case 'baidu':
                    return self::baidu($cfg, $text, $from, $to);
                case 'tencent':
                    return self::tencent($cfg, $text, $from, $to);
                case 'deepl':
                    return self::deepl($cfg, $text, $from, $to);
                case 'openai':
                    return self::openai($cfg, $text, $from, $to);
            }
        } catch (\Throwable $e) {
            // 任何网络/签名/解析异常都降级为"无法翻译"，由调用方保留原文
            return null;
        }
        return null;
    }

    /**
     * 翻译是否已可用（已选择供应商且凭据非空）。
     */
    public static function available()
    {
        $cfg = config('translate');
        if (empty($cfg['type'])) {
            return false;
        }
        switch ($cfg['type']) {
            case 'baidu':
                return !empty($cfg['appid']) && !empty($cfg['secret']);
            case 'tencent':
                return !empty($cfg['secret_id']) && !empty($cfg['secret_key']);
            case 'deepl':
                return !empty($cfg['auth_key']);
            case 'openai':
                return !empty($cfg['api_key']);
        }
        return false;
    }

    /**
     * maccms 内容语言码 -> 供应商语言码。
     */
    protected static function mapLang($type, $lang)
    {
        $lang = strtolower((string)$lang);
        $map = [
            'baidu' => [
                'zh-cn' => 'zh', 'zh-tw' => 'cht', 'en-us' => 'en', 'ja-jp' => 'jp',
                'ko-kr' => 'kor', 'ru-ru' => 'ru',
            ],
            'tencent' => [
                'zh-cn' => 'zh', 'zh-tw' => 'zh-TW', 'en-us' => 'en', 'ja-jp' => 'ja',
                'ko-kr' => 'ko', 'ru-ru' => 'ru',
            ],
            'deepl' => [
                'zh-cn' => 'ZH', 'zh-tw' => 'ZH', 'en-us' => 'EN-US', 'ja-jp' => 'JA',
                'ko-kr' => 'KO', 'ru-ru' => 'RU',
            ],
            'openai' => [
                'zh-cn' => 'Simplified Chinese', 'zh-tw' => 'Traditional Chinese', 'en-us' => 'English',
                'ja-jp' => 'Japanese', 'ko-kr' => 'Korean', 'ru-ru' => 'Russian',
            ],
        ];
        return isset($map[$type][$lang]) ? $map[$type][$lang] : '';
    }

    /**
     * 百度翻译开放平台（https://api.fanyi.baidu.com/doc/21）
     */
    protected static function baidu($cfg, $text, $from, $to)
    {
        $appid  = trim((string)($cfg['appid'] ?? ''));
        $secret = trim((string)($cfg['secret'] ?? ''));
        if ($appid === '' || $secret === '') {
            return null;
        }
        $salt = (string)mt_rand(100000, 999999);
        $sign = md5($appid . $text . $salt . $secret);
        $data = [
            'q'     => $text,
            'from'  => $from,
            'to'    => $to,
            'appid' => $appid,
            'salt'  => $salt,
            'sign'  => $sign,
        ];
        $resp = self::http('https://fanyi-api.baidu.com/api/trans/vip/translate', $data);
        $json = json_decode($resp, true);
        if (empty($json['trans_result'][0]['dst'])) {
            return null;
        }
        $dst = '';
        foreach ($json['trans_result'] as $seg) {
            $dst .= $seg['dst'];
        }
        return $dst;
    }

    /**
     * 腾讯云机器翻译 TMT（API 3.0，TC3-HMAC-SHA256 签名）
     */
    protected static function tencent($cfg, $text, $from, $to)
    {
        $secretId  = trim((string)($cfg['secret_id'] ?? ''));
        $secretKey = trim((string)($cfg['secret_key'] ?? ''));
        if ($secretId === '' || $secretKey === '') {
            return null;
        }
        $region   = trim((string)($cfg['region'] ?? 'ap-guangzhou'));
        $host     = 'tmt.tencentcloudapi.com';
        $service  = 'tmt';
        $action   = 'TextTranslate';
        $version  = '2018-03-21';
        $now      = time();
        $date     = gmdate('Y-m-d', $now);
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', $now);
        $payload  = json_encode([
            'SourceText' => $text,
            'Source'     => $from,
            'Target'     => $to,
            'ProjectId'  => 0,
        ], JSON_UNESCAPED_UNICODE);
        $canonicalHeaders = "content-type:application/json; charset=utf-8\nhost:{$host}\nx-tc-action:{$action}\n";
        $signedHeaders = 'content-type;host;x-tc-action';
        $hashedPayload = hash('sha256', $payload);
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$hashedPayload}";
        $credentialScope = "{$date}/{$service}/tc3_request";
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        $secretDate    = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
        $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        $resp = self::httpRaw('https://' . $host . '/', $payload, [
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $now,
            'X-TC-Version: ' . $version,
            'X-TC-Region: ' . $region,
        ]);
        $json = json_decode($resp, true);
        return isset($json['Response']['TargetText']) ? $json['Response']['TargetText'] : null;
    }

    /**
     * DeepL API（free / pro 两档 endpoint，自动探测）
     */
    protected static function deepl($cfg, $text, $from, $to)
    {
        $key = trim((string)($cfg['auth_key'] ?? ''));
        if ($key === '') {
            return null;
        }
        $endpoint = 'https://api.deepl.com/v2/translate';
        // free 版 key 以 :fx 结尾
        if (substr($key, -3) === ':fx') {
            $endpoint = 'https://api-free.deepl.com/v2/translate';
        }
        $data = [
            'auth_key'    => $key,
            'text'        => $text,
            'target_lang' => $to,
        ];
        if ($from !== '' && $from !== 'auto') {
            $data['source_lang'] = $from;
        }
        $resp = self::http($endpoint, $data);
        $json = json_decode($resp, true);
        if (empty($json['translations'][0]['text'])) {
            return null;
        }
        return $json['translations'][0]['text'];
    }

    /**
     * OpenAI 兼容 Chat 接口（可填官方或任意兼容中转地址，如 DeepSeek/GLM/Qwen 等）
     */
    protected static function openai($cfg, $text, $from, $to)
    {
        $key   = trim((string)($cfg['api_key'] ?? ''));
        $model = trim((string)($cfg['model'] ?? 'gpt-3.5-turbo'));
        $base  = rtrim((string)($cfg['base_url'] ?? 'https://api.openai.com'), '/');
        if ($key === '') {
            return null;
        }
        $system = "You are a professional translator. Translate the following text from {$from} to {$to}. " .
                  "Only output the translated text, without any explanation, quotes or extra content. " .
                  "Preserve HTML tags, line breaks and special characters exactly.";
        $body = json_encode([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $text],
            ],
            'temperature' => 0.2,
        ], JSON_UNESCAPED_UNICODE);
        $resp = self::httpRaw($base . '/chat/completions', $body, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ]);
        $json = json_decode($resp, true);
        if (empty($json['choices'][0]['message']['content'])) {
            return null;
        }
        return trim($json['choices'][0]['message']['content']);
    }

    /**
     * application/x-www-form-urlencoded POST
     */
    protected static function http($url, array $data)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'maccms12-translate/1.0',
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp === false ? '' : (string)$resp;
    }

    /**
     * 原始 body POST（JSON 等）
     */
    protected static function httpRaw($url, $body, array $headers = [])
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'maccms12-translate/1.0',
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp === false ? '' : (string)$resp;
    }
}
