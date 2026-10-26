<?php
namespace app\common\util;

use think\facade\Cache;

class AiEmbeddingService
{
    const DEFAULT_CACHE_TTL = 86400;

    private static $memo = [];

    private static $llmGate = null;

    private static $llmRecorder = null;

    public static function setLlmGate($gate, $recorder)
    {
        self::$llmGate = is_callable($gate) ? $gate : null;
        self::$llmRecorder = is_callable($recorder) ? $recorder : null;
    }

    public static function semanticEnabled(array $cfg)
    {
        return (string)(isset($cfg['semantic_enabled']) ? $cfg['semantic_enabled'] : '0') === '1';
    }

    public static function rerank($queryText, array $items, array $cfg, callable $snippetOf, array $opts = [])
    {
        if (empty($items) || !self::semanticEnabled($cfg)) {
            return $items;
        }
        $queryText = trim((string)$queryText);
        if ($queryText === '') {
            return $items;
        }
        $candidateLimit = max(3, intval(isset($cfg['semantic_candidates']) ? $cfg['semantic_candidates'] : 40));
        $weight = self::clampWeight(isset($cfg['semantic_weight']) ? $cfg['semantic_weight'] : 0.45);
        $items = array_slice($items, 0, $candidateLimit);

        $inputs = [$queryText];
        foreach ($items as $item) {
            $inputs[] = (string)$snippetOf($item);
        }
        $vectors = self::requestEmbeddings($inputs, $cfg);
        if (!is_array($vectors) || count($vectors) !== count($inputs)) {
            return $items;
        }

        $baseOf = (isset($opts['base']) && is_callable($opts['base'])) ? $opts['base'] : null;
        $tie = (isset($opts['tie']) && is_callable($opts['tie'])) ? $opts['tie'] : null;
        $scoreKey = isset($opts['scoreKey']) ? (string)$opts['scoreKey'] : '';

        $qv = $vectors[0];
        $ranked = [];
        foreach ($items as $idx => $item) {
            $sim = self::cosineSimilarity($qv, $vectors[$idx + 1]);
            $base = $baseOf !== null ? floatval($baseOf($item, $idx)) : max(0.35, 1.0 - $idx * 0.06);
            $score = ($base * (1.0 - $weight)) + ($sim * $weight * 3.0);
            if ($scoreKey !== '') {
                $item[$scoreKey] = $score;
            }
            $ranked[] = ['item' => $item, 'score' => $score, 'idx' => $idx];
        }
        usort($ranked, function ($a, $b) use ($tie) {
            if ($a['score'] === $b['score']) {
                if ($tie !== null) {
                    $t = (int)$tie($a['item'], $b['item']);
                    if ($t !== 0) {
                        return $t;
                    }
                }
                return $a['idx'] <=> $b['idx'];
            }
            return ($b['score'] > $a['score']) ? 1 : -1;
        });

        $out = [];
        foreach ($ranked as $r) {
            $out[] = $r['item'];
        }
        return $out;
    }

    public static function requestEmbeddings(array $inputs, array $cfg)
    {
        $inputs = array_values($inputs);
        if (empty($inputs)) {
            return [];
        }
        $provider = strtolower(trim((string)(isset($cfg['provider']) ? $cfg['provider'] : '')));
        $apiKey = trim((string)(isset($cfg['api_key']) ? $cfg['api_key'] : ''));
        if ($provider !== 'openai' || $apiKey === '') {
            return null;
        }
        $model = trim((string)(isset($cfg['embedding_model']) ? $cfg['embedding_model'] : 'text-embedding-3-small'));
        if ($model === '') {
            $model = 'text-embedding-3-small';
        }
        $ttl = self::cacheTtl($cfg);

        $vectors = array_fill(0, count($inputs), null);
        $missIdx = [];
        $missInputs = [];
        foreach ($inputs as $i => $text) {
            $text = (string)$text;
            $hit = self::cacheGet(self::cacheKey($model, $text));
            if (is_array($hit) && !empty($hit)) {
                $vectors[$i] = $hit;
            } else {
                $missIdx[] = $i;
                $missInputs[] = $text;
            }
        }

        if (!empty($missInputs)) {
            $fetched = self::httpEmbeddings($missInputs, $cfg, $model, $apiKey);
            if (!is_array($fetched) || count($fetched) !== count($missInputs)) {
                return null;
            }
            foreach ($missIdx as $k => $i) {
                $vec = $fetched[$k];
                if (!is_array($vec) || empty($vec)) {
                    return null;
                }
                $vectors[$i] = $vec;
                self::cacheSet(self::cacheKey($model, $missInputs[$k]), $vec, $ttl);
            }
        }

        foreach ($vectors as $vec) {
            if (!is_array($vec) || empty($vec)) {
                return null;
            }
        }
        return $vectors;
    }

