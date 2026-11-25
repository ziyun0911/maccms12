<?php
namespace app\common\util;

use think\facade\Cache;

class AdminAssistantService
{
    const KNOWLEDGE_DIR = 'data/admin_assistant';

    public function getMergedConfig()
    {
        // 服务商信息（provider / api_base / api_key / model）唯一来源：主题 AI 设置 theme_ai。
        // 本配置块只保存助手自己的行为参数，更换主题 AI 服务商后助手自动跟随。
        // 直读磁盘配置，避免框架配置缓存导致「主题 AI 刚保存、助手还用旧服务商」的窗口。
        $cfgFile = APP_PATH . 'extra/maccms.php';
        $disk = is_file($cfgFile) ? include $cfgFile : [];
        if (!is_array($disk)) {
            $disk = [];
        }
        $cfg = config('maccms.admin_assistant');
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $theme = config('maccms.theme_ai');
        if (!is_array($theme)) {
            $theme = [];
        }
        if (isset($disk['admin_assistant']) && is_array($disk['admin_assistant'])) {
            $cfg = $disk['admin_assistant'];
        }
        if (isset($disk['theme_ai']) && is_array($disk['theme_ai'])) {
            $theme = $disk['theme_ai'];
        }

        // 助手独有：功能开关与调用预算，与服务商无关
        $cfg = array_merge([
            'enabled' => '0',
            'access_scope' => 'all',
            'timeout' => '45',
            'max_tokens' => '1200',
            'include_env_snapshot' => '1',
            'rate_per_minute' => '20',
            'retrieve_chunks' => '8',
        ], $cfg);

        $provider = strtolower(trim((string)(isset($theme['provider']) ? $theme['provider'] : 'openai')));
        if ($provider !== 'claude' && $provider !== 'anthropic') {
            $provider = 'openai';
        }
        $model = trim((string)(isset($theme['model']) ? $theme['model'] : ''));
        if ($model === '') {
            $model = $provider === 'openai' ? 'gpt-4o-mini' : 'claude-haiku-4-5-20251001';
        }

        // 覆盖任何历史遗留值，保证旧配置不会把请求带回旧服务商
        $cfg['provider'] = $provider;
        $cfg['api_base'] = rtrim(trim((string)(isset($theme['api_base']) ? $theme['api_base'] : '')), '/');
        $cfg['api_key'] = trim((string)(isset($theme['api_key']) ? $theme['api_key'] : ''));
        $cfg['model'] = $model;
        $cfg['ai_source'] = 'theme_ai';
        unset($cfg['use_ai_search_credentials']);
        return $cfg;
    }

    public function isEnabled()
    {
        $cfg = $this->getMergedConfig();
        return (string)$cfg['enabled'] === '1' && trim((string)$cfg['api_key']) !== '';
    }

