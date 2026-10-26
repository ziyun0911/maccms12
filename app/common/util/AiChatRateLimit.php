<?php
namespace app\common\util;

class AiChatRateLimit
{
    public static function checkHit($ip, array $cfg)
    {
        $enabled = isset($cfg['rate_limit_enabled']) ? (string)$cfg['rate_limit_enabled'] : '1';
        if ($enabled !== '1') {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $window = max(10, intval(isset($cfg['rate_limit_window']) ? $cfg['rate_limit_window'] : 60));
        $maxReq = max(1, intval(isset($cfg['rate_limit_max']) ? $cfg['rate_limit_max'] : 20));

        $dir = runtime_path() . 'ai_chat_rl';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (mt_rand(1, 100) === 1) {
            self::gc(max(3600, $window * 20));
        }
        $path = $dir . DIRECTORY_SEPARATOR . self::ipFileKey($ip) . '.json';
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

        $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
            return ($now - (int)$t) < $window;
        }));

        if (count($hits) >= $maxReq) {
            $oldest = (int)min($hits);
            $retry = max(1, $window - ($now - $oldest));
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

    private static function dir()
    {
        $dir = runtime_path() . 'ai_chat_rl';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private static function atomicUpdate($path, $mutator)
    {
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return null;
        }
        rewind($fp);
        $raw = stream_get_contents($fp);
        $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $result = call_user_func($mutator, $data);
        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return null;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($result[0]));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return isset($result[1]) ? $result[1] : null;
    }

    public static function checkDailyBudget(array $cfg)
    {
        $limit = intval(isset($cfg['daily_budget']) ? $cfg['daily_budget'] : 500);
        if ($limit <= 0) {
            return ['allowed' => true, 'used' => 0, 'limit' => 0, 'date' => date('Ymd')];
        }
        $path = self::dir() . DIRECTORY_SEPARATOR . 'daily.json';
        $today = date('Ymd');
        $result = self::atomicUpdate($path, function ($data) use ($today, $limit) {
            if (!isset($data['date']) || $data['date'] !== $today) {
                $data = ['date' => $today, 'used' => 0];
            }
            $used = intval(isset($data['used']) ? $data['used'] : 0);
            if ($used >= $limit) {
                return [$data, ['allowed' => false, 'used' => $used, 'limit' => $limit, 'date' => $today]];
            }
            $data['used'] = $used + 1;
            return [$data, ['allowed' => true, 'used' => $data['used'], 'limit' => $limit, 'date' => $today]];
        });
        if ($result === null) {
            return ['allowed' => true, 'used' => 0, 'limit' => $limit, 'date' => $today];
        }
        return $result;
    }

    public static function checkSession($sessionId, array $cfg)
    {
        $limit = max(1, intval(isset($cfg['anon_captcha_after']) ? $cfg['anon_captcha_after'] : 10));
        $window = max(60, intval(isset($cfg['rate_limit_window']) ? $cfg['rate_limit_window'] : 60));
        $key = trim((string)$sessionId);
        if ($key === '' || strlen($key) > 128) {
            $key = 'anon';
        }
        $path = self::dir() . DIRECTORY_SEPARATOR . 'sess_' . hash('sha256', $key) . '.json';
        $now = time();
        $result = self::atomicUpdate($path, function ($data) use ($now, $window, $limit) {
            $hits = isset($data['hits']) && is_array($data['hits']) ? $data['hits'] : [];
            $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
                return ($now - (int)$t) < $window;
            }));
            if (count($hits) >= $limit) {
                $oldest = (int)min($hits);
                $retry = max(1, $window - ($now - $oldest));
                return [['hits' => $hits], ['allowed' => false, 'count' => count($hits), 'limit' => $limit, 'retry_after' => $retry]];
            }
            $hits[] = $now;
            return [['hits' => $hits], ['allowed' => true, 'count' => count($hits), 'limit' => $limit, 'retry_after' => 0]];
        });
        if ($result === null) {
            return ['allowed' => true, 'count' => 0, 'limit' => $limit, 'retry_after' => 0];
        }
        return $result;
    }

    public static function resetSession($sessionId)
    {
        $key = trim((string)$sessionId);
        if ($key === '' || strlen($key) > 128) {
            $key = 'anon';
        }
        $path = self::dir() . DIRECTORY_SEPARATOR . 'sess_' . hash('sha256', $key) . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function checkCircuit(array $cfg)
    {
        $threshold = max(1, intval(isset($cfg['circuit_fail_threshold']) ? $cfg['circuit_fail_threshold'] : 8));
        $hold = max(30, intval(isset($cfg['circuit_hold_seconds']) ? $cfg['circuit_hold_seconds'] : 1800));
        $path = self::dir() . DIRECTORY_SEPARATOR . 'circuit.json';
        $now = time();
        $result = self::atomicUpdate($path, function ($data) use ($now, $threshold) {
            $failStreak = intval(isset($data['fail_streak']) ? $data['fail_streak'] : 0);
            $openUntil = intval(isset($data['open_until']) ? $data['open_until'] : 0);
            if ($openUntil > 0 && $now < $openUntil) {
                return [$data, ['closed' => false, 'reason' => 'open']];
            }
            if ($openUntil > 0 && $now >= $openUntil) {
                $data['open_until'] = 0;
                return [$data, ['closed' => true, 'reason' => 'half_open']];
            }
            return [$data, ['closed' => true, 'reason' => ($failStreak >= $threshold ? 'near_open' : 'ok')]];
        });
        if ($result === null) {
            return ['closed' => true, 'reason' => 'ok'];
        }
        return $result;
    }

    public static function recordLlmCall($ok, array $cfg)
    {
        $threshold = max(1, intval(isset($cfg['circuit_fail_threshold']) ? $cfg['circuit_fail_threshold'] : 8));
        $hold = max(30, intval(isset($cfg['circuit_hold_seconds']) ? $cfg['circuit_hold_seconds'] : 1800));
        $path = self::dir() . DIRECTORY_SEPARATOR . 'circuit.json';
        $now = time();
        self::atomicUpdate($path, function ($data) use ($ok, $threshold, $hold, $now) {
            $failStreak = intval(isset($data['fail_streak']) ? $data['fail_streak'] : 0);
            $openUntil = intval(isset($data['open_until']) ? $data['open_until'] : 0);
            if ($ok) {
                $data['fail_streak'] = 0;
                $data['open_until'] = 0;
            } else {
                $failStreak = $failStreak + 1;
                $data['fail_streak'] = $failStreak;
                if ($failStreak >= $threshold) {
                    $data['open_until'] = $now + $hold;
                }
            }
            return [$data, null];
        });
    }

    public static function gc($maxAge = 86400)
    {
        $dir = self::dir();
        $handle = @opendir($dir);
        if ($handle === false) {
            return;
        }
        $now = time();
        $maxAge = max(60, intval($maxAge));
        while (($f = readdir($handle)) !== false) {
            if ($f === '.' || $f === '..' || $f === 'daily.json' || $f === 'circuit.json') {
                continue;
            }
            if (substr($f, -5) !== '.json') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $f;
            $mt = @filemtime($path);
            if ($mt !== false && ($now - $mt) > $maxAge) {
                @unlink($path);
            }
        }
        closedir($handle);
    }

    public static function resetCircuit()
    {
        $path = self::dir() . DIRECTORY_SEPARATOR . 'circuit.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
