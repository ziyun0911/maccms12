<?php
namespace app\common\util;

use think\facade\Db;
use think\facade\Log;

class AlertEngine
{
    const SEQUENCE_BREAK_SEC = 180;

    const MIN_P95_SAMPLES = 20;

    public static function run($now = 0, $sender = null, $source = 'metric')
    {
        $now = $now > 0 ? intval($now) : time();
        AlertNotifier::resetRun();

        $stat = ['evaluated' => 0, 'fired' => 0, 'resolved' => 0, 'notified' => 0, 'suppressed' => 0, 'skipped' => 0];

        $source = ($source === 'analytics') ? 'analytics' : 'metric';
        $rules = Db::name('MonitorAlertRule')
            ->where('rule_status', 1)
            ->where('rule_source', $source)
            ->select();
        foreach ($rules as $rule) {
            try {
                $stat['evaluated']++;
                $res = self::evaluate($rule, $now);
                if ($res === null) {
                    $stat['skipped']++;
                    continue;
                }

                if ($res['hit']) {
                    self::handleHit($rule, $res, $now, $sender, $stat);
                } else {
                    self::handleClear($rule, $res, $now, $sender, $stat);
                }
            } catch (\Throwable $e) {
                Log::error('[monitor] alert rule ' . $rule['rule_id'] . ' failed: ' . $e->getMessage());
            }
        }

        return $stat;
    }

    public static function evaluate(array $rule, $now)
    {
        if ((string)$rule['rule_source'] === 'analytics') {
            $r = AnalyticsAnomaly::detect($rule, $now);
            if ($r === null) {
                return null;
            }
            return [
                'hit'      => $r['hit'],
                'value'    => $r['value'],
                'baseline' => $r['baseline'],
                'summary'  => $r['summary'],
            ];
        }

        $window = max(1, intval($rule['rule_window_min']));
        $to = intval($now);
        $from = $to - ($window * 60);

        $agg = (string)$rule['rule_agg'];
        $metric = (string)$rule['rule_metric'];

        if ($agg === 'p95') {
            $value = self::evaluateP95($from, $to);
            if ($value === null) {
                return null;
            }
        } else {
            $res = MonitorMetric::fetchSeries([$metric], $from, $to, 'min');
            $series = isset($res['series'][$metric]) ? $res['series'][$metric] : [];
            $value = MonitorMetric::aggregate($series, $agg);
            if ($value === null) {
                return null;
            }
        }

        $threshold = floatval($rule['rule_threshold']);
        $hit = self::compare($value, (string)$rule['rule_op'], $threshold);

        return [
            'hit'     => $hit,
            'value'   => $value,
            'summary' => sprintf(
                '%s(%s, %dm) = %s',
                $agg,
                $metric,
                $window,
                rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.')
            ),
        ];
    }

    private static function evaluateP95($from, $to)
    {
        $buckets = MonitorMetric::fetchLatencyHistogram($from, $to);
        $r = MonitorMetric::estimatePercentile($buckets, 0.95);
        if ($r === null || $r['total'] < self::MIN_P95_SAMPLES) {
            return null;
        }
        return floatval($r['value']);
    }

    public static function compare($value, $op, $threshold)
    {
        switch ($op) {
            case 'gte':
                return $value >= $threshold;
            case 'lt':
                return $value < $threshold;
            case 'lte':
                return $value <= $threshold;
            case 'gt':
            default:
                return $value > $threshold;
        }
    }

    public static function fingerprint(array $rule, $dim = '')
    {
        return md5(intval($rule['rule_id']) . '|' . (string)$rule['rule_metric'] . '|' . (string)$dim);
    }

    private static function handleHit(array $rule, array $res, $now, $sender, array &$stat)
    {
        $ruleId = intval($rule['rule_id']);

        if ((string)$rule['rule_source'] === 'analytics') {
            MonitorState::purgeByPrefix('alert.recover.' . $ruleId);
            self::fire($rule, $res, $now, $now, $sender, $stat);
            return;
        }

        $pendingKey = 'alert.pending.' . $ruleId;

        $raw = MonitorState::getVal($pendingKey, '');
        $state = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            $state = ['since' => 0, 'last_hit' => 0];
        }

        $since = intval(isset($state['since']) ? $state['since'] : 0);
        $lastHit = intval(isset($state['last_hit']) ? $state['last_hit'] : 0);

        if ($since === 0 || ($now - $lastHit) > self::SEQUENCE_BREAK_SEC) {
            $since = $now;
        }
        MonitorState::set($pendingKey, $now, json_encode(['since' => $since, 'last_hit' => $now]));

        $forSec = max(0, intval($rule['rule_for_min'])) * 60;
        if (($now - $since) < $forSec) {
            return;
        }

        MonitorState::purgeByPrefix('alert.recover.' . $ruleId);