    public function chat($question, array $history = [])
    {
        $cfg = $this->getMergedConfig();
        if ((string)$cfg['enabled'] !== '1') {
            return ['code' => 1003, 'msg' => lang('admin/assistant/err_disabled')];
        }
        $apiKey = trim((string)$cfg['api_key']);
        if ($apiKey === '') {
            return ['code' => 1004, 'msg' => lang('admin/assistant/err_no_key')];
        }

        $question = trim(strip_tags((string)$question));
        if ($question === '') {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        if (mb_strlen($question, 'UTF-8') > 4000) {
            $question = mb_substr($question, 0, 4000, 'UTF-8');
        }
        if ($this->looksLikeSecretPaste($question)) {
            return ['code' => 1005, 'msg' => lang('admin/assistant/err_secrets')];
        }

        $chunks = $this->loadKnowledgeChunks();
        $top = $this->retrieveTopChunks($question, $chunks, max(1, min(12, intval($cfg['retrieve_chunks']))));
        $kb = $this->formatKnowledgeForPrompt($top);

        $envBlock = '';
        if ((string)$cfg['include_env_snapshot'] === '1') {
            $envBlock = $this->buildEnvSnapshot();
        }

        $system = $this->buildSystemPrompt($kb, $envBlock);
        // 对话轮次与 system 分开存，便于按主题 AI 的服务商格式组装请求体
        $turns = [];
        $tail = array_slice($history, -10);
        foreach ($tail as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = isset($row['role']) ? (string)$row['role'] : '';
            $content = isset($row['content']) ? trim(strip_tags((string)$row['content'])) : '';
            if ($content === '' || mb_strlen($content, 'UTF-8') > 8000) {
                continue;
            }
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }
            $turns[] = ['role' => $role, 'content' => $content];
        }
        $turns[] = ['role' => 'user', 'content' => $question];

        $apiBase = rtrim((string)$cfg['api_base'], '/');
        // 与「主题 AI 设置 → 测试连接」和「主题设计」共用端点解析：
        // 地址含完整端点时不再拼接，切换 openai/claude 服务商后请求格式自动跟随
        $endpoint = ThemeAiConnectionTester::chatEndpoint($apiBase, (string)$cfg['provider']);
        $url = $endpoint['url'];
        if (!$this->isSafeEndpoint($url)) {
            return ['code' => 1006, 'msg' => '主题 AI 的 API 地址不合法：' . (string)$cfg['api_base']
                . '（请到「主题 AI 设置」填写 http:// 或 https:// 开头的地址）'];
        }
        $timeout = max(8, intval($cfg['timeout']));
        $maxTokens = max(256, min(4096, intval($cfg['max_tokens'])));
        if ($endpoint['format'] === 'claude') {
            // Anthropic /v1/messages：system 是独立字段，密钥走 x-api-key
            $post = [
                'model' => (string)$cfg['model'],
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => $turns,
                'temperature' => 0.3,
            ];
            $headers = [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ];
        } else {
            // OpenAI 兼容 /chat/completions：system 作为第一条消息
            $post = [
                'model' => (string)$cfg['model'],
                'temperature' => 0.3,
                'max_tokens' => $maxTokens,
                'messages' => array_merge([['role' => 'system', 'content' => $system]], $turns),
            ];
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ];
        }
        $res = HttpClient::curlPostWithDiagnostics($url, json_encode($post, JSON_UNESCAPED_UNICODE), $headers, $timeout);
        if ($res['errno'] !== 0 || $res['http_code'] === 0) {
            return ['code' => 1006, 'msg' => '上游请求失败：' . self::curlReason($res) . '（地址 ' . $url . '）'];
        }
        $body = $res['body'];
        if ($body === '') {
            return ['code' => 1006, 'msg' => '上游返回空响应（HTTP ' . $res['http_code'] . '，地址 ' . $url . '）'];
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            if ($res['http_code'] < 200 || $res['http_code'] >= 300) {
                return ['code' => 1006, 'msg' => '上游返回 HTTP ' . $res['http_code'] . '（'
                    . self::httpHint($res['http_code']) . '）：' . self::excerpt($body, 160)];
            }
            return ['code' => 1007, 'msg' => lang('admin/assistant/err_bad_response')];
        }
        if (isset($json['error'])) {
            $em = isset($json['error']['message']) ? (string)$json['error']['message'] : 'error';
            return ['code' => 1008, 'msg' => 'HTTP ' . $res['http_code'] . '：' . mb_substr($em, 0, 500, 'UTF-8')];
        }
        if ($res['http_code'] < 200 || $res['http_code'] >= 300) {
            return ['code' => 1006, 'msg' => '上游返回 HTTP ' . $res['http_code'] . '（'
                . self::httpHint($res['http_code']) . '），响应中没有错误信息'];
        }
        $reply = '';
        if ($endpoint['format'] === 'claude' && isset($json['content']) && is_array($json['content'])) {
            // Anthropic 返回 content 文本块数组
            foreach ($json['content'] as $blk) {
                if (is_array($blk) && isset($blk['text'])) {
                    $reply .= (string)$blk['text'];
                }
            }
            $reply = trim($reply);
        } elseif (isset($json['choices'][0]['message']['content'])) {
            $reply = trim((string)$json['choices'][0]['message']['content']);
        }
        if ($reply === '') {
            return ['code' => 1007, 'msg' => lang('admin/assistant/err_bad_response')];
        }