    public static function cosineSimilarity(array $a, array $b)
    {
        $n = min(count($a), count($b));
        if ($n < 1) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $va = floatval($a[$i]);
            $vb = floatval($b[$i]);
            $dot += $va * $vb;
            $na += $va * $va;
            $nb += $vb * $vb;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    private static function clampWeight($w)
    {
        $w = floatval($w);
        if ($w < 0) {
            return 0.0;
        }
        if ($w > 1) {
            return 1.0;
        }
        return $w;
    }

    private static function cacheTtl(array $cfg)
    {
        $ttl = intval(isset($cfg['embedding_cache_ttl']) ? $cfg['embedding_cache_ttl'] : self::DEFAULT_CACHE_TTL);
        if ($ttl < 60) {
            $ttl = self::DEFAULT_CACHE_TTL;
        }
        return $ttl;
    }

    private static function cacheKey($model, $text)
    {
        return 'ai_embed:' . md5($model . "\x1e" . $text);
    }

    private static function cacheGet($key)
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        $val = null;
        try {
            if (class_exists('\think\facade\Cache', false)) {
                $val = Cache::get($key);
            }
        } catch (\Throwable $e) {
            $val = null;
        }
        self::$memo[$key] = is_array($val) ? $val : null;
        return self::$memo[$key];
    }

    private static function cacheSet($key, array $vec, $ttl)
    {
        self::$memo[$key] = $vec;
        try {
            if (class_exists('\think\facade\Cache', false)) {
                Cache::set($key, $vec, (int)$ttl);
            }
        } catch (\Throwable $e) {
        }
    }

    private static function httpEmbeddings(array $inputs, array $cfg, $model, $apiKey)
    {
        $apiBase = rtrim((string)(isset($cfg['api_base']) ? $cfg['api_base'] : ''), '/');
        if ($apiBase === '') {
            $apiBase = 'https://api.openai.com/v1';
        }
        $timeout = max(3, intval(isset($cfg['timeout']) ? $cfg['timeout'] : 12));
        $post = ['model' => $model, 'input' => array_values($inputs)];
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
        if (self::$llmGate !== null && !call_user_func(self::$llmGate)) {
            return null;
        }
        $respBody = HttpClient::curlPostWithTimeout(
            $apiBase . '/embeddings',
            json_encode($post, JSON_UNESCAPED_UNICODE),
            $headers,
            $timeout
        );
        if (self::$llmRecorder !== null) {
            call_user_func(self::$llmRecorder, $respBody !== false && $respBody !== '');
        }
        if ($respBody === false || $respBody === '') {
            return null;
        }
        $json = json_decode((string)$respBody, true);
        if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
            return null;
        }
        usort($json['data'], function ($a, $b) {
            return intval(isset($a['index']) ? $a['index'] : 0) <=> intval(isset($b['index']) ? $b['index'] : 0);
        });
        $vectors = [];
        foreach ($json['data'] as $item) {
            if (empty($item['embedding']) || !is_array($item['embedding'])) {
                return null;
            }
            $vectors[] = $item['embedding'];
        }
        return $vectors;
    }
}
