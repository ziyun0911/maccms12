<?php
namespace app\common\util;

use think\facade\Log;

class AlertNotifier
{
    const DEFAULT_MAX_PER_RUN = 5;

    const DEFAULT_TIME_BUDGET_MS = 8000;

    const DEFAULT_BUDGET_HOUR = 20;

    private static $sentThisRun = 0;
    private static $spentMs = 0;

    public static function resetRun()
    {
        self::$sentThisRun = 0;
        self::$spentMs = 0;
    }

    public static function notify(array $event, array $rule, $isRecover, $sender = null, $now = 0)
    {
        $now = $now > 0 ? intval($now) : time();
        $cfg = self::config();
        $out = ['sent' => [], 'failed' => [], 'skipped' => []];

        $channels = self::parseChannels(isset($rule['rule_channels']) ? $rule['rule_channels'] : '');
        if (empty($channels)) {
            $out['skipped'][] = 'no channel configured';
            return $out;
        }

        $maxPerRun = isset($cfg['notify_max_per_run']) ? intval($cfg['notify_max_per_run']) : self::DEFAULT_MAX_PER_RUN;
        $timeBudget = isset($cfg['notify_time_budget_ms']) ? intval($cfg['notify_time_budget_ms']) : self::DEFAULT_TIME_BUDGET_MS;
        if (self::$sentThisRun >= max(1, $maxPerRun)) {
            $out['skipped'][] = 'per-run notification cap reached';
            return $out;
        }
        if (self::$spentMs >= max(500, $timeBudget)) {
            $out['skipped'][] = 'per-run time budget exhausted';
            return $out;
        }

        $cap = 0;
        $budgetKey = '';
        if (!$isRecover) {
            $cap = isset($cfg['notify_budget_hour']) ? intval($cfg['notify_budget_hour']) : self::DEFAULT_BUDGET_HOUR;
            $budgetKey = 'notify_budget_' . date('YmdH', $now);
            if (MonitorState::budgetExceeded($budgetKey, $cap)) {
                $out['skipped'][] = 'hourly notification budget exhausted (' . $cap . '/h)';
                return $out;
            }
        }

        $title = self::renderTitle($event, $rule, $isRecover);
        $body = self::renderBody($event, $rule, $isRecover, $now);
        $context = self::renderContext($event, $rule, $isRecover);

        $t0 = microtime(true);
        foreach ($channels as $ch) {
            $circuit = PushCircuit::isClosed($ch, $now);
            if (!$circuit['closed']) {
                $out['skipped'][] = $ch . ': ' . $circuit['reason'];
                continue;
            }

            $res = self::dispatch($ch, $title, $body, $context, $cfg, $sender);
            $ok = isset($res['code']) && intval($res['code']) === 1;

            PushCircuit::record($ch, $ok, $now);

            if ($ok) {
                $out['sent'][] = $ch;
            } else {
                $out['failed'][] = $ch . ': ' . (isset($res['msg']) ? $res['msg'] : 'unknown error');
                Log::error('[monitor] push failed via ' . $ch . ': ' . (isset($res['msg']) ? $res['msg'] : ''));
            }
        }
        self::$spentMs += intval(round((microtime(true) - $t0) * 1000));

        if (!empty($out['sent'])) {
            self::$sentThisRun++;
            if (!$isRecover) {
                MonitorState::consumeBudget($budgetKey, $cap, $now);
            }
        }
        return $out;
    }

    private static function dispatch($channel, $title, $body, array $context, array $cfg, $sender)
    {
        if (is_callable($sender)) {
            return call_user_func($sender, $channel, $title, $body, $context, $cfg);
        }

        if ($channel === 'notify') {
            return self::sendInSite($title, $body, $cfg);
        }
        if ($channel === 'email') {
            return self::sendEmail($title, $body, $cfg);
        }
        return mac_send_push($channel, $title, $body, $context, $cfg);
    }

    private static function sendInSite($title, $body, array $cfg)
    {
        $raw = isset($cfg['notify_user_ids']) ? trim((string)$cfg['notify_user_ids']) : '';
        if ($raw === '') {
            return ['code' => 905, 'msg' => 'monitor.notify_user_ids not configured'];
        }

        $model = (new \app\common\model\Notify());
        $sent = 0;
        foreach (explode(',', $raw) as $one) {
            $uid = intval(trim($one));
            if ($uid <= 0) {
                continue;
            }
            $res = $model->send($uid, 'system', $title, $body, '');
            if (is_array($res) && isset($res['code']) && intval($res['code']) === 1) {
                $sent++;
            }
        }
        if ($sent === 0) {
            return ['code' => 907, 'msg' => 'no valid recipient in monitor.notify_user_ids'];
        }
        return ['code' => 1, 'msg' => 'sent to ' . $sent . ' admin(s)'];
    }

