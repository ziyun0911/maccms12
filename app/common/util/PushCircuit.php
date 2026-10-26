<?php
namespace app\common\util;

class PushCircuit
{
    const FAIL_THRESHOLD = 5;
    const HOLD_SECONDS = 1800;
    const SUBDIR = 'monitor_push';

    public static function isClosed($channel, $now = 0)
    {
        $now = $now > 0 ? intval($now) : time();
        $data = self::read($channel);

        $openUntil = isset($data['open_until']) ? intval($data['open_until']) : 0;
        if ($openUntil > $now) {
            return [
                'closed' => false,
                'reason' => 'circuit open until ' . date('Y-m-d H:i:s', $openUntil)
                    . ' after ' . (isset($data['fails']) ? intval($data['fails']) : 0) . ' consecutive failures',
            ];
        }
        return ['closed' => true, 'reason' => ''];
    }

    public static function record($channel, $ok, $now = 0)
    {
        $now = $now > 0 ? intval($now) : time();
        self::update($channel, function ($data) use ($ok, $now) {
            if ($ok) {
                return ['fails' => 0, 'open_until' => 0, 'updated_at' => $now];
            }
            $fails = (isset($data['fails']) ? intval($data['fails']) : 0) + 1;
            $openUntil = ($fails >= self::FAIL_THRESHOLD) ? ($now + self::HOLD_SECONDS) : 0;
            return ['fails' => $fails, 'open_until' => $openUntil, 'updated_at' => $now];
        });
    }

    public static function reset($channel)
    {
        $path = self::path($channel);
        @unlink($path);
    }

    public static function read($channel)
    {
        $path = self::path($channel);
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function update($channel, $mutator)
    {
        $path = self::path($channel);
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return;
        }
        rewind($fp);
        $raw = stream_get_contents($fp);
        $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $next = call_user_func($mutator, $data);
        if (is_array($next)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($next));
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function path($channel)
    {
        $channel = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', (string)$channel));
        if ($channel === '') {
            $channel = 'default';
        }
        $dir = rtrim(runtime_path(), '/\\') . DIRECTORY_SEPARATOR . self::SUBDIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $channel . '.json';
    }
}
