<?php
namespace app\common\middleware;

use Closure;
use app\common\util\MonitorBucket;
use app\common\util\MonitorMetric;
use think\Request;
use think\Response;

/**
 * 苹果CMS v12 —— 请求级指标埋点中间件
 * （由 TP5 的 common\behavior\MonitorRequest 迁移）
 *
 * 通过 register_shutdown_function 在所有终止路径（正常/异常/exit/fatal）上记录指标，
 * 请求路径上零 DB 写入，仅累加到缓存分钟桶，由 cron 端点落库。
 */
class MonitorRequest
{
    /** 防止重复注册 */
    private static $registered = false;

    public function handle(Request $request, Closure $next)
    {
        try {
            if (PHP_SAPI !== 'cli' && !self::$registered) {
                self::$registered = true;
                register_shutdown_function([__CLASS__, 'record']);
            }
        } catch (\Throwable $e) {
            // 监控永远不能弄坏网站
        }

        return $next($request);
    }

    /**
     * 进程结束时记录本次请求的指标。
     */
    public static function record()
    {
        try {
            if (!defined('MAC_START_TIME')) {
                return;
            }
            $cfg = isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
                ? $GLOBALS['config']['monitor']
                : [];

            // 杀手开关
            if (empty($cfg['req_metrics_enabled']) || (string)$cfg['req_metrics_enabled'] !== '1') {
                return;
            }

            $req = request();

            // 不统计 cron 端点自己
            if (self::isCronEndpoint($req)) {
                return;
            }

            // 抽样
            $rate = isset($cfg['req_sample_rate']) ? (int)$cfg['req_sample_rate'] : 100;
            $rate = min(100, max(1, $rate));
            if ($rate < 100 && mt_rand(1, 100) > $rate) {
                return;
            }
            $w = intval(100 / $rate);

            $ms = (int)round((microtime(true) - MAC_START_TIME) * 1000);
            if ($ms < 0) {
                $ms = 0;
            }

            $code = function_exists('http_response_code') ? (int)http_response_code() : 200;
            if ($code <= 0) {
                $code = 200;
            }

            if ($code >= 500) {
                $cls = '5xx';
            } elseif ($code >= 400) {
                $cls = '4xx';
            } elseif ($code >= 300) {
                $cls = '3xx';
            } else {
                $cls = '2xx';
            }

            $ent = defined('ENTRANCE') ? ENTRANCE : 'other';
            $slow = isset($cfg['slow_ms']) ? (int)$cfg['slow_ms'] : 1000;
            $idx = MonitorMetric::latencyBucketIndex($ms);

            $delta = [
                'http.req'          => $w,
                'http.' . $cls      => $w,
                'http.lat.sum'      => $ms * $w,
                'http.lat.b' . $idx => $w,
                'http.req|' . $ent  => $w,
            ];
            if ($slow > 0 && $ms >= $slow) {
                $delta['http.slow'] = $w;
            }
            if ($cls === '5xx') {
                $delta['http.5xx|' . $ent] = $w;
            }

            MonitorBucket::add($delta);
            self::recordIp($req, $cfg, $cls, $w);
        } catch (\Throwable $e) {
            // 监控永远不能弄坏网站
        }
    }

    private static function recordIp($req, array $cfg, $cls, $w)
    {
        if (empty($cfg['access_track_enabled']) || (string)$cfg['access_track_enabled'] !== '1') {
            return;
        }
        $ip = (string)mac_get_client_ip();
        if ($ip === '' || $ip === '0.0.0.0') {
            return;
        }
        $ua = (string)$req->header('user-agent');
        $path = strtolower((string)$req->pathinfo());

        $delta = ['h' => $w];
        if ($cls === '4xx') {
            $delta['e4'] = $w;
        }
        if ($cls === '5xx') {
            $delta['e5'] = $w;
        }
        if (self::isScanPath($path)) {
            $delta['scan'] = $w;
        }
        if (self::isBadUa($ua)) {
            $delta['bad_ua'] = $w;
        }
        MonitorBucket::addIp($ip, $delta, ['ua' => $ua, 'path' => $path]);
    }

    public static function isScanPath($path)
    {
        if ($path === '') {
            return false;
        }
        $needles = [
            '.env', '.git/', '.svn', '.ds_store',
            'wp-login', 'wp-admin', 'wp-content', 'xmlrpc.php',
            'phpmyadmin', 'pma/', 'adminer',
            '/config.php', '.sql', '.bak', '.zip', '.tar.gz',
            'actuator', 'druid', 'solr/', 'jenkins',
            'eval-stdin', 'shell.php', 'webshell',
        ];
        foreach ($needles as $n) {
            if (strpos($path, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function isBadUa($ua)
    {
        $ua = strtolower(trim((string)$ua));
        if ($ua === '') {
            return true;
        }
        $needles = [
            'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'nuclei',
            'httpx', 'dirbuster', 'gobuster', 'wpscan', 'acunetix',
            'netsparker', 'x-scan', 'hydra',
        ];
        foreach ($needles as $n) {
            if (strpos($ua, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function isCronEndpoint($req)
    {
        try {
            $c = strtolower((string)$req->controller());
            $a = strtolower((string)$req->action());
            if ($c === 'monitor' && $a === 'cron') {
                return true;
            }
            $pi = strtolower((string)$req->pathinfo());
            return strpos($pi, 'monitor/cron') !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
