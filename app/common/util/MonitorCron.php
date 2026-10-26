<?php
namespace app\common\util;

use think\facade\Db;
use think\facade\Log;

class MonitorCron
{
    const GATE_KEY = 'due.cron';
    const GATE_SEC = 55;

    public static function run($now = 0)
    {
        $t0 = microtime(true);
        $now = $now > 0 ? intval($now) : time();
        $stages = [];
        $errors = [];

        if (!MonitorState::due(self::GATE_KEY, self::GATE_SEC, $now)) {
            return [
                'code' => 1,
                'msg'  => 'skipped',
                'data' => [
                    'took_ms' => self::tookMs($t0),
                    'stages'  => ['locked'],
                    'errors'  => [],
                ],
            ];
        }

        $cfg = self::config();

        self::stage($stages, $errors, 'flush', function () use ($now) {
            return self::flushRequestBuckets($now);
        });

        self::stage($stages, $errors, 'collect', function () use ($now) {
            return self::collectSystem($now);
        });

        self::stage($stages, $errors, 'biz', function () use ($now) {
            return self::collectBiz($now);
        });

        self::stage($stages, $errors, 'heartbeat', function () use ($now) {
            MonitorState::set('cron_heartbeat', $now, '');
            return 1;
        });

        self::stage($stages, $errors, 'access', function () use ($now, $cfg) {
            if (empty($cfg['access_track_enabled']) || (string)$cfg['access_track_enabled'] !== '1') {
                return null;
            }
            if (!MonitorState::due('due.access', 60, $now)) {
                return null;
            }
            $r = AbnormalAccessDetector::run($now);
            return $r['suspicious'] . '/' . $r['high'];
        });

        self::stage($stages, $errors, 'alert', function () use ($now) {
            if (!MonitorState::due('due.alert', 60, $now)) {
                return null;
            }
            $r = AlertEngine::run($now);
            return $r['fired'] . '/' . $r['resolved'] . '/' . $r['notified'];
        });

        self::stage($stages, $errors, 'anomaly', function () use ($now) {
            if (!MonitorState::due('due.anomaly', 3600, $now)) {
                return null;
            }
            $r = AlertEngine::run($now, null, 'analytics');
            return $r['fired'] . '/' . $r['resolved'] . '/' . $r['notified'];
        });

        self::stage($stages, $errors, 'rollup', function () use ($now) {
            if (!MonitorState::due('due.rollup', 3600, $now)) {
                return null;
            }
            $hourStart = intval(floor($now / 3600) * 3600) - 3600;
            return MonitorMetric::rollupHour($hourStart);
        });

        self::stage($stages, $errors, 'purge', function () use ($now, $cfg) {
            if (!MonitorState::due('due.purge', 86400, $now)) {
                return null;
            }
            $res = MonitorMetric::purge(
                isset($cfg['retain_min_days']) ? $cfg['retain_min_days'] : 3,
                isset($cfg['retain_hour_days']) ? $cfg['retain_hour_days'] : 90
            );
            $access = AbnormalAccessDetector::purge(
                isset($cfg['retain_access_days']) ? $cfg['retain_access_days'] : 30
            );
            MonitorBucket::gc(3600);
            MonitorState::purgeBudgetBefore($now);
            return $res['min'] + $res['hour'] + $access;
        });

        self::stage($stages, $errors, 'ping', function () use ($cfg) {
            $url = isset($cfg['heartbeat_url']) ? trim((string)$cfg['heartbeat_url']) : '';
            if ($url === '') {
                return null;
            }
            return self::pingHeartbeat($url) ? 1 : 0;
        });

        return [
            'code' => 1,
            'msg'  => 'ok',
            'data' => [
                'took_ms' => self::tookMs($t0),
                'stages'  => $stages,
                'errors'  => $errors,
            ],
        ];
    }

    private static function stage(array &$stages, array &$errors, $name, $fn)
    {
        try {
            $res = call_user_func($fn);
            if ($res === null) {
                return;
            }
            $stages[$name] = $res;
        } catch (\Throwable $e) {
            $errors[$name] = $e->getMessage();
            Log::error('[monitor] stage ' . $name . ' failed: ' . $e->getMessage());
        }
    }

    private static function flushRequestBuckets($now)
    {
        $buckets = MonitorBucket::drainClosed($now, 30);
        if (empty($buckets)) {
            return 0;
        }
        $written = 0;
        foreach ($buckets as $statMin => $fields) {
            $rows = [];
            foreach ($fields as $k => $v) {
                $rows[] = ['k' => $k, 't' => MonitorMetric::TYPE_COUNTER, 'v' => $v];
            }
            $written += MonitorMetric::upsertMany($statMin, $rows);
        }
        return $written;
    }

    private static function collectSystem($now)
    {
        $res = MonitorCollector::collect();
        $statMin = intval(floor($now / 60) * 60);
        if (!empty($res['skipped'])) {
            MonitorState::set('capabilities', $now, json_encode($res['skipped']));
        }
        return MonitorMetric::upsertMany($statMin, $res['metrics']);
    }

    private static function collectBiz($now)
    {
        $statMin = intval(floor($now / 60) * 60);
        $from = $now - 300;
        $rows = [];

        if (self::analyticsPageviewExists()) {
            $pre = Db::getConfig('connections.mysql.prefix');
            $r = Db::query(
                "SELECT COUNT(DISTINCT `visitor_id`) AS `uv`, COUNT(*) AS `pv` FROM `{$pre}analytics_pageview` WHERE `ts` >= ?",
                [$from]
            );
            $uv = !empty($r) ? intval($r[0]['uv']) : 0;
            $pv = !empty($r) ? intval($r[0]['pv']) : 0;
            $rows[] = ['k' => 'biz.online', 't' => MonitorMetric::TYPE_GAUGE, 'v' => $uv];
            $rows[] = ['k' => 'biz.pv5m', 't' => MonitorMetric::TYPE_GAUGE, 'v' => $pv];
        } else {
            $pre = Db::getConfig('connections.mysql.prefix');
            $r = Db::query(
                "SELECT SUM(`metric_value`) AS `s` FROM `{$pre}monitor_metric_min` WHERE `metric_key`='http.req' AND `stat_min` >= ?",
                [intval(floor($from / 60) * 60)]
            );
            $req = (!empty($r) && $r[0]['s'] !== null) ? intval(round(floatval($r[0]['s']))) : 0;
            $rows[] = ['k' => 'biz.pv5m', 't' => MonitorMetric::TYPE_GAUGE, 'v' => $req];
        }

        return MonitorMetric::upsertMany($statMin, $rows);
    }

    private static function analyticsPageviewExists()
    {
        try {
            $pre = Db::getConfig('connections.mysql.prefix');
            $rows = Db::query("SHOW TABLES LIKE '{$pre}analytics_pageview'");
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function pingHeartbeat($url)
    {
        if (\app\common\extend\push\PushHttp::guardUrl($url) !== null) {
            return false;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_exec($ch);
        $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);
        return $code >= 200 && $code < 400;
    }

    private static function tookMs($t0)
    {
        return intval(round((microtime(true) - $t0) * 1000));
    }

    private static function config()
    {
        return isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
    }
}
