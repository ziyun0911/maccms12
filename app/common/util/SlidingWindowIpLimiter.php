<?php
namespace app\common\util;

class SlidingWindowIpLimiter
{
    const WINDOW_MAX_SEC = 86400;
    const HITS_MAX = 1000000;

    const REDIS_SCRIPT = "local limit=tonumber(ARGV[2])\n"
        . "local expire=tonumber(ARGV[1])\n"
        . "local cur=redis.call('GET',KEYS[1])\n"
        . "if cur and tonumber(cur)>=limit then\n"
        . "  local t=redis.call('TTL',KEYS[1])\n"
        . "  if t<0 then redis.call('EXPIRE',KEYS[1],expire) t=expire end\n"
        . "  return {tonumber(cur)+1,t}\n"
        . "end\n"
        . "local c=redis.call('INCR',KEYS[1])\n"
        . "if c==1 or redis.call('TTL',KEYS[1])<0 then\n"
        . "  redis.call('EXPIRE',KEYS[1],expire)\n"
        . "end\n"
        . "return {c,redis.call('TTL',KEYS[1])}";

    public static function checkHit($ip, $scope, $windowSec, $maxHits, $runtimeSubdir = 'anti_scrape_rl')
    {
        $windowSec = (int)$windowSec;
        if ($windowSec < 5) {
            $windowSec = 5;
        }
        if ($windowSec > self::WINDOW_MAX_SEC) {
            $windowSec = self::WINDOW_MAX_SEC;
        }
        $maxHits = (int)$maxHits;
        if ($maxHits < 1) {
            $maxHits = 1;
        }
        if ($maxHits > self::HITS_MAX) {
            $maxHits = self::HITS_MAX;
        }

        $scope = strtolower(preg_replace('/[^a-z0-9_]+/', '_', (string)$scope));
        if ($scope === '') {
            $scope = 'default';
        }

        $runtimeSubdir = preg_replace('/[^a-zA-Z0-9_\-]+/', '', (string)$runtimeSubdir);
        if ($runtimeSubdir === '') {
            $runtimeSubdir = 'anti_scrape_rl';
        }

        $redisResult = self::checkHitRedis($ip, $scope, $windowSec, $maxHits);
        if ($redisResult !== null) {
            return $redisResult;
        }

        return self::checkHitFile($ip, $scope, $windowSec, $maxHits, $runtimeSubdir);
    }

    protected static function checkHitRedis($ip, $scope, $windowSec, $maxHits)
    {
        if (!class_exists('\Redis', false)) {
            return null;
        }

        try {
            $handler = \think\facade\Cache::store()->handler();
        } catch (\Exception $e) {
            return null;
        }

        if (!is_object($handler) || !($handler instanceof \Redis)) {
            return null;
        }
        if (!method_exists($handler, 'incr') || !method_exists($handler, 'expire') || !method_exists($handler, 'ttl')) {
            return null;
        }

        $flag = '';
        if (isset($GLOBALS['config']['app']['cache_flag'])) {
            $flag = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$GLOBALS['config']['app']['cache_flag']);
        }
        if ($flag === '') {
            $flag = 'mac';
        }

        $bucket = (int)floor(time() / $windowSec);
        $key = $flag . ':swrl:' . $scope . ':' . self::ipFileKey($ip) . ':' . $bucket;
        $expireSec = $windowSec + 2;

        try {
            $parsed = self::redisIncrAtomic($handler, $key, $expireSec, $maxHits);
            if ($parsed === null) {
                return null;
            }
            $count = $parsed[0];
            $ttl = $parsed[1];
            if ($count > $maxHits) {
                if ($ttl < 1) {
                    $ttl = 1;
                }
                return ['allowed' => false, 'retry_after' => $ttl];
            }
            return ['allowed' => true, 'retry_after' => 0];
        } catch (\Exception $e) {
            return null;
        }
    }

    protected static function redisIncrAtomic($handler, $key, $expireSec, $maxHits)
    {
        if (method_exists($handler, 'eval')) {
            try {
                $ret = $handler->eval(self::REDIS_SCRIPT, [$key, (string)$expireSec, (string)$maxHits], 1);
                $parsed = self::parseRedisCountTtl($ret);
                if ($parsed !== null) {
                    return $parsed;
                }
            } catch (\Exception $e) {
            }
        }

        $count = $handler->incr($key);
        if ($count === false || $count === null) {
            return null;
        }
        $count = (int)$count;
        $ttl = (int)$handler->ttl($key);
        if ($count === 1 || $ttl < 0) {
            $handler->expire($key, $expireSec);
            $ttl = $expireSec;
        }
        if ($ttl < 1) {
            $ttl = 1;
        }
        return [$count, $ttl];
    }

    protected static function parseRedisCountTtl($ret)
    {
        if (!is_array($ret) || count($ret) < 2) {
            return null;
        }
        return [(int)$ret[0], (int)$ret[1]];
    }

    protected static function checkHitFile($ip, $scope, $windowSec, $maxHits, $runtimeSubdir)
    {
        $dir = rtrim(runtime_path(), '/\\') . DIRECTORY_SEPARATOR . $runtimeSubdir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . $scope . '_' . self::ipFileKey($ip) . '.json';
        $now = time();

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return ['allowed' => true, 'retry_after' => 0];
        }

        rewind($fp);
        $raw = stream_get_contents($fp);
        $hits = json_decode((string)$raw, true);
        if (!is_array($hits)) {
            $hits = [];
        }

        $hits = array_values(array_filter($hits, function ($t) use ($now, $windowSec) {
            return ($now - (int)$t) < $windowSec;
        }));

        if (count($hits) >= $maxHits) {
            $oldest = (int)min($hits);
            $retry = max(1, $windowSec - ($now - $oldest));
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['allowed' => false, 'retry_after' => $retry];
        }

        $hits[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($hits));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return ['allowed' => true, 'retry_after' => 0];
    }

    private static function ipFileKey($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '' || strlen($ip) > 64) {
            $ip = 'unknown';
        }

        return hash('sha256', $ip);
    }
}
