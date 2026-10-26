<?php

namespace app\common\util;

class OpenccConverter
{
    private static $shellChecked = false;
    private static $shellAvailable = false;
    private static $cache = [];
    private static $extOd = [];
    private static $conversionWorks = null;

    private static $persistTtl = 2592000;
    private static $shellAvailableTtl = 86400;
    private static $shellUnavailableTtl = 300;
    private static $shellExecTimeout = 3;

    private static function containsHan($text)
    {
        return is_string($text) && $text !== '' && preg_match('/\p{Han}/u', $text) === 1;
    }

    private static function shellExecLimited($cmd, $timeoutSec = null)
    {
        $timeoutSec = max(1, (int)($timeoutSec ?? self::$shellExecTimeout));
        if (!function_exists('proc_open')) {
            return null;
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            return null;
        }
        @fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $deadline = microtime(true) + $timeoutSec;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                @proc_terminate($proc);
                break;
            }
            usleep(50000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($proc);

        return $stdout !== '' ? $stdout : null;
    }

    public static function s2t($text)
    {
        return self::convert($text, 's2t');
    }

    public static function t2s($text)
    {
        return self::convert($text, 't2s');
    }

    public static function convert($text, $config)
    {
        $text = (string)$text;
        $config = trim((string)$config);
        if ($text === '' || $config === '') {
            return $text;
        }
        $key = $config . ':' . md5($text);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $persistKey = 'opencc:' . $config . ':' . md5($text);
        if (class_exists('\think\facade\Cache', false)) {
            try {
                $cached = \think\facade\Cache::get($persistKey);
                if (is_string($cached)) {
                    self::$cache[$key] = $cached;

                    return $cached;
                }
            } catch (\Throwable $e) {
            }
        }

        $out = self::convertOnce($text, $config);
        if ($out === null || $out === '') {
            $out = $text;
        }
        self::$cache[$key] = $out;
        if (class_exists('\think\facade\Cache', false)) {
            try {
                \think\facade\Cache::set($persistKey, $out, self::$persistTtl);
            } catch (\Throwable $e) {
            }
        }

        return $out;
    }

    private static function convertOnce($text, $config)
    {
        $ext = self::convertWithExtension($text, $config);
        if ($ext !== null) {
            return $ext;
        }
        if (!self::isShellAvailable()) {
            return $text;
        }

        return self::execOpencc($text, $config);
    }

    private static function convertWithExtension($text, $config)
    {
        if (!extension_loaded('opencc') || !function_exists('opencc_open') || !function_exists('opencc_convert')) {
            return null;
        }
        if (!array_key_exists($config, self::$extOd)) {
            $od = null;
            foreach (self::extensionConfigCandidates($config) as $cfgFile) {
                $od = @opencc_open($cfgFile);
                if ($od !== false && $od !== null) {
                    break;
                }
            }
            self::$extOd[$config] = ($od !== false && $od !== null) ? $od : false;
        }
        $od = self::$extOd[$config];
        if ($od === false || $od === null) {
            return null;
        }
        $converted = @opencc_convert($text, $od);
        if (!is_string($converted)) {
            $converted = @opencc_convert($od, $text);
        }

        return is_string($converted) ? $converted : null;
    }

    private static function extensionConfigCandidates($config)
    {
        $config = trim((string)$config);
        $candidates = [
            $config . '.json',
            $config,
        ];
        $dir = getenv('OPENCC_CONFIG_DIR');
        if (is_string($dir) && $dir !== '') {
            $dir = rtrim($dir, '/\\');
            $candidates[] = $dir . DIRECTORY_SEPARATOR . $config . '.json';
            $candidates[] = $dir . DIRECTORY_SEPARATOR . $config;
        }

        return array_values(array_unique($candidates));
    }

    public static function available()
    {
        return self::conversionWorks();
    }

    public static function searchVariants($text)
    {
        $text = trim((string)$text);
        if ($text === '') {
            return [];
        }
        $variants = [$text];
        $compact = preg_replace('/\s+/u', '', $text);
        if (is_string($compact) && $compact !== '' && $compact !== $text) {
            $variants[] = $compact;
        }
        $lower = strtolower($text);
        if ($lower !== '' && $lower !== $text && !in_array($lower, $variants, true)) {
            $variants[] = $lower;
        }
        if (self::containsHan($text) && self::conversionWorks()) {
            foreach (['t2s', 's2t'] as $cfg) {
                $converted = self::convert($text, $cfg);
                if ($converted !== '' && $converted !== $text && !in_array($converted, $variants, true)) {
                    $variants[] = $converted;
                }
            }
        }

        return array_values(array_unique($variants));
    }

