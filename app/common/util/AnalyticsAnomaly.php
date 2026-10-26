<?php
namespace app\common\util;

use think\facade\Db;

class AnalyticsAnomaly
{
    const MAD_TO_SIGMA = 1.4826;

    const HOURLY_METRICS = [
        'analytics.pv', 'analytics.uv', 'analytics.session_cnt',
        'analytics.order_cnt', 'analytics.order_amount',
    ];

    const DAILY_METRICS = [
        'analytics.bounce_rate', 'analytics.recharge_amount',
    ];

    public static function detect(array $rule, $now = 0)
    {
        $now = $now > 0 ? intval($now) : time();
        $metric = (string)$rule['rule_metric'];

        if (!self::isSupported($metric)) {
            return null;
        }
        if (!self::sourceReady($metric)) {
            return null;
        }

        $param = self::param($rule);
        $isHourly = in_array($metric, self::HOURLY_METRICS, true);

        $target = $isHourly
            ? (intval(floor($now / 3600) * 3600) - 7200)
            : strtotime(date('Y-m-d', $now - 86400));

        $cur = self::fetchValue($metric, $target, $isHourly);
        if ($cur === null) {
            return null;
        }

        $samples = self::baselineSamples($metric, $target, $isHourly, $param['baseline_days']);

        $mode = (string)$rule['rule_detect_mode'];
        switch ($mode) {
            case 'zerodrop':
                return self::detectZeroDrop($metric, $cur, $samples, $param, $target, $isHourly);
            case 'yoy':
                return self::detectRatio($metric, $cur, $target, $isHourly, $rule, $param, 7);
            case 'mom':
                return self::detectRatio($metric, $cur, $target, $isHourly, $rule, $param, 0);
            case 'zscore':
            default:
                return self::detectZScore($metric, $cur, $samples, $rule, $param, $target, $isHourly);
        }
    }

    private static function detectZScore($metric, $cur, array $samples, array $rule, array $param, $target, $isHourly)
    {
        $r = self::robustZ($cur, $samples, $param['min_sample'], $param['min_abs']);
        if ($r === null) {
            return null;
        }

        $k = floatval($param['k']);
        $op = (string)$rule['rule_op'];
        if ($op === 'lt' || $op === 'lte') {
            $hit = $r['z'] < -$k;
        } elseif ($op === 'gt' || $op === 'gte') {
            $hit = $r['z'] > $k;
        } else {
            $hit = abs($r['z']) > $k;
        }

        return [
            'hit'      => $hit,
            'value'    => $cur,
            'baseline' => $r['median'],
            'z'        => $r['z'],
            'summary'  => sprintf(
                '%s @ %s = %s (baseline %s, z=%.2f, k=%.1f)',
                $metric,
                self::label($target, $isHourly),
                self::fmt($cur),
                self::fmt($r['median']),
                $r['z'],
                $k
            ),
        ];
    }

    private static function detectZeroDrop($metric, $cur, array $samples, array $param, $target, $isHourly)
    {
        if (count($samples) < $param['min_sample']) {
            return null;
        }
        $med = self::median($samples);
        if ($med < $param['min_abs']) {
            return null;
        }

        $hit = (floatval($cur) <= 0.0);

        return [
            'hit'      => $hit,
            'value'    => $cur,
            'baseline' => $med,
            'z'        => 0.0,
            'summary'  => sprintf(
                '%s @ %s = %s (baseline %s) -- zero-drop check',
                $metric,
                self::label($target, $isHourly),
                self::fmt($cur),
                self::fmt($med)
            ),
        ];
    }

    private static function detectRatio($metric, $cur, $target, $isHourly, array $rule, array $param, $daysBack)
    {
        if ($daysBack > 0) {
            $baseTarget = $isHourly
                ? ($target - ($daysBack * 86400))
                : strtotime('-' . $daysBack . ' day', $target);
        } else {
            $baseTarget = $isHourly ? ($target - 3600) : strtotime('-1 day', $target);
        }

        $base = self::fetchValue($metric, $baseTarget, $isHourly);
        if ($base === null) {
            return null;
        }
        if ($base < $param['min_abs']) {
            return null;
        }

        $dev = ($cur - $base) / max(1.0, abs($base));
        $threshold = abs(floatval($rule['rule_threshold']));
        $op = (string)$rule['rule_op'];

        if ($op === 'lt' || $op === 'lte') {
            $hit = $dev < -$threshold;
        } elseif ($op === 'gt' || $op === 'gte') {
            $hit = $dev > $threshold;
        } else {
            $hit = abs($dev) > $threshold;
        }

        return [
            'hit'      => $hit,
            'value'    => $cur,
            'baseline' => $base,
            'z'        => $dev,
            'summary'  => sprintf(
                '%s @ %s = %s vs %s (%+.1f%%)',
                $metric,
                self::label($target, $isHourly),
                self::fmt($cur),
                self::fmt($base),
                $dev * 100
            ),
        ];
    }

