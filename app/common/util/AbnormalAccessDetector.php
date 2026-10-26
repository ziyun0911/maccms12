<?php
namespace app\common\util;

use think\facade\Db;

class AbnormalAccessDetector
{
    const DEFAULT_CC_THRESHOLD = 120;

    const DEFAULT_ERR4_THRESHOLD = 20;

    public static function run($now = 0)
    {
        $now = $now > 0 ? intval($now) : time();
        $buckets = MonitorBucket::drainClosedIp($now, 30);

        $stat = ['minutes' => 0, 'suspicious' => 0, 'high' => 0];
        if (empty($buckets)) {
            return $stat;
        }

        $ccTh = self::ccThreshold();
        $err4Th = self::err4Threshold();

        foreach ($buckets as $statMin => $ips) {
            $stat['minutes']++;

            $rows = [];
            $maxHits = 0;
            $scanTotal = 0;
            $blockedTotal = 0;
            $highCount = 0;

            foreach ($ips as $ip => $agg) {
                $hit = isset($agg['h']) ? intval($agg['h']) : 0;
                $e4 = isset($agg['e4']) ? intval($agg['e4']) : 0;
                $e5 = isset($agg['e5']) ? intval($agg['e5']) : 0;
                $scan = isset($agg['scan']) ? intval($agg['scan']) : 0;
                $badUa = isset($agg['bad_ua']) ? intval($agg['bad_ua']) : 0;
                $blk = isset($agg['blk']) ? intval($agg['blk']) : 0;

                $maxHits = max($maxHits, $hit);
                $scanTotal += $scan;
                $blockedTotal += $blk;

                if (!self::isSuspicious($hit, $e4, $scan, $blk, $ccTh, $err4Th)) {
                    continue;
                }

                $score = self::riskScore($hit, $e4, $scan, $badUa, $blk, $ccTh, $err4Th);
                $level = self::riskLevel($score);
                if ($level === 2) {
                    $highCount++;
                }

                $rows[] = [
                    'access_ip'    => mb_substr((string)$ip, 0, 45),
                    'stat_min'     => intval($statMin),
                    'hit_cnt'      => $hit,
                    'err4_cnt'     => $e4,
                    'err5_cnt'     => $e5,
                    'scan_cnt'     => $scan,
                    'bad_ua_cnt'   => $badUa,
                    'blocked_cnt'  => $blk,
                    'risk_score'   => $score,
                    'access_level' => $level,
                    'last_ua'      => mb_substr(isset($agg['ua']) ? (string)$agg['ua'] : '', 0, 255),
                    'last_path'    => mb_substr(isset($agg['path']) ? (string)$agg['path'] : '', 0, 255),
                    'updated_at'   => $now,
                ];
                $stat['suspicious']++;
            }
            $stat['high'] += $highCount;

            if (!empty($rows)) {
                self::upsert($rows);
            }

            MonitorMetric::upsertMany(intval($statMin), [
                ['k' => 'sec.cc_max_hits', 't' => MonitorMetric::TYPE_GAUGE, 'v' => $maxHits],
                ['k' => 'sec.abnormal_ip_high', 't' => MonitorMetric::TYPE_GAUGE, 'v' => $highCount],
                ['k' => 'sec.scan_hits', 't' => MonitorMetric::TYPE_COUNTER, 'v' => $scanTotal],
                ['k' => 'sec.blocked', 't' => MonitorMetric::TYPE_COUNTER, 'v' => $blockedTotal],
            ]);
        }

        return $stat;
    }

    public static function isSuspicious($hit, $e4, $scan, $blk, $ccTh, $err4Th)
    {
        return $hit >= $ccTh || $scan >= 1 || $blk >= 1 || $e4 >= $err4Th;
    }

    public static function riskScore($hit, $e4, $scan, $badUa, $blk, $ccTh, $err4Th)
    {
        $ccTh = max(1, intval($ccTh));
        $err4Th = max(1, intval($err4Th));

        $score = 0.0;
        $score += min(40.0, ($hit / $ccTh) * 40.0);
        $score += min(30.0, $scan * 10.0);
        $score += min(20.0, ($e4 / $err4Th) * 20.0);
        $score += ($badUa > 0) ? 10.0 : 0.0;
        $score += ($blk > 0) ? 10.0 : 0.0;

        return min(100, max(0, (int)round($score)));
    }

    public static function riskLevel($score)
    {
        $score = intval($score);
        if ($score >= 60) {
            return 2;
        }
        return $score >= 35 ? 1 : 0;
    }

    private static function upsert(array $rows)
    {
        $table = Db::getConfig('connections.mysql.prefix') . 'monitor_abnormal_access';
        $placeholders = [];
        $bind = [];

        foreach ($rows as $r) {
            $placeholders[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $bind[] = $r['access_ip'];
            $bind[] = $r['stat_min'];
            $bind[] = $r['hit_cnt'];
            $bind[] = $r['err4_cnt'];
            $bind[] = $r['err5_cnt'];
            $bind[] = $r['scan_cnt'];
            $bind[] = $r['bad_ua_cnt'];
            $bind[] = $r['blocked_cnt'];
            $bind[] = $r['risk_score'];
            $bind[] = $r['access_level'];
            $bind[] = $r['last_ua'];
            $bind[] = $r['last_path'];
            $bind[] = $r['updated_at'];
        }

        Db::execute(
            "INSERT INTO `{$table}` (`access_ip`,`stat_min`,`hit_cnt`,`err4_cnt`,`err5_cnt`,`scan_cnt`,`bad_ua_cnt`,"
            . "`blocked_cnt`,`risk_score`,`access_level`,`last_ua`,`last_path`,`updated_at`) VALUES "
            . implode(',', $placeholders)
            . " ON DUPLICATE KEY UPDATE `hit_cnt`=VALUES(`hit_cnt`),`err4_cnt`=VALUES(`err4_cnt`),`err5_cnt`=VALUES(`err5_cnt`),"
            . "`scan_cnt`=VALUES(`scan_cnt`),`bad_ua_cnt`=VALUES(`bad_ua_cnt`),`blocked_cnt`=VALUES(`blocked_cnt`),"
            . "`risk_score`=VALUES(`risk_score`),`access_level`=VALUES(`access_level`),`last_ua`=VALUES(`last_ua`),"
            . "`last_path`=VALUES(`last_path`),`updated_at`=VALUES(`updated_at`)",
            $bind
        );
    }

    public static function purge($retainDays, $maxRows = 50000)
    {
        $retainDays = min(365, max(1, intval($retainDays)));
        $cut = time() - ($retainDays * 86400);
        $table = Db::getConfig('connections.mysql.prefix') . 'monitor_abnormal_access';

        $done = 0;
        $batch = 5000;
        $t0 = microtime(true);
        do {
            $n = intval(Db::execute("DELETE FROM `{$table}` WHERE `stat_min` < ? LIMIT {$batch}", [$cut]));
            $done += $n;
            if ($n < $batch || $done >= $maxRows) {
                break;
            }
        } while ((microtime(true) - $t0) < 10.0);

        return $done;
    }

    private static function ccThreshold()
    {
        $cfg = self::config();
        $n = isset($cfg['access_cc_threshold']) ? intval($cfg['access_cc_threshold']) : self::DEFAULT_CC_THRESHOLD;
        return max(10, $n);
    }

    private static function err4Threshold()
    {
        $cfg = self::config();
        $n = isset($cfg['access_err4_threshold']) ? intval($cfg['access_err4_threshold']) : self::DEFAULT_ERR4_THRESHOLD;
        return max(5, $n);
    }

    private static function config()
    {
        return isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
    }
}