    public static function likePatterns($text)
    {
        $patterns = [];
        foreach (self::searchVariants($text) as $v) {
            $patterns[] = '%' . $v . '%';
        }

        return array_values(array_unique($patterns));
    }

    public static function conversionWorks()
    {
        if (self::$conversionWorks !== null) {
            return self::$conversionWorks;
        }
        $persistKey = 'opencc:conversion_works';
        if (class_exists('\think\facade\Cache', false)) {
            try {
                $cached = \think\facade\Cache::get($persistKey);
                if ($cached === 1 || $cached === '1' || $cached === true) {
                    self::$conversionWorks = true;

                    return true;
                }
                if ($cached === 0 || $cached === '0' || $cached === false) {
                    self::$conversionWorks = false;

                    return false;
                }
            } catch (\Throwable $e) {
            }
        }
        $sample = '软件';
        $expect = '軟件';
        $works = false;
        $ext = self::convertWithExtension($sample, 's2t');
        if (is_string($ext) && $ext === $expect) {
            $works = true;
        } elseif (self::isShellAvailable()) {
            $shell = self::execOpencc($sample, 's2t');
            if (is_string($shell) && $shell === $expect) {
                $works = true;
            }
        }
        self::$conversionWorks = $works;
        if (class_exists('\think\facade\Cache', false)) {
            try {
                \think\facade\Cache::set(
                    $persistKey,
                    $works ? 1 : 0,
                    $works ? self::$shellAvailableTtl : self::$shellUnavailableTtl
                );
            } catch (\Throwable $e) {
            }
        }

        return $works;
    }

    private static function isShellAvailable()
    {
        if (self::$shellChecked) {
            return self::$shellAvailable;
        }
        self::$shellChecked = true;
        $persistKey = 'opencc:shell_available';
        if (class_exists('\think\facade\Cache', false)) {
            try {
                $cached = \think\facade\Cache::get($persistKey);
                if ($cached === 1 || $cached === '1' || $cached === true) {
                    self::$shellAvailable = true;
                    return true;
                }
                if ($cached === 0 || $cached === '0' || $cached === false) {
                    self::$shellAvailable = false;
                    return false;
                }
            } catch (\Throwable $e) {
            }
        }
        if (!function_exists('shell_exec')) {
            self::$shellAvailable = false;
            if (class_exists('\think\facade\Cache', false)) {
                try {
                    \think\facade\Cache::set($persistKey, 0, self::$shellUnavailableTtl);
                } catch (\Throwable $e) {
                }
            }

            return false;
        }
        try {
            $ret = self::shellExecLimited('opencc -V 2>&1', 2);
            if ($ret === null && function_exists('shell_exec')) {
                $ret = @shell_exec('opencc -V 2>&1');
            }
            self::$shellAvailable = is_string($ret) && trim($ret) !== '';
        } catch (\Throwable $e) {
            self::$shellAvailable = false;
        }
        if (class_exists('\think\facade\Cache', false)) {
            try {
                \think\facade\Cache::set(
                    $persistKey,
                    self::$shellAvailable ? 1 : 0,
                    self::$shellAvailable ? self::$shellAvailableTtl : self::$shellUnavailableTtl
                );
            } catch (\Throwable $e) {
            }
        }

        return self::$shellAvailable;
    }

    private static function execOpencc($text, $config)
    {
        try {
            $tmpIn = tempnam(sys_get_temp_dir(), 'mcc_in_');
            $tmpOut = tempnam(sys_get_temp_dir(), 'mcc_out_');
            if ($tmpIn === false || $tmpOut === false) {
                return null;
            }
            file_put_contents($tmpIn, $text);
            $out = null;
            foreach ([$config . '.json', $config] as $cfgName) {
                $cmd = 'opencc -c ' . escapeshellarg($cfgName)
                    . ' -i ' . escapeshellarg($tmpIn)
                    . ' -o ' . escapeshellarg($tmpOut) . ' 2>&1';
                self::shellExecLimited($cmd, self::$shellExecTimeout);
                $chunk = @file_get_contents($tmpOut);
                if (is_string($chunk) && trim($chunk) !== '') {
                    $out = trim($chunk);
                    break;
                }
            }
            @unlink($tmpIn);
            @unlink($tmpOut);

            return is_string($out) ? $out : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
