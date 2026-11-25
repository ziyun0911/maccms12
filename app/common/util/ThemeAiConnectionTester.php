<?php

namespace app\common\util;

/**
 * 主题 AI 设置 - 连接测试
 *
 * 设计约束：
 * 1. 只读探测，绝不写配置文件、不落库、不生成图片（避免产生图像费用）；
 * 2. 文本测试只发一次极小对话请求（约 16 tokens），验证 key / 端点 / 模型 / 协议格式全链路；
 * 3. 图像测试只 GET /models，验证密钥有效性并核对图像模型是否在列表中；
 * 4. 使用当前表单值（允许未保存），密钥留空时回退到磁盘上已保存的密钥；
 * 5. 与 ThemeDesign::themeAiChat() 使用同一套端点解析规则，避免「测试通过、实际生成失败」的假阳性。
 */
class ThemeAiConnectionTester
{
    /** 表单/配置值统一清洗，与 System::configthemeai() 的清洗口径保持一致 */
    private static function s($arr, $key, $default = '')
    {
        if (!is_array($arr) || !array_key_exists($key, $arr)) {
            return $default;
        }
        return trim(strip_tags((string)$arr[$key]));
    }

    /**
     * 归一化配置：$form 优先（页面当前填写值，可能尚未保存），$saved 兜底
     * @return array text / image 两组配置 + notes
     */
    public static function normalize($form, $saved)
    {
        $form = is_array($form) ? $form : array();
        $saved = is_array($saved) ? $saved : array();

        $provider = strtolower(self::s($form, 'provider', self::s($saved, 'provider', 'openai')));
        if ($provider !== 'claude' && $provider !== 'anthropic') {
            $provider = 'openai';
        }

        $base = self::s($form, 'api_base', self::s($saved, 'api_base', ''));
        $base = rtrim($base, '/');
        $model = self::s($form, 'model', self::s($saved, 'model', ''));
        if ($model === '' || preg_match('/[<>{};]/', $model)) {
            $model = $provider === 'openai' ? 'gpt-4o-mini' : 'claude-haiku-4-5-20251001';
        }

        // 密钥：表单新填的优先；留空表示「保持已保存」，回退磁盘配置
        $key = self::s($form, 'api_key', '');
        $keySource = '当前表单填写';
        if ($key === '') {
            $key = self::s($saved, 'api_key', '');
            $keySource = $key !== '' ? '已保存的密钥' : '未配置';
        }

        $timeout = intval(self::s($form, 'timeout', self::s($saved, 'timeout', '60')));
        $timeout = max(10, min(300, $timeout));
        // 连接测试不适合让用户等 300 秒，压到 30 秒上限并在结果里说明
        $probeTimeout = min(30, $timeout);

        $maxTokens = intval(self::s($form, 'max_tokens', self::s($saved, 'max_tokens', '4000')));
        $maxTokens = max(500, min(8000, $maxTokens));

        $textNotes = array();
        $imageNotes = array();
        $ep = self::chatEndpoint($base, $provider);
        if ($ep['note'] !== '') {
            $textNotes[] = $ep['note'];
            $imageNotes[] = $ep['note'];
        }

        // 图像配置：留空时按 ThemeDesign::resolveImageAiConfig() 的规则复用文本配置
        $imgBase = self::s($form, 'image_api_base', self::s($saved, 'image_api_base', ''));
        $imgBase = rtrim($imgBase, '/');
        $imgKey = self::s($form, 'image_api_key', self::s($saved, 'image_api_key', ''));
        $imgBaseSource = '图像专用配置';
        $imgKeySource = '图像专用配置';
        if ($imgBase === '') {
            $imgBase = $base;
            $imgBaseSource = '复用文本 AI 地址';
        }
        if ($imgKey === '') {
            $imgKey = $key;
            $imgKeySource = '复用文本 AI 密钥';
        }
        if ($imgBaseSource === '复用文本 AI 地址' || $imgKeySource === '复用文本 AI 密钥') {
            $imageNotes[] = '图像地址/密钥留空，按主题设计的规则复用了文本 AI 配置；若该端点不提供图像模型，生成 Logo/头像仍会失败。';
        }

        $imgModel = self::s($form, 'image_model', self::s($saved, 'image_model', ''));
        if ($imgModel === '' || preg_match('/[<>{};]/', $imgModel)) {
            $imgModel = 'gpt-image-1';
        }

        return array(
            'text' => array(
                'provider' => $provider,
                'model' => $model,
                'api_base' => $base,
                'api_key' => $key,
                'key_source' => $keySource,
                'timeout' => $probeTimeout,
                'max_tokens' => $maxTokens,
                'endpoint' => $ep,
            ),
            'image' => array(
                'api_base' => $imgBase,
                'api_key' => $imgKey,
                'model' => $imgModel,
                'base_source' => $imgBaseSource,
                'key_source' => $imgKeySource,
                'timeout' => $probeTimeout,
            ),
            'text_notes' => $textNotes,
            'image_notes' => $imageNotes,
        );
    }