        $citations = [];
        foreach ($top as $t) {
            $citations[] = ['file' => $t['file'], 'title' => $t['title']];
        }
        return ['code' => 1, 'reply' => $reply, 'citations' => $citations];
    }

    public function consumeRateLimit($adminId, $perMinute)
    {
        $adminId = (int)$adminId;
        $perMinute = max(1, min(120, (int)$perMinute));
        if ($adminId <= 0) {
            return false;
        }
        $bucket = intval(time() / 60);
        $key = 'admin_assistant_rl:' . $adminId . ':' . $bucket;
        $n = intval(Cache::get($key, 0));
        if ($n >= $perMinute) {
            return false;
        }
        Cache::set($key, $n + 1, 70);
        return true;
    }

    /** API 地址白名单：仅 http(s)，禁止内嵌账号信息与控制字符 */
    private function isSafeEndpoint($url)
    {
        if (!preg_match('#^https?://#i', $url) || strpos($url, '@') !== false
            || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }
        $p = parse_url($url);
        return is_array($p) && !empty($p['host']);
    }

    /** 把 curl 错误码翻译成可操作的原因 */
    private static function curlReason($res)
    {
        $map = [
            1 => '不支持的协议（地址缺少 http:// 或 https://）',
            3 => 'URL 格式错误',
            6 => 'DNS 解析失败',
            7 => '无法建立连接（目标拒绝或端口不通）',
            28 => '连接或读取超时',
            35 => 'SSL/TLS 握手失败',
            51 => 'SSL 证书校验失败',
            60 => 'SSL 证书校验失败（本地 CA 缺失或过期）',
        ];
        if (isset($map[$res['errno']])) {
            return $map[$res['errno']];
        }
        if ((string)$res['error'] !== '') {
            return mb_substr((string)$res['error'], 0, 120, 'UTF-8');
        }
        return '未收到 HTTP 响应（curl errno ' . $res['errno'] . '）';
    }

    private static function httpHint($code)
    {
        $map = [
            400 => '请求参数有误', 401 => '密钥无效', 403 => '无权限或地区限制',
            404 => '端点或模型不存在', 405 => '请求方法不被允许', 408 => '服务端超时',
            413 => '请求体过大', 422 => '参数校验失败', 429 => '触发限流',
            500 => '服务端内部错误', 502 => '网关错误', 503 => '服务暂不可用', 504 => '网关超时',
        ];
        return isset($map[$code]) ? $map[$code] : '请查看响应内容';
    }

    private static function excerpt($text, $len)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        if ($text === '') {
            return '(空响应)';
        }
        return mb_substr($text, 0, $len, 'UTF-8') . (mb_strlen($text, 'UTF-8') > $len ? '…' : '');
    }

    private function looksLikeSecretPaste($text)
    {
        if (preg_match('/\bsk-[a-zA-Z0-9]{10,}\b/', $text)) {
            return true;
        }
        if (preg_match('/api[_-]?key\s*[:=]\s*\S{12,}/i', $text)) {
            return true;
        }
        if (preg_match('/BEGIN\s+RSA\s+PRIVATE\s+KEY/', $text)) {
            return true;
        }
        return false;
    }

    private function loadKnowledgeChunks()
    {
        $dir = APP_PATH . self::KNOWLEDGE_DIR;
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.md') ?: [] as $path) {
            $base = basename($path);
            $raw = @file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $title = $base;
            if (preg_match('/^#\s+(.+)$/m', $raw, $m)) {
                $title = trim($m[1]);
            }
            $out[] = [
                'file' => $base,
                'title' => $title,
                'body' => $raw,
            ];
        }
        return $out;
    }

    private function retrieveTopChunks($query, array $chunks, $k)
    {
        if (empty($chunks)) {
            return [];
        }
        $q = mb_strtolower(trim((string)$query), 'UTF-8');
        $terms = $this->buildSearchTerms($q);

        $scored = [];
        foreach ($chunks as $ch) {
            $hay = mb_strtolower($ch['title'] . "\n" . $ch['body'], 'UTF-8');
            $score = 0;
            if ($q !== '' && mb_strpos($hay, $q, 0, 'UTF-8') !== false) {
                $score += 24;
            }
            $matched = 0;
            foreach ($terms as $term) {
                if (mb_strpos($hay, $term, 0, 'UTF-8') !== false) {
                    $score += mb_strlen($term, 'UTF-8') > 2 ? 3 : 2;
                    $matched++;
                }
            }
            $score += min(10, $matched);
            if ($score > 0) {
                $scored[] = ['score' => $score, 'chunk' => $ch];
            }
        }
        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        if (empty($scored)) {
            return array_slice($chunks, 0, $k);
        }
        $pick = [];
        foreach ($scored as $row) {
            $pick[] = $row['chunk'];
            if (count($pick) >= $k) {
                break;
            }
        }
        return $pick;
    }

    private function buildSearchTerms($q)
    {
        $terms = [];
        if ($q !== '') {
            $terms[] = $q;
        }
        $words = preg_split('/[\s\p{P}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $w) {
            if (mb_strlen($w, 'UTF-8') > 1) {
                $terms[] = $w;
                if (preg_match('/^[a-z]{4,}$/', $w) && substr($w, -1) === 's') {
                    $terms[] = substr($w, 0, -1);
                }
            }
        }

        $compact = preg_replace('/[\s\p{P}]+/u', '', $q);
        if ($compact !== null && preg_match('/[\x{4e00}-\x{9fff}]/u', $compact)) {
            $len = mb_strlen($compact, 'UTF-8');
            for ($i = 0; $i < $len - 1; $i++) {
                $terms[] = mb_substr($compact, $i, 2, 'UTF-8');
            }
        }
        $terms = array_values(array_unique(array_filter($terms, function ($w) {
            return mb_strlen($w, 'UTF-8') > 1;
        })));
        return $terms;
    }

    private function formatKnowledgeForPrompt(array $top)
    {
        $parts = [];
        foreach ($top as $ch) {
            $parts[] = "### FILE: " . $ch['file'] . "\n" . $ch['body'];
        }
        return implode("\n\n---\n\n", $parts);
    }

    private function buildEnvSnapshot()
    {
        $c = $GLOBALS['config'] ?? [];
        if (!is_array($c)) {
            return '(no runtime config)';
        }
        $app = isset($c['app']) && is_array($c['app']) ? $c['app'] : [];
        $site = isset($c['site']) && is_array($c['site']) ? $c['site'] : [];
        $safe = [
            'php_version' => PHP_VERSION,
            'search_frontend' => (string)(isset($app['search']) ? $app['search'] : ''),
            'lang' => (string)(isset($app['lang']) ? $app['lang'] : ''),
            'cache_type' => (string)(isset($app['cache_type']) ? $app['cache_type'] : ''),
            'site_name' => (string)(isset($site['site_name']) ? $site['site_name'] : ''),
            'template_dir' => (string)(isset($site['template_dir']) ? $site['template_dir'] : ''),
        ];
        return json_encode($safe, JSON_UNESCAPED_UNICODE);
    }

    private function buildMenuI18nBlock()
    {
        $raw = mac_site_lang();
        $langId = preg_replace('/[^a-z0-9_-]/', '', $raw);
        if ($langId === '') {
            $langId = 'zh-cn';
        }
        $path = APP_PATH . 'lang/' . $langId . '.php';
        if (!is_file($path)) {
            return "locale={$langId}\n(menu language file missing)";
        }
        $dict = include $path;
        if (!is_array($dict)) {
            return "locale={$langId}\n(menu language file invalid)";
        }
        $lines = [];
        foreach ($dict as $key => $label) {
            if (!is_string($key) || strpos($key, 'menu/') !== 0) {
                continue;
            }
            if (!is_scalar($label)) {
                continue;
            }
            $text = trim((string)$label);
            if ($text === '') {
                continue;
            }
            $lines[$key] = $key . ': ' . $text;
        }
        if ($lines === []) {
            return "locale={$langId}\n(no menu/* keys)";
        }
        ksort($lines);

        return "locale={$langId}\n" . implode("\n", $lines);
    }

    private function buildSystemPrompt($knowledgeBlock, $envBlock)
    {
        $replyLang = trim((string)lang('admin/assistant/prompt_reply_language'));
        if ($replyLang === '' || $replyLang === 'admin/assistant/prompt_reply_language') {
            $replyLang = 'Match the admin UI language (see ENV_SNAPSHOT.lang). Write your **entire** answer in that language unless the user explicitly requests another.';
        }

        $menuLabels = $this->buildMenuI18nBlock();

        return implode("\n", [
            'You are the administrative help assistant for maccms (苹果CMS / MacCMS), a PHP content management system.',
            $replyLang,
            'Answer only using KNOWLEDGE_BASE and ENV_SNAPSHOT. If something is not covered, say so and suggest a high-level area using **only** labels from ADMIN_MENU_LABELS (never invent menu names).',
            'ADMIN_MENU_LABELS lists the exact sidebar/menu titles for this admin locale (keys are `menu/...` language-pack entries). KNOWLEDGE_BASE often cites those keys after `/` — always resolve them through ADMIN_MENU_LABELS.',
            'For every admin path you mention: use ADMIN_MENU_LABELS text only. Example: KNOWLEDGE_BASE says `menu/system` alongside `menu/config` → output `LABEL(menu/system)` + \' → \' + `LABEL(menu/config)`. If KNOWLEDGE_BASE shows Chinese or English prose like `系统 → …` alongside a `menu/...` key, **ignore that prose for names** — use ADMIN_MENU_LABELS strings instead.',
            'Do not invent or guess menu wording; only use ADMIN_MENU_LABELS plus logical ` → ` joins between parent sidebar group and submenu when KNOWLEDGE_BASE implies that structure.',
            'For how-to questions, give concrete numbered steps (1. 2. 3.) matching the workflow in KNOWLEDGE_BASE. Mention prerequisites (e.g. create category before adding content).',
            'Never request or repeat API keys, passwords, database credentials, or full config file contents.',
            'Do not instruct users to disable security, delete data blindly, or expose server paths beyond what is in KNOWLEDGE_BASE.',
            'You cannot execute changes; only explain steps.',
            '',
            'ENV_SNAPSHOT (non-secret flags only):',
            $envBlock,
            '',
            'ADMIN_MENU_LABELS:',
            $menuLabels,
            '',
            'KNOWLEDGE_BASE:',
            $knowledgeBlock,
        ]);
    }
}
