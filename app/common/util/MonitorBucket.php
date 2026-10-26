<?php
namespace app\common\util;

class MonitorBucket
{
    const SHARDS = 64;

    const REDIS_TTL = 900;

    public static function add(array $delta)
    {
        if (empty($delta)) {
            return;
        }
        try {
            $minute = self::currentMinute();
            $redis = self::redis();
            if ($redis !== null) {
                self::addRedis($redis, $minute, $delta);
                return;
            }
            self::addFile($minute, $delta);
        } catch (\Throwable $e) {
        }
    }

    public static function addIp($ip, array $delta, array $meta = [])
    {
        if (empty($delta)) {
            return;
        }
        $ip = trim((string)$ip);
        if ($ip === '' || $ip === '0.0.0.0') {
            return;
        }
        try {
            self::addIpFile(self::currentMinute(), $ip, $delta, $meta);
        } catch (\Throwable $e) {
        }
    }

    public static function drainClosedIp($now = 0, $maxMinutes = 30)
    {
        $now = $now > 0 ? intval($now) : time();
        $maxMinutes = min(120, max(1, intval($maxMinutes)));
        $closed = intval(floor($now / 60) * 60);

        try {
            return self::drainIpFile($closed, $maxMinutes);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function drainClosed($now = 0, $maxMinutes = 30)
    {
        $now = $now > 0 ? intval($now) : time();
        $maxMinutes = min(120, max(1, intval($maxMinutes)));
        $closed = intval(floor($now / 60) * 60);

        try {
            $redis = self::redis();
            if ($redis !== null) {
                return self::drainRedis($redis, $closed, $maxMinutes);
            }
            return self::drainFile($closed, $maxMinutes);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function gc($maxAgeSec = 3600)
    {
        try {
            $cut = time() - max(600, intval($maxAgeSec));
            $n = 0;
            foreach ([self::dir(), self::ipDir()] as $dir) {
                if (!is_dir($dir)) {
                    continue;
                }
                $files = @glob($dir . DIRECTORY_SEPARATOR . '*');
                if (!is_array($files)) {
                    continue;
                }
                foreach ($files as $f) {
                    if (@filemtime($f) < $cut) {
                        @unlink($f);
                        $n++;
                    }
                }
            }
            return $n;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function redis()
    {
        try {
            $handler = \think\facade\Cache::store()->handler();
            if ($handler instanceof \Redis) {
                return $handler;
            }
        } catch (\Exception $e) {
        }
        return null;
    }

    private static function addRedis($redis, $minute, array $delta)
    {
        $key = self::redisKey($minute);
        $pipe = $redis->multi(\Redis::PIPELINE);
        foreach ($delta as $field => $n) {
            $n = intval($n);
            if ($n === 0) {
                continue;
            }
            $pipe->hIncrBy($key, (string)$field, $n);
        }
        $pipe->expire($key, self::REDIS_TTL);
        $pipe->exec();
    }

    private static function drainRedis($redis, $closed, $maxMinutes)
    {
        $out = [];
        for ($i = 1; $i <= $maxMinutes; $i++) {
            $minute = $closed - ($i * 60);
            $key = self::redisKey($minute);
            $data = $redis->hGetAll($key);
            if (empty($data) || !is_array($data)) {
                continue;
            }
            $redis->del($key);
            $row = [];
            foreach ($data as $field => $v) {
                $row[(string)$field] = intval($v);
            }
            $out[$minute] = $row;
        }
        return $out;
    }

    private static function redisKey($minute)
    {
        $flag = isset($GLOBALS['config']['app']['cache_flag'])
            ? (string)$GLOBALS['config']['app']['cache_flag'] : 'mac';
        return $flag . '_mon_req_' . intval($minute);
    }

    private static function addFile($minute, array $delta)
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . intval($minute) . '_' . self::shard() . '.json';

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return;
        }

        $size = filesize($path);
        $data = [];
        if ($size > 0) {
            rewind($fp);
            $raw = fread($fp, $size);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        foreach ($delta as $field => $n) {
            $n = intval($n);
            if ($n === 0) {
                continue;
            }
            $field = (string)$field;
            $data[$field] = (isset($data[$field]) ? intval($data[$field]) : 0) + $n;
        }

        $json = json_encode($data);
        if ($json !== false) {
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function drainFile($closed, $maxMinutes)
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = @glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files) || empty($files)) {
            return [];
        }

        $oldest = $closed - ($maxMinutes * 60);
        $out = [];

        foreach ($files as $path) {
            $base = basename($path, '.json');
            $parts = explode('_', $base);
            if (count($parts) !== 2) {
                continue;
            }
            $minute = intval($parts[0]);
            if ($minute >= $closed) {
                continue;
            }
            if ($minute < $oldest) {
                @unlink($path);
                continue;
            }

            $tmp = $path . '.flushing';
            if (!@rename($path, $tmp)) {
                continue;
            }
            $raw = @file_get_contents($tmp);
            @unlink($tmp);
            if ($raw === false || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            if (!isset($out[$minute])) {
                $out[$minute] = [];
            }
            foreach ($data as $field => $v) {
                $field = (string)$field;
                $out[$minute][$field] = (isset($out[$minute][$field]) ? $out[$minute][$field] : 0) + intval($v);
            }
        }

        return $out;
    }

    private static function addIpFile($minute, $ip, array $delta, array $meta)
    {
        $dir = self::ipDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . intval($minute) . '_' . self::shard() . '.json';

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return;
        }

        $size = filesize($path);
        $data = [];
        if ($size > 0) {
            rewind($fp);
            $raw = fread($fp, $size);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        $maxIp = self::maxTrackedIp();
        if (!isset($data[$ip]) && count($data) >= $maxIp) {
            $ip = '__overflow';
        }

        if (!isset($data[$ip])) {
            $data[$ip] = [];
        }
        foreach ($delta as $f => $n) {
            $n = intval($n);
            if ($n === 0) {
                continue;
            }
            $f = (string)$f;
            $data[$ip][$f] = (isset($data[$ip][$f]) ? intval($data[$ip][$f]) : 0) + $n;
        }
        if ($ip !== '__overflow') {
            if (isset($meta['ua'])) {
                $data[$ip]['ua'] = mb_substr((string)$meta['ua'], 0, 255);
            }
            if (isset($meta['path'])) {
                $data[$ip]['path'] = mb_substr((string)$meta['path'], 0, 255);
            }
        }

        $json = json_encode($data);
        if ($json !== false) {
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function drainIpFile($closed, $maxMinutes)
    {
        $dir = self::ipDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = @glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files) || empty($files)) {
            return [];
        }

        $oldest = $closed - ($maxMinutes * 60);
        $out = [];

        foreach ($files as $path) {
            $base = basename($path, '.json');
            $parts = explode('_', $base);
            if (count($parts) !== 2) {
                continue;
            }
            $minute = intval($parts[0]);
            if ($minute >= $closed) {
                continue;
            }
            if ($minute < $oldest) {
                @unlink($path);
                continue;
            }

            $tmp = $path . '.flushing';
            if (!@rename($path, $tmp)) {
                continue;
            }
            $raw = @file_get_contents($tmp);
            @unlink($tmp);
            if ($raw === false || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            if (!isset($out[$minute])) {
                $out[$minute] = [];
            }
            foreach ($data as $ip => $fields) {
                if (!is_array($fields)) {
                    continue;
                }
                if (!isset($out[$minute][$ip])) {
                    $out[$minute][$ip] = [];
                }
                foreach ($fields as $f => $v) {
                    if ($f === 'ua' || $f === 'path') {
                        $out[$minute][$ip][$f] = (string)$v;
                        continue;
                    }
                    $out[$minute][$ip][$f] = (isset($out[$minute][$ip][$f]) ? intval($out[$minute][$ip][$f]) : 0) + intval($v);
                }
            }
        }

        return $out;
    }

    private static function maxTrackedIp()
    {
        $cfg = isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
        $n = isset($cfg['access_track_max_ip']) ? intval($cfg['access_track_max_ip']) : 300;
        return min(2000, max(50, $n));
    }

    private static function ipDir()
    {
        return rtrim(runtime_path(), '/\\') . DIRECTORY_SEPARATOR . 'monitor' . DIRECTORY_SEPARATOR . 'ip';
    }

    private static function dir()
    {
        return rtrim(runtime_path(), '/\\') . DIRECTORY_SEPARATOR . 'monitor' . DIRECTORY_SEPARATOR . 'req';
    }

    private static function shard()
    {
        $pid = function_exists('getmypid') ? getmypid() : 0;
        if ($pid === false || $pid === 0) {
            $pid = 1;
        }
        return (crc32((string)$pid) & 0x7fffffff) % self::SHARDS;
    }

    private static function currentMinute()
    {
        return intval(floor(time() / 60) * 60);
    }
}