    /**
     * 解析文本对话端点。
     * 兼容用户把完整端点（.../v1/chat/completions 或 .../v1/messages）填进 API 地址的情况，
     * 此时不再重复拼接路径，并按端点形态锁定请求格式。
     */
    public static function chatEndpoint($base, $provider)
    {
        $base = rtrim(trim((string)$base), '/');
        if (preg_match('#/chat/completions$#i', $base)) {
            return array(
                'url' => $base,
                'format' => 'openai',
                'note' => 'API 地址已包含 /chat/completions，已直接请求该完整地址；建议把地址填到 /v1 层级即可，端点路径由系统自动补齐。',
            );
        }
        if (preg_match('#/messages$#i', $base)) {
            return array(
                'url' => $base,
                'format' => 'claude',
                'note' => 'API 地址已包含 /messages，已直接请求该完整地址；建议把地址填到 /v1 层级即可，端点路径由系统自动补齐。',
            );
        }
        $claude = ($provider === 'claude' || $provider === 'anthropic');
        return array(
            'url' => $base . ($claude ? '/messages' : '/chat/completions'),
            'format' => $claude ? 'claude' : 'openai',
            'note' => '',
        );
    }

    /** 图像探测端点：从完整业务端点回退到 /v1 层级再拼 /models */
    public static function modelsEndpoint($base)
    {
        $base = rtrim(trim((string)$base), '/');
        if (preg_match('#/models$#i', $base)) {
            return $base;
        }
        $base = preg_replace('#/(chat/completions|messages|images/generations)$#i', '', $base);
        return $base . '/models';
    }

    /** 文本连接测试：真实发一次最小对话请求 */
    public static function testText($cfg, $notes = array())
    {
        $d = array();
        self::detail($d, '测试类型', '文本生成（真实最小对话请求，约 16 tokens，会产生极少量文本费用）');
        self::detail($d, '请求格式', $cfg['endpoint']['format'] === 'claude' ? 'Anthropic /v1/messages' : 'OpenAI 兼容 /chat/completions');
        self::detail($d, '请求地址', $cfg['api_base'] === '' ? '(未填写)' : $cfg['endpoint']['url']);
        self::detail($d, '模型', $cfg['model']);
        self::detail($d, '密钥来源', $cfg['key_source']);
        self::detail($d, '超时上限', $cfg['timeout'] . ' 秒');

        if ($cfg['api_base'] === '') {
            return self::fail('text', '未填写 API 地址', $d, $notes, '请先填写「API 地址」，例如 https://api.openai.com/v1');
        }
        if ($cfg['api_key'] === '') {
            return self::fail('text', '未配置 API 密钥', $d, $notes, '请填写密钥后测试；若已保存过密钥，请勿清空输入框再保存。');
        }
        if (!self::isSafeUrl($cfg['endpoint']['url'])) {
            return self::fail('text', 'API 地址不合法', $d, $notes, '仅支持 http(s):// 开头的地址，且不能内嵌 @ 账号信息或控制字符。');
        }

        $system = 'You are a connectivity test. Reply with exactly one word: OK';
        $format = $cfg['endpoint']['format'];
        if ($format === 'claude') {
            $payload = array(
                'model' => $cfg['model'],
                'max_tokens' => 16,
                'system' => $system,
                'messages' => array(array('role' => 'user', 'content' => 'ping')),
            );
            $headers = array(
                'Content-Type: application/json',
                'x-api-key: ' . $cfg['api_key'],
                'anthropic-version: 2023-06-01',
            );
        } else {
            $payload = array(
                'model' => $cfg['model'],
                'max_tokens' => 16,
                'messages' => array(
                    array('role' => 'system', 'content' => $system),
                    array('role' => 'user', 'content' => 'ping'),
                ),
            );
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['api_key'],
            );
        }