    public static function median(array $xs)
    {
        $n = count($xs);
        if ($n === 0) {
            return 0.0;
        }
        $vals = array_values($xs);
        sort($vals, SORT_NUMERIC);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return floatval($vals[$mid]);
        }
        return (floatval($vals[$mid - 1]) + floatval($vals[$mid])) / 2.0;
    }

    public static function mad(array $xs, $med)
    {
        if (empty($xs)) {
            return 0.0;
        }
        $dev = [];
        foreach ($xs as $x) {
            $dev[] = abs(floatval($x) - floatval($med));
        }
        return self::median($dev);
    }

    public static function robustZ($cur, array $samples, $minSample, $minAbs)
    {
        if (count($samples) < intval($minSample)) {
            return null;
        }

        $med = self::median($samples);

        if ($med < floatval($minAbs)) {
            return null;
        }

        $sigma = self::MAD_TO_SIGMA * self::mad($samples, $med);

        if ($sigma < 1e-6) {
            $sigma = max(1.0, $med * 0.05);
        }

        return [
            'z'      => (floatval($cur) - $med) / $sigma,
            'median' => $med,
            'sigma'  => $sigma,
        ];
    }

    public static function baselineSamples($metric, $target, $isHourly, $baselineDays)
    {
        $baselineDays = min(60, max(3, intval($baselineDays)));
        $out = [];
        for ($d = 1; $d <= $baselineDays; $d++) {
            $t = $isHourly
                ? ($target - ($d * 86400))
                : strtotime('-' . $d . ' day', $target);
            $v = self::fetchValue($metric, $t, $isHourly);
            if ($v !== null) {
                $out[] = $v;
            }
        }
        return $out;
    }

    public static function fetchValue($metric, $ts, $isHourly)
    {
        $ts = intval($ts);

        if ($metric === 'analytics.order_cnt' || $metric === 'analytics.order_amount') {
            $start = $ts;
            $end = $isHourly ? ($ts + 3599) : ($ts + 86399);
            $field = ($metric === 'analytics.order_cnt') ? 'COUNT(*)' : 'IFNULL(SUM(`order_price`),0)';
            $pre = Db::getConfig('connections.mysql.prefix');
            $rows = Db::query(
                "SELECT {$field} AS v FROM `{$pre}order` WHERE `order_status` = 1 AND `order_pay_time` BETWEEN ? AND ?",
                [$start, $end]
            );
            return !empty($rows) ? floatval($rows[0]['v']) : 0.0;
        }

        if ($isHourly) {
            $col = self::hourColumn($metric);
            if ($col === null) {
                return null;
            }
            $pre = Db::getConfig('connections.mysql.prefix');
            $rows = Db::query(
                "SELECT `{$col}` AS v FROM `{$pre}analytics_hour_dim`"
                . " WHERE `stat_hour` = ? AND `dim_type` = 'all' AND `dim_key` = '' LIMIT 1",
                [date('Y-m-d H:00:00', $ts)]
            );
            return !empty($rows) ? floatval($rows[0]['v']) : null;
        }

        $col = self::dayColumn($metric);
        if ($col === null) {
            return null;
        }
        $pre = Db::getConfig('connections.mysql.prefix');
        $rows = Db::query(
            "SELECT `{$col}` AS v FROM `{$pre}analytics_day_overview` WHERE `stat_date` = ? LIMIT 1",
            [date('Y-m-d', $ts)]
        );
        return !empty($rows) ? floatval($rows[0]['v']) : null;
    }

    private static function hourColumn($metric)
    {
        $map = [
            'analytics.pv'          => 'pv',
            'analytics.uv'          => 'uv',
            'analytics.session_cnt' => 'session_cnt',
        ];
        return isset($map[$metric]) ? $map[$metric] : null;
    }

    private static function dayColumn($metric)
    {
        $map = [
            'analytics.bounce_rate'      => 'bounce_rate',
            'analytics.recharge_amount'  => 'recharge_amount',
        ];
        return isset($map[$metric]) ? $map[$metric] : null;
    }

    public static function isSupported($metric)
    {
        return in_array($metric, self::HOURLY_METRICS, true)
            || in_array($metric, self::DAILY_METRICS, true);
    }

    private static function sourceReady($metric)
    {
        try {
            $pre = Db::getConfig('connections.mysql.prefix');
            if ($metric === 'analytics.order_cnt' || $metric === 'analytics.order_amount') {
                return !empty(Db::query("SHOW TABLES LIKE '{$pre}order'"));
            }
            if (in_array($metric, self::HOURLY_METRICS, true)) {
                return !empty(Db::query("SHOW TABLES LIKE '{$pre}analytics_hour_dim'"));
            }
            return !empty(Db::query("SHOW TABLES LIKE '{$pre}analytics_day_overview'"));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function param(array $rule)
    {
        $raw = isset($rule['rule_detect_param']) ? trim((string)$rule['rule_detect_param']) : '';
        $p = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($p)) {
            $p = [];
        }
        return [
            'k'             => isset($p['k']) ? max(1.0, floatval($p['k'])) : 3.0,
            'baseline_days' => isset($p['baseline_days']) ? intval($p['baseline_days']) : 14,
            'min_sample'    => isset($p['min_sample']) ? max(3, intval($p['min_sample'])) : 7,
            'min_abs'       => isset($p['min_abs']) ? max(0.0, floatval($p['min_abs'])) : 50.0,
        ];
    }

    private static function label($ts, $isHourly)
    {
        return $isHourly ? date('Y-m-d H:00', $ts) : date('Y-m-d', $ts);
    }

    private static function fmt($v)
    {
        return rtrim(rtrim(number_format(floatval($v), 2, '.', ''), '0'), '.');
    }
}