        self::fire($rule, $res, $since, $now, $sender, $stat);
    }

    private static function handleClear(array $rule, array $res, $now, $sender, array &$stat)
    {
        $ruleId = intval($rule['rule_id']);
        MonitorState::purgeByPrefix('alert.pending.' . $ruleId);

        $active = self::activeEvent($rule);
        if (empty($active)) {
            return;
        }

        $recoverKey = 'alert.recover.' . $ruleId;
        $since = MonitorState::getNum($recoverKey, 0);
        if ($since === 0) {
            MonitorState::set($recoverKey, $now, '');
            $since = $now;
        }

        $recoverSec = max(0, intval($rule['rule_recover_min'])) * 60;
        if (($now - $since) < $recoverSec) {
            return;
        }

        Db::name('MonitorAlertEvent')
            ->where('event_id', intval($active['event_id']))
            ->update([
                'event_status' => 2,
                'event_end_ts' => $now,
            ]);
        MonitorState::purgeByPrefix($recoverKey);
        $stat['resolved']++;

        $active['event_end_ts'] = $now;
        $delivery = AlertNotifier::notify($active, $rule, true, $sender, $now);
        if (!empty($delivery['sent'])) {
            $stat['notified']++;
        }
        self::recordDelivery(intval($active['event_id']), $delivery, $now, false);
    }

    private static function fire(array $rule, array $res, $since, $now, $sender, array &$stat)
    {
        $fp = self::fingerprint($rule);
        $active = self::activeEvent($rule);

        if (empty($active)) {
            $row = [
                'rule_id'           => intval($rule['rule_id']),
                'rule_name'         => (string)$rule['rule_name'],
                'event_metric'      => (string)$rule['rule_metric'],
                'event_severity'    => intval($rule['rule_severity']),
                'event_status'      => 1,
                'event_value'       => $res['value'],
                'event_threshold'   => floatval($rule['rule_threshold']),
                'event_baseline'    => isset($res['baseline']) ? floatval($res['baseline']) : 0,
                'event_summary'     => mb_substr((string)$res['summary'], 0, 500),
                'event_fingerprint' => $fp,
                'event_start_ts'    => intval($since),
                'event_last_ts'     => $now,
                'event_end_ts'      => 0,
                'event_notify_ts'   => 0,
                'event_notify_cnt'  => 0,
            ];

            try {
                $eventId = Db::name('MonitorAlertEvent')->insertGetId($row);
                $row['event_id'] = intval($eventId);
                $stat['fired']++;

                $delivery = AlertNotifier::notify($row, $rule, false, $sender, $now);
                self::afterNotify(intval($eventId), $delivery, $now, $stat);
                return;
            } catch (\Exception $e) {
                $active = self::activeEvent($rule);
                if (empty($active)) {
                    throw $e;
                }
            }
        }

        Db::name('MonitorAlertEvent')
            ->where('event_id', intval($active['event_id']))
            ->update([
                'event_last_ts' => $now,
                'event_value'   => $res['value'],
                'event_summary' => mb_substr((string)$res['summary'], 0, 500),
            ]);

        $silenceSec = max(0, intval($rule['rule_silence_min'])) * 60;
        $lastNotify = intval($active['event_notify_ts']);

        if ($silenceSec > 0 && $lastNotify > 0 && ($now - $lastNotify) < $silenceSec) {
            $stat['suppressed']++;
            return;
        }

        $active['event_value'] = $res['value'];
        $active['event_summary'] = $res['summary'];
        $delivery = AlertNotifier::notify($active, $rule, false, $sender, $now);
        self::afterNotify(intval($active['event_id']), $delivery, $now, $stat);
    }

    private static function afterNotify($eventId, array $delivery, $now, array &$stat)
    {
        $sent = !empty($delivery['sent']);
        if ($sent) {
            $stat['notified']++;
        } else {
            $stat['suppressed']++;
        }
        self::recordDelivery($eventId, $delivery, $now, $sent);
    }

    private static function recordDelivery($eventId, array $delivery, $now, $advanceNotifyTs)
    {
        $parts = [];
        if (!empty($delivery['sent'])) {
            $parts[] = 'sent:' . implode('/', $delivery['sent']);
        }
        if (!empty($delivery['failed'])) {
            $parts[] = 'failed:' . implode('/', $delivery['failed']);
        }
        if (!empty($delivery['skipped'])) {
            $parts[] = 'skipped:' . implode('/', $delivery['skipped']);
        }

        $update = ['event_notify_result' => mb_substr(implode(' | ', $parts), 0, 500)];
        if ($advanceNotifyTs) {
            $update['event_notify_ts'] = $now;
            $update['event_notify_cnt'] = Db::raw('`event_notify_cnt` + 1');
        }

        Db::name('MonitorAlertEvent')->where('event_id', intval($eventId))->update($update);
    }

    private static function activeEvent(array $rule)
    {
        $row = Db::name('MonitorAlertEvent')
            ->where('event_fingerprint', self::fingerprint($rule))
            ->where('event_end_ts', 0)
            ->find();
        return is_array($row) ? $row : [];
    }
}