        $res = self::request($cfg['endpoint']['url'], 'POST', json_encode($payload, JSON_UNESCAPED_UNICODE), $headers, $cfg['timeout']);
        self::detail($d, 'HTTP 状态', $res['http_code'] > 0 ? (string)$res['http_code'] : '无响应');
        self::detail($d, '耗时', number_format($res['elapsed'], 2) . ' 秒');

        if ($res['error'] !== '') {
            // 超时/连不上时补一次免费的 /models 探测，区分「端点密钥有问题」与「这个模型不可用」
            $probe = self::probeModels($cfg);
            self::detail($d, '端点探测', $probe['summary']);
            $notes[] = $probe['hint'] !== '' ? $probe['hint'] : '检查服务器能否访问该域名、防火墙是否放行 443、以及 API 地址是否写错。';
            return self::fail('text', '网络请求失败：' . self::curlErrorText($res['errno'], $res['error']), $d, $notes);
        }

        $body = self::mask($res['body'], $cfg['api_key']);
        if ($res['http_code'] < 200 || $res['http_code'] >= 300) {
            $hint = self::httpHint($res['http_code']);
            return self::fail('text', '服务端返回 HTTP ' . $res['http_code'] . '（' . $hint . '）', $d, $notes,
                '响应摘要：' . self::excerpt($body, 300));
        }

        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) {
            return self::fail('text', '响应不是有效 JSON', $d, $notes,
                '响应摘要：' . self::excerpt($body, 300) . '。常见原因：地址填错、被网关/代理拦截、或返回了 HTML 错误页。');
        }
        if (isset($data['error'])) {
            $em = is_array($data['error']) ? (isset($data['error']['message']) ? $data['error']['message'] : json_encode($data['error'], JSON_UNESCAPED_UNICODE)) : (string)$data['error'];
            // 部分推理模型不接受 max_tokens，去掉该参数再试一次，避免误判
            if (stripos((string)$em, 'max_tokens') !== false) {
                unset($payload['max_tokens']);
                $res2 = self::request($cfg['endpoint']['url'], 'POST', json_encode($payload, JSON_UNESCAPED_UNICODE), $headers, $cfg['timeout']);
                $data2 = json_decode((string)$res2['body'], true);
                if (is_array($data2) && !isset($data2['error']) && $res2['http_code'] >= 200 && $res2['http_code'] < 300) {
                    $data = $data2;
                    $notes[] = '该模型不接受 max_tokens 参数，已自动去掉后重试成功；主题设计实际生成时仍会带上该参数，建议更换兼容模型。';
                } else {
                    return self::fail('text', '服务端返回错误：' . mb_substr((string)$em, 0, 240), $d, $notes,
                        '核对模型名称是否在该服务商可用列表内。');
                }
            } else {
                return self::fail('text', '服务端返回错误：' . mb_substr((string)$em, 0, 240), $d, $notes,
                    '核对模型名称与密钥权限。');
            }
        }

        $reply = '';
        $recognized = false;
        if ($format === 'claude') {
            if (isset($data['content']) && is_array($data['content'])) {
                $recognized = true;
                foreach ($data['content'] as $blk) {
                    if (is_array($blk) && isset($blk['text'])) {
                        $reply .= (string)$blk['text'];
                    }
                }
            }
        } else {
            if (isset($data['choices']) && is_array($data['choices'])) {
                $recognized = true;
                $msg = isset($data['choices'][0]['message']) ? $data['choices'][0]['message'] : array();
                if (isset($msg['content']) && (string)$msg['content'] !== '') {
                    $reply = (string)$msg['content'];
                } elseif (isset($msg['reasoning_content'])) {
                    $reply = '(推理模型，仅返回思考内容) ' . mb_substr((string)$msg['reasoning_content'], 0, 80);
                }
            }
        }
        if (!$recognized && !isset($data['usage'])) {
            return self::fail('text', 'HTTP 200 但响应结构不是预期的 AI 结果', $d, $notes,
                '响应摘要：' . self::excerpt($body, 300));
        }

        self::detail($d, '模型回复', trim($reply) === '' ? '(空文本，但请求已被模型接受)' : self::excerpt(trim($reply), 160));
        if (isset($data['usage'])) {
            self::detail($d, 'Token 用量', self::excerpt(json_encode($data['usage'], JSON_UNESCAPED_UNICODE), 120));
        }
        return self::ok('text', '连接成功，模型已正常响应', $d, $notes);
    }

    /** 图像连接测试：只读 /models，不生成图片，不产生图像费用 */
    public static function testImage($cfg, $notes = array())
    {
        $url = self::modelsEndpoint($cfg['api_base']);
        $d = array();
        self::detail($d, '测试类型', '图像服务（只读取 /models 列表，不生成图片、不产生图像费用）');
        self::detail($d, '请求地址', $cfg['api_base'] === '' ? '(未填写)' : $url);
        self::detail($d, '图像模型', $cfg['model']);
        self::detail($d, '地址来源', $cfg['base_source']);
        self::detail($d, '密钥来源', $cfg['key_source']);
        self::detail($d, '超时上限', $cfg['timeout'] . ' 秒');

        if ($cfg['api_base'] === '') {
            return self::fail('image', '未填写图像 API 地址', $d, $notes, '请填写图像 API 地址，或留空以复用文本 AI 地址。');
        }
        if ($cfg['api_key'] === '') {
            return self::fail('image', '未配置图像 API 密钥', $d, $notes, '请填写密钥后测试。');
        }
        if (!self::isSafeUrl($url)) {
            return self::fail('image', 'API 地址不合法', $d, $notes, '仅支持 http(s):// 开头的地址。');
        }

        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $cfg['api_key'],
        );
        $res = self::request($url, 'GET', null, $headers, $cfg['timeout']);
        self::detail($d, 'HTTP 状态', $res['http_code'] > 0 ? (string)$res['http_code'] : '无响应');
        self::detail($d, '耗时', number_format($res['elapsed'], 2) . ' 秒');

        if ($res['error'] !== '') {
            return self::fail('image', '网络请求失败：' . self::curlErrorText($res['errno'], $res['error']), $d, $notes,
                '检查服务器能否访问该域名。');
        }
        $body = self::mask($res['body'], $cfg['api_key']);
        if ($res['http_code'] === 401 || $res['http_code'] === 403) {
            return self::fail('image', '密钥无效或权限不足（HTTP ' . $res['http_code'] . '）', $d, $notes,
                '响应摘要：' . self::excerpt($body, 240));
        }
        if ($res['http_code'] === 404 || $res['http_code'] === 405) {
            return self::fail('image', '该端点未提供 /models 列表（HTTP ' . $res['http_code'] . '）', $d, $notes,
                '密钥可能有效，但无法在不生成图片的前提下验证图像模型。请到「主题设计 → AI 生成 Logo」实测，或改用支持 /models 的 OpenAI 兼容端点。');
        }
        if ($res['http_code'] < 200 || $res['http_code'] >= 300) {
            return self::fail('image', '服务端返回 HTTP ' . $res['http_code'], $d, $notes,
                '响应摘要：' . self::excerpt($body, 240));
        }

        $data = json_decode((string)$res['body'], true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            return self::fail('image', 'HTTP 200 但 /models 响应格式无法识别', $d, $notes,
                '响应摘要：' . self::excerpt($body, 300));
        }

        $ids = array();
        foreach ($data['data'] as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (string)$row['id'];
            } elseif (is_string($row)) {
                $ids[] = $row;
            }
        }
        self::detail($d, '可用模型数', (string)count($ids) . ' 个');
        $found = in_array($cfg['model'], $ids, true);
        self::detail($d, '图像模型可用', $found ? '是（模型列表中存在）' : '未在列表中找到');
        if (!$found) {
            $notes[] = '端点可访问、密钥有效，但模型列表中没有「' . $cfg['model'] . '」；实际生成 Logo/头像时可能报模型不存在。相近模型：' . self::similarModels($cfg['model'], $ids);
        } else {
            $notes[] = '连接与密钥均有效；本测试只读取模型列表，未调用图像生成接口。';
        }
        return self::ok('image', '连接成功，密钥有效且模型列表可读', $d, $notes);
    }

    /**
     * 文本请求失败时的辅助探测：只读 /models（免费），用于定位是密钥/端点问题还是模型问题
     */
    private static function probeModels($cfg)
    {
        $url = self::modelsEndpoint($cfg['api_base']);
        if (!self::isSafeUrl($url)) {
            return array('summary' => '未执行（地址不合法）', 'hint' => '');
        }
        $headers = array('Accept: application/json');
        if ($cfg['endpoint']['format'] === 'claude') {
            $headers[] = 'x-api-key: ' . $cfg['api_key'];
            $headers[] = 'anthropic-version: 2023-06-01';
        } else {
            $headers[] = 'Authorization: Bearer ' . $cfg['api_key'];
        }
        $r = self::request($url, 'GET', null, $headers, 8);
        if ($r['error'] !== '' || $r['http_code'] < 200 || $r['http_code'] >= 300) {
            return array('summary' => 'GET /models → ' . ($r['http_code'] > 0 ? 'HTTP ' . $r['http_code'] : '网络失败'), 'hint' => '');
        }
        $j = json_decode((string)$r['body'], true);
        if (!is_array($j) || !isset($j['data']) || !is_array($j['data'])) {
            return array('summary' => 'GET /models → HTTP 200（格式无法识别）', 'hint' => '');
        }
        $ids = array();
        foreach ($j['data'] as $row) {
            if (is_array($row) && isset($row['id'])) {
                $ids[] = (string)$row['id'];
            }
        }
        $has = in_array($cfg['model'], $ids, true);
        return array(
            'summary' => 'GET /models → HTTP 200，' . count($ids) . ' 个可用模型；当前模型' . ($has ? '在列表中' : '不在列表中'),
            'hint' => $has
                ? '端点与密钥正常、模型也在可用列表里；对话请求仍失败，通常是该模型冷启动慢或服务端繁忙，建议稍后重试或换用更轻量的模型。'
                : '端点与密钥正常，但可用模型列表中没有「' . $cfg['model'] . '」；请从 /models 返回的模型里选一个填到「模型」栏。',
        );
    }

    /** 底层 HTTP 请求：带状态码、耗时与 curl 错误，便于给出可操作的诊断 */
    private static function request($url, $method, $payload, $headers, $timeout)
    {
        $out = array('http_code' => 0, 'body' => '', 'error' => '', 'errno' => 0, 'elapsed' => 0.0);
        $ch = @curl_init();
        if ($ch === false) {
            $out['error'] = 'curl_init 失败';
            return $out;
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        // 限制重定向协议，避免被跳转到 file:// 等非 HTTP 协议
        if (defined('CURLOPT_REDIRECT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_REDIRECT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(2, min(10, (int)$timeout)));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(3, (int)$timeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // 与站点公共 HTTP 客户端一致：使用系统原生 CA，避免本机 php.ini 缺 CA 导致误报
        \app\common\util\HttpClient::applyNativeCa($ch);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MacCMS-ThemeAI-ConnectTest/1.0');
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        if (is_array($headers) && !empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $t0 = microtime(true);
        $body = @curl_exec($ch);
        $out['elapsed'] = microtime(true) - $t0;
        $out['errno'] = (int)curl_errno($ch);
        $out['error'] = (string)curl_error($ch);
        $out['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        if ($body === false) {
            $body = '';
        }
        $out['body'] = (string)$body;
        return $out;
    }

    private static function isSafeUrl($url)
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        if (strpos($url, '@') !== false || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }
        $p = parse_url($url);
        return is_array($p) && !empty($p['host']);
    }

    private static function curlErrorText($errno, $error)
    {
        $map = array(
            6 => 'DNS 解析失败',
            7 => '无法建立连接（目标拒绝连接或端口不通）',
            28 => '连接或读取超时',
            35 => 'SSL/TLS 握手失败',
            51 => 'SSL 证书校验失败',
            60 => 'SSL 证书校验失败（本地 CA 证书缺失或过期）',
        );
        if (isset($map[$errno])) {
            return $map[$errno];
        }
        return $error !== '' ? mb_substr($error, 0, 160) : '未知 curl 错误（' . $errno . '）';
    }

    private static function httpHint($code)
    {
        $map = array(
            400 => '请求参数有误，常见为模型名不支持',
            401 => '密钥无效',
            403 => '无权限或地区限制',
            404 => '端点不存在，检查 API 地址',
            405 => '请求方法不被允许',
            408 => '服务端超时',
            413 => '请求体过大',
            422 => '参数校验失败',
            429 => '触发限流',
            500 => '服务端内部错误',
            502 => '网关错误',
            503 => '服务暂不可用',
            504 => '网关超时',
        );
        return isset($map[$code]) ? $map[$code] : '请查看响应摘要';
    }

    private static function similarModels($model, $ids)
    {
        $hits = array();
        $kw = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$model));
        foreach ($ids as $id) {
            if (stripos($id, 'image') !== false || stripos($id, 'dall') !== false || stripos($id, 'flux') !== false
                || ($kw !== '' && stripos(strtolower($id), substr($kw, 0, 6)) !== false)) {
                $hits[] = $id;
                if (count($hits) >= 5) {
                    break;
                }
            }
        }
        return empty($hits) ? '（列表中没有明显匹配的图像模型）' : implode('、', $hits);
    }

    /** 响应体脱敏：避免把密钥回显到后台页面 */
    private static function mask($text, $key)
    {
        $text = (string)$text;
        if ($key !== '') {
            $text = str_replace($key, '***', $text);
        }
        return $text;
    }

    private static function excerpt($text, $len)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        if ($text === '') {
            return '(空响应)';
        }
        return mb_substr($text, 0, $len) . (mb_strlen($text) > $len ? '…' : '');
    }

    private static function detail(&$arr, $k, $v)
    {
        $arr[] = array('k' => (string)$k, 'v' => (string)$v);
    }

    private static function ok($kind, $msg, $details, $notes)
    {
        return array('ok' => true, 'kind' => $kind, 'msg' => $msg, 'details' => $details, 'notes' => array_values(array_unique($notes)));
    }

    private static function fail($kind, $msg, $details, $notes, $hint = '')
    {
        if ($hint !== '') {
            $notes[] = $hint;
        }
        return array('ok' => false, 'kind' => $kind, 'msg' => $msg, 'details' => $details, 'notes' => array_values(array_unique($notes)));
    }
}