    private static function sendEmail($title, $body, array $cfg)
    {
        $raw = isset($cfg['alert_emails']) ? trim((string)$cfg['alert_emails']) : '';
        if ($raw === '') {
            $raw = isset($GLOBALS['config']['site']['site_email']) ? trim((string)$GLOBALS['config']['site']['site_email']) : '';
        }
        if ($raw === '') {
            return ['code' => 905, 'msg' => 'no alert email recipient configured'];
        }

        $sent = 0;
        $lastMsg = '';
        foreach (explode(',', $raw) as $one) {
            $to = trim($one);
            if ($to === '') {
                continue;
            }
            $res = mac_send_mail($to, $title, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
            if (is_array($res) && isset($res['code']) && intval($res['code']) === 1) {
                $sent++;
            } elseif (is_array($res)) {
                $lastMsg = isset($res['msg']) ? (string)$res['msg'] : '';
            }
        }
        if ($sent === 0) {
            return ['code' => 908, 'msg' => 'email delivery failed: ' . $lastMsg];
        }
        return ['code' => 1, 'msg' => 'sent to ' . $sent . ' address(es)'];
    }

    public static function parseChannels($raw)
    {
        $allowed = ['notify', 'email', 'webhook', 'telegram', 'dingtalk', 'wecom', 'serverchan'];
        $out = [];
        foreach (explode(',', (string)$raw) as $one) {
            $one = strtolower(trim($one));
            if ($one !== '' && in_array($one, $allowed, true) && !in_array($one, $out, true)) {
                $out[] = $one;
            }
        }
        return $out;
    }

    public static function renderTitle(array $event, array $rule, $isRecover)
    {
        $sev = intval(isset($event['event_severity']) ? $event['event_severity'] : 2);
        $tag = $isRecover
            ? lang('admin/monitor/alert_recovered')
            : self::severityLabel($sev);
        $name = isset($rule['rule_name']) ? (string)$rule['rule_name'] : '';
        $site = isset($GLOBALS['config']['site']['site_name']) ? (string)$GLOBALS['config']['site']['site_name'] : '';
        return '[' . $tag . '] ' . $name . ($site !== '' ? ' - ' . $site : '');
    }

    public static function renderBody(array $event, array $rule, $isRecover, $now = 0)
    {
        $now = $now > 0 ? intval($now) : time();
        $lines = [];
        $lines[] = lang('admin/monitor/f_metric') . ': ' . (isset($event['event_metric']) ? $event['event_metric'] : '');
        $lines[] = lang('admin/monitor/current_value') . ': ' . self::num($event, 'event_value');
        $lines[] = lang('admin/monitor/f_threshold') . ': ' . self::opLabel($rule) . ' ' . self::num($event, 'event_threshold');
        $lines[] = lang('admin/monitor/started_at') . ': ' . date('Y-m-d H:i:s', intval($event['event_start_ts']));
        if ($isRecover) {
            $end = intval(isset($event['event_end_ts']) ? $event['event_end_ts'] : $now);
            $lines[] = lang('admin/monitor/recovered_at') . ': ' . date('Y-m-d H:i:s', $end > 0 ? $end : $now);
            $dur = max(0, ($end > 0 ? $end : $now) - intval($event['event_start_ts']));
            $lines[] = lang('admin/monitor/duration') . ': ' . self::humanDuration($dur);
        }
        if (!empty($event['event_summary'])) {
            $lines[] = (string)$event['event_summary'];
        }
        return implode("\n", $lines);
    }

    public static function renderContext(array $event, array $rule, $isRecover)
    {
        return [
            'status'    => $isRecover ? 'resolved' : 'firing',
            'severity'  => self::severityKey(intval(isset($event['event_severity']) ? $event['event_severity'] : 2)),
            'rule'      => isset($rule['rule_name']) ? (string)$rule['rule_name'] : '',
            'metric'    => isset($event['event_metric']) ? (string)$event['event_metric'] : '',
            'value'     => floatval(isset($event['event_value']) ? $event['event_value'] : 0),
            'threshold' => floatval(isset($event['event_threshold']) ? $event['event_threshold'] : 0),
            'start_ts'  => intval(isset($event['event_start_ts']) ? $event['event_start_ts'] : 0),
            'end_ts'    => intval(isset($event['event_end_ts']) ? $event['event_end_ts'] : 0),
        ];
    }

    private static function num(array $row, $key)
    {
        $v = isset($row[$key]) ? floatval($row[$key]) : 0.0;
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

    private static function opLabel(array $rule)
    {
        $map = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
        $op = isset($rule['rule_op']) ? (string)$rule['rule_op'] : 'gt';
        return isset($map[$op]) ? $map[$op] : '>';
    }

    private static function severityKey($sev)
    {
        if ($sev >= 3) {
            return 'critical';
        }
        return $sev <= 1 ? 'info' : 'warning';
    }

    private static function severityLabel($sev)
    {
        if ($sev >= 3) {
            return lang('admin/monitor/sev_critical');
        }
        return $sev <= 1 ? lang('admin/monitor/sev_info') : lang('admin/monitor/sev_warning');
    }

    private static function humanDuration($sec)
    {
        $sec = max(0, intval($sec));
        if ($sec < 60) {
            return $sec . 's';
        }
        $m = intdiv($sec, 60);
        if ($m < 60) {
            return $m . 'm';
        }
        return intdiv($m, 60) . 'h' . ($m % 60) . 'm';
    }

    private static function config()
    {
        return isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
    }
}
