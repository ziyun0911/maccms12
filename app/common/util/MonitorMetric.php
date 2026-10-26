<?php
namespace app\common\util;

use think\facade\Db;

class MonitorMetric
{
    const LATENCY_BOUNDS = [50, 100, 200, 300, 500, 800, 1200, 2000, 3000, 5000, 8000];

    const TYPE_GAUGE   = 1;
    const TYPE_COUNTER = 2;

    private static function tableMin()
    {
        return Db::getConfig('connections.mysql.prefix') . 'monitor_metric_min';
    }

    private static function tableHour()
    {
        return Db::getConfig('connections.mysql.prefix') . 'monitor_metric_hour';
    }

    public static function latencyBucketIndex($ms)
    {
        $ms = intval($ms);
        $bounds = self::LATENCY_BOUNDS;
        $n = count($bounds);
        for ($i = 0; $i < $n; $i++) {
            if ($ms <= $bounds[$i]) {
                return $i;
            }
        }
        return $n;
    }

    public static function upsertMany($statMin, array $rows)
    {
        if (empty($rows)) {
            return 0;
        }
        $statMin = intval($statMin);
        $now = time();
        $table = self::tableMin();

        $placeholders = [];
        $bind = [];
        foreach ($rows as $row) {
            if (!isset($row['k']) || $row['k'] === '') {
                continue;
            }
            $key = mb_substr((string)$row['k'], 0, 64);
            $type = isset($row['t']) ? intval($row['t']) : self::TYPE_GAUGE;
            if ($type !== self::TYPE_COUNTER) {
                $type = self::TYPE_GAUGE;
            }
            $val = isset($row['v']) ? floatval($row['v']) : 0.0;

            $placeholders[] = '(?,?,?,?,?)';
            $bind[] = $key;
            $bind[] = $statMin;
            $bind[] = $type;
            $bind[] = $val;
            $bind[] = $now;
        }
        if (empty($placeholders)) {
            return 0;
        }

        $sql = "INSERT INTO `{$table}` (`metric_key`,`stat_min`,`metric_type`,`metric_value`,`updated_at`) VALUES "
            . implode(',', $placeholders)
            . " ON DUPLICATE KEY UPDATE `metric_type`=VALUES(`metric_type`),`metric_value`=VALUES(`metric_value`),`updated_at`=VALUES(`updated_at`)";
        Db::execute($sql, $bind);
        return count($placeholders);
    }

    public static function fetchSeries(array $metricKeys, $fromTs, $toTs, $granularity = 'auto')
    {
        $metricKeys = array_values(array_unique(array_filter($metricKeys, 'strlen')));
        if (empty($metricKeys)) {
            return ['series' => [], 'granularity' => 'min'];
        }
        $fromTs = intval($fromTs);
        $toTs = intval($toTs);

        if ($granularity === 'auto') {
            $granularity = self::pickGranularity($fromTs);
        }
        if ($granularity !== 'hour') {
            $granularity = 'min';
        }

        $in = implode(',', array_fill(0, count($metricKeys), '?'));
        $bind = $metricKeys;
        $bind[] = $fromTs;
        $bind[] = $toTs;

        if ($granularity === 'hour') {
            $table = self::tableHour();
            $sql = "SELECT `metric_key`,`stat_hour` AS `ts`,`val_avg` AS `v` FROM `{$table}`"
                . " WHERE `metric_key` IN ({$in}) AND `stat_hour` >= ? AND `stat_hour` <= ? ORDER BY `stat_hour` ASC";
        } else {
            $table = self::tableMin();
            $sql = "SELECT `metric_key`,`stat_min` AS `ts`,`metric_value` AS `v` FROM `{$table}`"
                . " WHERE `metric_key` IN ({$in}) AND `stat_min` >= ? AND `stat_min` <= ? ORDER BY `stat_min` ASC";
        }

        $rows = Db::query($sql, $bind);
        $series = [];
        foreach ($metricKeys as $k) {
            $series[$k] = [];
        }
        foreach ($rows as $row) {
            $series[$row['metric_key']][intval($row['ts'])] = floatval($row['v']);
        }
        return ['series' => $series, 'granularity' => $granularity];
    }

    private static function pickGranularity($fromTs)
    {
        $cfg = isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
        $retainDays = isset($cfg['retain_min_days']) ? intval($cfg['retain_min_days']) : 3;
        $retainDays = min(14, max(1, $retainDays));
        $cut = time() - ($retainDays * 86400) + 43200;
        return intval($fromTs) >= $cut ? 'min' : 'hour';
    }

    public static function aggregate(array $series, $agg)
    {
        if (empty($series)) {
            return null;
        }
        $vals = array_values($series);
        switch ((string)$agg) {
            case 'max':
                return floatval(max($vals));
            case 'min':
                return floatval(min($vals));
            case 'sum':
                return floatval(array_sum($vals));
            case 'last':
                ksort($series);
                $last = end($series);
                return floatval($last);
            case 'avg':
            default:
                return floatval(array_sum($vals) / count($vals));
        }
    }

