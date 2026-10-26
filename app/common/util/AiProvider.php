<?php
namespace app\common\util;

class AiProvider
{
    public static function resolveConfig()
    {
        $cfg = config('maccms');
        $ai = isset($cfg['ai_content']) && is_array($cfg['ai_content']) ? $cfg['ai_content'] : [];

        $out = [
            'enabled' => (string)(isset($ai['enabled']) ? $ai['enabled'] : '0') === '1',
            'provider' => strtolower(trim((string)(isset($ai['provider']) ? $ai['provider'] : 'openai'))),
            'model' => trim((string)(isset($ai['model']) ? $ai['model'] : 'gpt-4o-mini')),
            'api_base' => rtrim(trim((string)(isset($ai['api_base']) ? $ai['api_base'] : '')), '/'),
            'api_key' => trim((string)(isset($ai['api_key']) ? $ai['api_key'] : '')),
            'timeout' => max(5, intval(isset($ai['timeout']) ? $ai['timeout'] : 30)),
            'max_tokens' => max(256, intval(isset($ai['max_tokens']) ? $ai['max_tokens'] : 800)),
            'batch_size' => max(1, min(100, intval(isset($ai['batch_size']) ? $ai['batch_size'] : 20))),
            'auto_adopt_empty' => (string)(isset($ai['auto_adopt_empty']) ? $ai['auto_adopt_empty'] : '0') === '1',
        ];

        $inherit = (string)(isset($ai['use_ai_search_credentials']) ? $ai['use_ai_search_credentials'] : '0') === '1';
        if ($inherit && isset($cfg['ai_search']) && is_array($cfg['ai_search'])) {
            $src = $cfg['ai_search'];
            if ($out['api_key'] === '' && !empty($src['api_key'])) {
                $out['api_key'] = trim((string)$src['api_key']);
            }
            if ($out['api_base'] === '' && !empty($src['api_base'])) {
                $out['api_base'] = rtrim(trim((string)$src['api_base']), '/');
            }
        }

        if ($out['api_base'] === '') {
            $out['api_base'] = self::defaultBase($out['provider']);
        }
        return $out;
    }

    private static function defaultBase($provider)
    {
        $map = [
            'openai' => 'https://api.openai.com/v1',
            'claude' => 'https://api.anthropic.com/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
            'deepseek' => 'https://api.deepseek.com/v1',
            'qwen' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'glm' => 'https://open.bigmodel.cn/api/paas/v4',
        ];
        return isset($map[$provider]) ? $map[$provider] : $map['openai'];
    }

    public static function buildRequest($cfg, $systemPrompt, $userPrompt)
    {
        $provider = isset($cfg['provider']) ? strtolower((string)$cfg['provider']) : 'openai';
        $model = (string)$cfg['model'];
        $maxTokens = max(256, intval(isset($cfg['max_tokens']) ? $cfg['max_tokens'] : 800));

        if ($provider === 'claude') {
            return [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => (string)$systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => (string)$userPrompt],
                ],
            ];
        }

        if ($provider === 'gemini') {
            return [
                'contents' => [
                    ['parts' => [['text' => (string)$systemPrompt . "\n\n" . (string)$userPrompt]]],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $maxTokens,
                    'temperature' => 0.4,
                ],
            ];
        }

        return [
            'model' => $model,
            'temperature' => 0.4,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => (string)$systemPrompt],
                ['role' => 'user', 'content' => (string)$userPrompt],
            ],
        ];
    }

    public static function extractText($cfg, $respBody)
    {
        $respBody = (string)$respBody;
        if ($respBody === '') {
            return '';
        }
        $json = json_decode($respBody, true);
        if (!is_array($json)) {
            return '';
        }
        $provider = isset($cfg['provider']) ? strtolower((string)$cfg['provider']) : 'openai';

        if ($provider === 'claude') {
            if (!isset($json['content']) || !is_array($json['content'])) {
                return '';
            }
            foreach ($json['content'] as $block) {
                if (is_array($block) && isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    return (string)$block['text'];
                }
            }
            return '';
        }

        if ($provider === 'gemini') {
            if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                return (string)$json['candidates'][0]['content']['parts'][0]['text'];
            }
            return '';
        }

        if (isset($json['choices'][0]['message']['content'])) {
            return (string)$json['choices'][0]['message']['content'];
        }
        return '';
    }

    private static function endpoint($cfg)
    {
        $provider = strtolower((string)$cfg['provider']);
        $base = rtrim((string)$cfg['api_base'], '/');
        if ($provider === 'claude') {
            return $base . '/messages';
        }
        if ($provider === 'gemini') {
            return $base . '/models/' . rawurlencode((string)$cfg['model']) . ':generateContent?key=' . rawurlencode((string)$cfg['api_key']);
        }
        return $base . '/chat/completions';
    }

    private static function headers($cfg)
    {
        $provider = strtolower((string)$cfg['provider']);
        $headers = ['Content-Type: application/json'];
        if ($provider === 'claude') {
            $headers[] = 'x-api-key: ' . (string)$cfg['api_key'];
            $headers[] = 'anthropic-version: 2023-06-01';
            return $headers;
        }
        if ($provider === 'gemini') {
            return $headers;
        }
        $headers[] = 'Authorization: Bearer ' . (string)$cfg['api_key'];
        return $headers;
    }

    private static function curlPost($url, $data, $heads, $timeout, $verifySsl = true)
    {
        $ch = @curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.101 Safari/537.36');
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl ? 1 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 15));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        if (count($heads) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $heads);
        }
        $response = @curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    public static function chat($cfg, $systemPrompt, $userPrompt)
    {
        if (empty($cfg['enabled'])) {
            return ['code' => 0, 'msg' => 'ai_content disabled', 'text' => ''];
        }
        if ((string)$cfg['api_key'] === '') {
            return ['code' => 0, 'msg' => 'api_key missing', 'text' => ''];
        }

        $body = json_encode(self::buildRequest($cfg, $systemPrompt, $userPrompt), JSON_UNESCAPED_UNICODE);
        $verifySsl = !isset($cfg['verify_ssl']) || (string)$cfg['verify_ssl'] !== '0';
        $resp = self::curlPost(self::endpoint($cfg), $body, self::headers($cfg), intval($cfg['timeout']), $verifySsl);
        if ($resp === false || (string)$resp === '') {
            return ['code' => 0, 'msg' => 'empty ai response', 'text' => ''];
        }
        $text = self::extractText($cfg, $resp);
        if ($text === '') {
            return ['code' => 0, 'msg' => 'invalid ai response', 'text' => ''];
        }
        return ['code' => 1, 'msg' => '', 'text' => $text];
    }
}