    public static function fetchLatencyHistogram($fromTs, $toTs)
    {
        $n = count(self::LATENCY_BOUNDS) + 1;
        $keys = [];
        for ($i = 0; $i < $n; $i++) {
            $keys[] = 'http.lat.b' . $i;
        }

        $table = self::tableMin();
        $in = implode(',', array_fill(0, count($keys), '?'));
        $bind = $keys;
        $bind[] = intval($fromTs);
        $bind[] = intval($toTs);

        $rows = Db::query(
            "SELECT `metric_key`, SUM(`metric_value`) AS `s` FROM `{$table}`"
            . " WHERE `metric_key` IN ({$in}) AND `stat_min` >= ? AND `stat_min` <= ? GROUP BY `metric_key`",
            $bind
        );

        $buckets = array_fill(0, $n, 0);
        foreach ($rows as $row) {
            $idx = intval(substr((string)$row['metric_key'], strlen('http.lat.b')));
            if ($idx >= 0 && $idx < $n) {
                $buckets[$idx] = intval(round(floatval($row['s'])));
            }
        }
        return $buckets;
    }

    public static function estimatePercentile(array $buckets, $q)
    {
        $bounds = self::LATENCY_BOUNDS;
        $n = count($bounds) + 1;
        $total = 0;
        for ($i = 0; $i < $n; $i++) {
            $total += isset($buckets[$i]) ? intval($buckets[$i]) : 0;
        }
        if ($total <= 0) {
            return null;
        }

        $q = min(1.0, max(0.0, floatval($q)));
        $target = $q * $total;

        $cum = 0;
        for ($i = 0; $i < $n; $i++) {
            $c = isset($buckets[$i]) ? intval($buckets[$i]) : 0;
            if ($c <= 0) {
                continue;
            }
            if ($cum + $c >= $target) {
                if ($i === $n - 1) {
                    return [
                        'value'   => floatval($bounds[$n - 2]),
                        'clamped' => true,
                        'total'   => $total,
                    ];
                }
                $lo = ($i === 0) ? 0.0 : floatval($bounds[$i - 1]);
                $hi = floatval($bounds[$i]);
                $frac = ($target - $cum) / $c;
                $frac = min(1.0, max(0.0, $frac));
                return [
                    'value'   => $lo + ($hi - $lo) * $frac,
                    'clamped' => false,
                    'total'   => $total,
                ];
            }
            $cum += $c;
        }

        return ['value' => floatval($bounds[$n - 2]), 'clamped' => true, 'total' => $total];
    }

    public static function rollupHour($hourStart)
    {
        $hourStart = intval($hourStart);
        $hourEnd = $hourStart + 3600;
        $now = time();
        $min = self::tableMin();
        $hour = self::tableHour();

        $affected = Db::execute(
            "INSERT INTO `{$hour}` (`metric_key`,`stat_hour`,`metric_type`,`val_avg`,`val_max`,`val_min`,`val_sum`,`sample_cnt`,`updated_at`)"
            . " SELECT `metric_key`, ?, MAX(`metric_type`), AVG(`metric_value`), MAX(`metric_value`), MIN(`metric_value`), SUM(`metric_value`), COUNT(*), ?"
            . " FROM `{$min}` WHERE `stat_min` >= ? AND `stat_min` < ? GROUP BY `metric_key`"
            . " ON DUPLICATE KEY UPDATE `metric_type`=VALUES(`metric_type`),`val_avg`=VALUES(`val_avg`),`val_max`=VALUES(`val_max`),"
            . "`val_min`=VALUES(`val_min`),`val_sum`=VALUES(`val_sum`),`sample_cnt`=VALUES(`sample_cnt`),`updated_at`=VALUES(`updated_at`)",
            [$hourStart, $now, $hourStart, $hourEnd]
        );
        return intval($affected);
    }

    public static function purge($minRetainDays, $hourRetainDays, $maxRows = 50000)
    {
        $minRetainDays = min(14, max(1, intval($minRetainDays)));
        $hourRetainDays = min(730, max(7, intval($hourRetainDays)));
        $maxRows = max(1000, intval($maxRows));

        $t0 = microtime(true);
        $deletedMin = self::purgeTable(self::tableMin(), 'stat_min', time() - ($minRetainDays * 86400), $maxRows, $t0);
        $deletedHour = self::purgeTable(self::tableHour(), 'stat_hour', time() - ($hourRetainDays * 86400), $maxRows, $t0);

        return ['min' => $deletedMin, 'hour' => $deletedHour];
    }

    private static function purgeTable($table, $tsCol, $cut, $maxRows, $t0)
    {
        $done = 0;
        $batch = 5000;
        do {
            $n = intval(Db::execute(
                "DELETE FROM `{$table}` WHERE `{$tsCol}` < ? LIMIT {$batch}",
                [intval($cut)]
            ));
            $done += $n;
            if ($n < $batch || $done >= $maxRows) {
                break;
            }
        } while ((microtime(true) - $t0) < 10.0);

        return $done;
    }
}
