<?php
namespace app\admin\controller;

use app\common\util\MonitorCollector;
use app\common\util\MonitorMetric;
use app\common\util\MonitorState;
use think\facade\Cache;
use think\facade\Db;

class Monitor extends Base
{
    const LIVE_CACHE_SEC = 5;

    const HEARTBEAT_DEAD_SEC = 300;

    public function __construct()
    {
        parent::__construct();
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
    }

    public function index()
    {
        $this->assign('title', lang('admin/monitor/title_server'));
        $this->assign('heartbeat', $this->heartbeatStatus());
        $this->assign('cron_url', $this->cronUrl());
        $this->assign('capabilities', MonitorCollector::capabilities());
        $this->assign('skipped', $this->skippedReasons());
        return $this->fetch('monitor/index');
    }

    public function realtime()
    {
        $this->assign('title', lang('admin/monitor/title_realtime'));
        $this->assign('heartbeat', $this->heartbeatStatus());
        return $this->fetch('monitor/realtime');
    }

    public function setting()
    {
        $cfg = $this->monitorConfig();
        $this->assign('title', lang('admin/monitor/title_setting'));
        $this->assign('cfg', $cfg);
        $this->assign('cron_url', $this->cronUrl());
        $this->assign('heartbeat', $this->heartbeatStatus());
        return $this->fetch('monitor/setting');
    }

    public function series()
    {
        $range = (string)input('range/s', '6h');
        $keys = (string)input('keys/s', '');

        $windows = [
            '1h'  => 3600,
            '6h'  => 21600,
            '24h' => 86400,
            '72h' => 259200,
            '7d'  => 604800,
            '30d' => 2592000,
        ];
        if (!isset($windows[$range])) {
            $range = '6h';
        }
        $span = $windows[$range];

        $metricKeys = [];
        foreach (explode(',', $keys) as $k) {
            $k = trim($k);
            if ($k !== '' && preg_match('#^[A-Za-z0-9_.\-|/]{1,64}$#', $k)) {
                $metricKeys[] = $k;
            }
        }
        if (empty($metricKeys)) {
            return json(['code' => 0, 'msg' => lang('admin/monitor/no_metric'), 'data' => []]);
        }

        $to = time();
        $from = $to - $span;
        $res = MonitorMetric::fetchSeries($metricKeys, $from, $to, 'auto');
        $step = $res['granularity'] === 'hour' ? 3600 : 60;

        $start = intval(floor($from / $step) * $step);
        $axis = [];
        for ($t = $start; $t <= $to; $t += $step) {
            $axis[] = $t;
        }

        $out = [$axis];
        foreach ($metricKeys as $k) {
            $vals = [];
            $pts = isset($res['series'][$k]) ? $res['series'][$k] : [];
            foreach ($axis as $t) {
                $vals[] = isset($pts[$t]) ? floatval($pts[$t]) : null;
            }
            $out[] = $vals;
        }

        return json([
            'code' => 1,
            'msg'  => '',
            'data' => [
                'keys'        => $metricKeys,
                'granularity' => $res['granularity'],
                'step'        => $step,
                'data'        => $out,
            ],
        ]);
    }

    public function live()
    {
        $flag = isset($GLOBALS['config']['app']['cache_flag'])
            ? (string)$GLOBALS['config']['app']['cache_flag'] : 'mac';
        $cacheKey = $flag . '_mon_live';

        $cached = Cache::get($cacheKey);
        if (!empty($cached) && is_array($cached)) {
            return json($cached);
        }

        $now = time();
        $from = $now - 3600;

        $keys = [
            'biz.online', 'biz.pv5m', 'http.req', 'http.5xx',
            'sys.load1', 'sys.mem.used_pct', 'sys.cpu.pct',
        ];
        $res = MonitorMetric::fetchSeries($keys, $from, $now, 'min');
        $series = $res['series'];

        $buckets = MonitorMetric::fetchLatencyHistogram($now - 300, $now);
        $p95 = MonitorMetric::estimatePercentile($buckets, 0.95);

        $req5m = 0;
        $err5m = 0;
        foreach ($series['http.req'] as $t => $v) {
            if ($t >= $now - 300) {
                $req5m += $v;
            }
        }
        foreach ($series['http.5xx'] as $t => $v) {
            if ($t >= $now - 300) {
                $err5m += $v;
            }
        }

        $payload = [
            'code' => 1,
            'msg'  => '',
            'data' => [
                'ts'          => $now,
                'online'      => intval($this->lastOf($series['biz.online'])),
                'pv5m'        => intval($this->lastOf($series['biz.pv5m'])),
                'online_est'  => !$this->analyticsAvailable(),
                'qps'         => $req5m > 0 ? round($req5m / 300.0, 2) : 0,
                'err_rate'    => $req5m > 0 ? round(100.0 * $err5m / $req5m, 2) : 0,
                'p95_ms'      => $p95 === null ? null : round($p95['value'], 0),
                'p95_clamped' => $p95 === null ? false : $p95['clamped'],
                'load1'       => $this->lastOf($series['sys.load1']),
                'mem_pct'     => $this->lastOf($series['sys.mem.used_pct']),
                'cpu_pct'     => $this->lastOf($series['sys.cpu.pct']),
                'spark'       => [
                    'req'  => $this->sparkOf($series['http.req'], $from, $now),
                    'cpu'  => $this->sparkOf($series['sys.cpu.pct'], $from, $now),
                    'mem'  => $this->sparkOf($series['sys.mem.used_pct'], $from, $now),
                    'load' => $this->sparkOf($series['sys.load1'], $from, $now),
                ],
                'heartbeat'   => $this->heartbeatStatus(),
            ],
        ];

        Cache::set($cacheKey, $payload, self::LIVE_CACHE_SEC);
        return json($payload);
    }

    public function setting_save()
    {
        $param = input('post.');

        $file = APP_PATH . 'extra/maccms.php';
        $config = config('maccms');
        if (!is_array($config)) {
            return json(['code' => 0, 'msg' => lang('admin/monitor/config_read_fail')]);
        }
        if (!isset($config['monitor']) || !is_array($config['monitor'])) {
            $config['monitor'] = [];
        }

        $bools = ['enabled', 'req_metrics_enabled', 'allow_shell', 'webhook_allow_private', 'access_track_enabled'];
        foreach ($bools as $k) {
            if (isset($param[$k])) {
                $config['monitor'][$k] = ((string)$param[$k] === '1') ? '1' : '0';
            }
        }

        $creds = [
            'notify_user_ids', 'alert_emails', 'webhook_url', 'webhook_secret',
            'telegram_token', 'telegram_chat_id', 'dingtalk_token', 'dingtalk_secret',
            'wecom_key', 'serverchan_key',
        ];
        foreach ($creds as $k) {
            if (isset($param[$k])) {
                $config['monitor'][$k] = mb_substr(trim((string)$param[$k]), 0, 255);
            }
        }

        if (isset($param['webhook_url'])) {
            $wu = trim((string)$param['webhook_url']);
            if ($wu !== '') {
                if (!isset($GLOBALS['config']['monitor']) || !is_array($GLOBALS['config']['monitor'])) {
                    $GLOBALS['config']['monitor'] = [];
                }
                $GLOBALS['config']['monitor']['webhook_allow_private'] = $config['monitor']['webhook_allow_private'];
                $guard = \app\common\extend\push\PushHttp::guardUrl($wu);
                if ($guard !== null) {
                    return json(['code' => 0, 'msg' => $guard['msg']]);
                }
            }
        }

        if (isset($param['notify_budget_hour'])) {
            $config['monitor']['notify_budget_hour'] = (string)min(500, max(1, intval($param['notify_budget_hour'])));
        }

        $ints = [
            'access_cc_threshold'   => [10, 100000],
            'access_err4_threshold' => [5, 100000],
            'access_track_max_ip'   => [50, 2000],
            'retain_access_days'    => [1, 365],
        ];
        foreach ($ints as $k => $range) {
            if (isset($param[$k])) {
                $config['monitor'][$k] = (string)min($range[1], max($range[0], intval($param[$k])));
            }
        }
        if (isset($param['ban_whitelist'])) {
            $config['monitor']['ban_whitelist'] = mac_filter_xss(trim((string)$param['ban_whitelist']));
        }

        if (isset($param['req_sample_rate'])) {
            $config['monitor']['req_sample_rate'] = (string)min(100, max(1, intval($param['req_sample_rate'])));
        }
        if (isset($param['slow_ms'])) {
            $config['monitor']['slow_ms'] = (string)min(60000, max(50, intval($param['slow_ms'])));
        }
        if (isset($param['retain_min_days'])) {
            $config['monitor']['retain_min_days'] = (string)min(14, max(1, intval($param['retain_min_days'])));
        }
        if (isset($param['retain_hour_days'])) {
            $config['monitor']['retain_hour_days'] = (string)min(730, max(7, intval($param['retain_hour_days'])));
        }
        if (isset($param['disk_mounts'])) {
            $config['monitor']['disk_mounts'] = mac_filter_xss(trim((string)$param['disk_mounts']));
        }
        if (isset($param['heartbeat_url'])) {
            $url = trim((string)$param['heartbeat_url']);
            if ($url !== '') {
                if (!isset($GLOBALS['config']['monitor']) || !is_array($GLOBALS['config']['monitor'])) {
                    $GLOBALS['config']['monitor'] = [];
                }
                $GLOBALS['config']['monitor']['webhook_allow_private'] = $config['monitor']['webhook_allow_private'];
                $guard = \app\common\extend\push\PushHttp::guardUrl($url);
                if ($guard !== null) {
                    return json(['code' => 0, 'msg' => $guard['msg']]);
                }
            }
            $config['monitor']['heartbeat_url'] = $url;
        }

        if (mac_arr2file($file, $config) === false) {
            return json(['code' => 0, 'msg' => lang('write_err_config')]);
        }
        return json(['code' => 1, 'msg' => lang('save_ok')]);
    }

    public function cron_token_reset()
    {
        $file = APP_PATH . 'extra/maccms.php';
        $config = config('maccms');
        if (!is_array($config)) {
            return json(['code' => 0, 'msg' => lang('admin/monitor/config_read_fail')]);
        }
        if (!isset($config['monitor']) || !is_array($config['monitor'])) {
            $config['monitor'] = [];
        }
        $config['monitor']['cron_token'] = mac_get_rndstr(32);

        if (mac_arr2file($file, $config) === false) {
            return json(['code' => 0, 'msg' => lang('write_err_config')]);
        }
        return json([
            'code' => 1,
            'msg'  => lang('save_ok'),
            'data' => ['cron_url' => $this->cronUrl($config['monitor']['cron_token'])],
        ]);
    }

    public function rule()
    {
        $list = Db::name('MonitorAlertRule')->order('rule_status desc,rule_id asc')->select();
        $this->assign('title', lang('admin/monitor/title_rule'));
        $this->assign('list', $list);
        $this->assign('push_channels', ['notify', 'email', 'webhook', 'telegram', 'dingtalk', 'wecom', 'serverchan']);
        $this->assign('heartbeat', $this->heartbeatStatus());
        return $this->fetch('monitor/rule');
    }

    public function rule_info()
    {
        $id = intval(input('id/d', 0));
        $info = $id > 0 ? Db::name('MonitorAlertRule')->where('rule_id', $id)->find() : [];
        if (empty($info)) {
            $info = [
                'rule_id' => 0, 'rule_name' => '', 'rule_status' => 1, 'rule_source' => 'metric',
                'rule_metric' => '', 'rule_agg' => 'avg', 'rule_window_min' => 5, 'rule_op' => 'gt',
                'rule_threshold' => 0, 'rule_for_min' => 3, 'rule_severity' => 2,
                'rule_silence_min' => 30, 'rule_recover_min' => 3, 'rule_channels' => 'notify',
                'rule_detect_mode' => 'threshold', 'rule_detect_param' => '',
            ];
        }
        $this->assign('title', lang('admin/monitor/title_rule'));
        $this->assign('info', $info);
        $this->assign('channels', ['notify', 'email', 'webhook', 'telegram', 'dingtalk', 'wecom', 'serverchan']);
        $this->assign('agg_list', ['avg', 'max', 'min', 'sum', 'last', 'p95']);
        $this->assign('op_list', ['gt', 'gte', 'lt', 'lte']);
        $this->assign('analytics_metrics', array_merge(
            \app\common\util\AnalyticsAnomaly::HOURLY_METRICS,
            \app\common\util\AnalyticsAnomaly::DAILY_METRICS
        ));
        return $this->fetch('monitor/rule_info');
    }

    public function rule_save()
    {
        $param = input('post.');

        $name = mac_filter_xss(trim((string)(isset($param['rule_name']) ? $param['rule_name'] : '')));
        $metric = trim((string)(isset($param['rule_metric']) ? $param['rule_metric'] : ''));
        if ($name === '') {
            return json(['code' => 0, 'msg' => lang('admin/monitor/rule_name_required')]);
        }
        if ($metric === '' || !preg_match('#^[A-Za-z0-9_.\-|/]{1,64}$#', $metric)) {
            return json(['code' => 0, 'msg' => lang('admin/monitor/rule_metric_invalid')]);
        }

        $source = ((string)(isset($param['rule_source']) ? $param['rule_source'] : 'metric') === 'analytics')
            ? 'analytics' : 'metric';

        $agg = in_array((string)$param['rule_agg'], ['avg', 'max', 'min', 'sum', 'last', 'p95'], true)
            ? (string)$param['rule_agg'] : 'avg';
        $op = in_array((string)$param['rule_op'], ['gt', 'gte', 'lt', 'lte'], true)
            ? (string)$param['rule_op'] : 'gt';

        $detectMode = 'threshold';
        $detectParam = '';
        if ($source === 'analytics') {
            if (!\app\common\util\AnalyticsAnomaly::isSupported($metric)) {
                return json(['code' => 0, 'msg' => lang('admin/monitor/analytics_metric_unsupported')]);
            }
            $detectMode = in_array((string)$param['rule_detect_mode'], ['zscore', 'zerodrop', 'yoy', 'mom'], true)
                ? (string)$param['rule_detect_mode'] : 'zscore';

            $raw = trim((string)(isset($param['rule_detect_param']) ? $param['rule_detect_param'] : ''));
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    return json(['code' => 0, 'msg' => lang('admin/monitor/detect_param_invalid')]);
                }
                $detectParam = mb_substr($raw, 0, 500);
            }
        }

        $channels = \app\common\util\AlertNotifier::parseChannels(
            isset($param['rule_channels']) ? (is_array($param['rule_channels']) ? implode(',', $param['rule_channels']) : $param['rule_channels']) : ''
        );

        $data = [
            'rule_name'         => mb_substr($name, 0, 100),
            'rule_status'       => ((string)(isset($param['rule_status']) ? $param['rule_status'] : '0') === '1') ? 1 : 0,
            'rule_source'       => $source,
            'rule_metric'       => $metric,
            'rule_agg'          => $agg,
            'rule_window_min'   => min(1440, max(1, intval($param['rule_window_min']))),
            'rule_op'           => $op,
            'rule_threshold'    => floatval($param['rule_threshold']),
            'rule_for_min'      => ($source === 'analytics') ? 0 : min(1440, max(0, intval($param['rule_for_min']))),
            'rule_severity'     => min(3, max(1, intval($param['rule_severity']))),
            'rule_silence_min'  => min(1440, max(0, intval($param['rule_silence_min']))),
            'rule_recover_min'  => min(1440, max(0, intval($param['rule_recover_min']))),
            'rule_channels'     => implode(',', $channels),
            'rule_detect_mode'  => $detectMode,
            'rule_detect_param' => $detectParam,
            'rule_time'         => time(),
        ];

        $id = intval(input('post.rule_id/d', 0));
        if ($id > 0) {
            Db::name('MonitorAlertRule')->where('rule_id', $id)->update($data);
        } else {
            $data['rule_time_add'] = time();
            $id = intval(Db::name('MonitorAlertRule')->insertGetId($data));
        }
        return json(['code' => 1, 'msg' => lang('save_ok'), 'data' => ['rule_id' => $id]]);
    }

    public function rule_del()
    {
        $id = intval(input('id/d', 0));
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }
        Db::name('MonitorAlertEvent')->where('rule_id', $id)->where('event_end_ts', 0)->delete();
        Db::name('MonitorAlertRule')->where('rule_id', $id)->delete();
        MonitorState::purgeByPrefix('alert.pending.' . $id);
        MonitorState::purgeByPrefix('alert.recover.' . $id);
        return json(['code' => 1, 'msg' => lang('del_ok')]);
    }

    public function rule_field()
    {
        $id = intval(input('post.id/d', 0));
        $status = ((string)input('post.status/s', '0') === '1') ? 1 : 0;
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }
        Db::name('MonitorAlertRule')->where('rule_id', $id)->update(['rule_status' => $status, 'rule_time' => time()]);
        return json(['code' => 1, 'msg' => lang('save_ok')]);
    }

    public function rule_enable_recommended()
    {
        $n = Db::name('MonitorAlertRule')
            ->where('rule_status', 0)
            ->where('rule_source', 'metric')
            ->update(['rule_status' => 1, 'rule_time' => time()]);
        return json(['code' => 1, 'msg' => lang('save_ok'), 'data' => ['enabled' => intval($n)]]);
    }

    public function rule_test()
    {
        $channel = strtolower(trim((string)input('post.channel/s', '')));
        $channels = \app\common\util\AlertNotifier::parseChannels($channel);
        if (empty($channels)) {
            return json(['code' => 0, 'msg' => lang('admin/monitor/push_channel_invalid')]);
        }
        $ch = $channels[0];

        $fakeEvent = [
            'event_metric'    => 'sys.cpu.pct',
            'event_severity'  => 2,
            'event_value'     => 88.8,
            'event_threshold' => 85,
            'event_summary'   => lang('admin/monitor/test_push_summary'),
            'event_start_ts'  => time(),
            'event_end_ts'    => 0,
        ];
        $fakeRule = ['rule_name' => lang('admin/monitor/test_push_rule'), 'rule_op' => 'gt', 'rule_channels' => $ch];

        \app\common\util\PushCircuit::reset($ch);
        \app\common\util\AlertNotifier::resetRun();
        $res = \app\common\util\AlertNotifier::notify($fakeEvent, $fakeRule, false, null, time());

        if (!empty($res['sent'])) {
            return json(['code' => 1, 'msg' => lang('admin/monitor/test_push_ok')]);
        }
        $reason = array_merge($res['failed'], $res['skipped']);
        return json(['code' => 0, 'msg' => implode('; ', $reason)]);
    }

    public function event()
    {
        $page = max(1, intval(input('page/d', 1)));
        $limit = 20;
        $status = intval(input('status/d', 0));

        $where = [];
        if (in_array($status, [1, 2, 3], true)) {
            $where['event_status'] = $status;
        }

        $total = Db::name('MonitorAlertEvent')->where($where)->count();
        $list = Db::name('MonitorAlertEvent')
            ->where($where)
            ->order('event_start_ts desc')
            ->limit(($page - 1) * $limit, $limit)
            ->select();

        $this->assign('title', lang('admin/monitor/title_event'));
        $this->assign('list', $list);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('status', $status);
        $this->assign('firing', Db::name('MonitorAlertEvent')->where('event_status', 1)->count());
        return $this->fetch('monitor/event');
    }

    public function event_ack()
    {
        $id = intval(input('post.id/d', 0));
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }
        Db::name('MonitorAlertEvent')->where('event_id', $id)->where('event_status', 1)->update(['event_status' => 3]);
        return json(['code' => 1, 'msg' => lang('save_ok')]);
    }

    public function access()
    {
        $hours = min(168, max(1, intval(input('hours/d', 24))));
        $level = intval(input('level/d', -1));
        $page = max(1, intval(input('page/d', 1)));
        $limit = 30;

        $pre = Db::getConfig('connections.mysql.prefix');
        $from = time() - ($hours * 3600);

        $where = 'WHERE `stat_min` >= ?';
        $bind = [$from];
        if (in_array($level, [0, 1, 2], true)) {
            $where .= ' AND `access_level` = ?';
            $bind[] = $level;
        }

        $totalRow = Db::query(
            "SELECT COUNT(DISTINCT `access_ip`) AS c FROM `{$pre}monitor_abnormal_access` {$where}",
            $bind
        );
        $total = !empty($totalRow) ? intval($totalRow[0]['c']) : 0;

        $listBind = $bind;
        $listBind[] = ($page - 1) * $limit;
        $listBind[] = $limit;
        $list = Db::query(
            "SELECT `access_ip`, SUM(`hit_cnt`) AS hit_cnt, SUM(`err4_cnt`) AS err4_cnt, SUM(`err5_cnt`) AS err5_cnt,"
            . " SUM(`scan_cnt`) AS scan_cnt, SUM(`bad_ua_cnt`) AS bad_ua_cnt, SUM(`blocked_cnt`) AS blocked_cnt,"
            . " MAX(`risk_score`) AS risk_score, MAX(`access_level`) AS access_level,"
            . " MAX(`updated_at`) AS updated_at,"
            . " SUBSTRING_INDEX(GROUP_CONCAT(`last_ua` ORDER BY `stat_min` DESC SEPARATOR '\\n'), '\\n', 1) AS last_ua,"
            . " SUBSTRING_INDEX(GROUP_CONCAT(`last_path` ORDER BY `stat_min` DESC SEPARATOR '\\n'), '\\n', 1) AS last_path"
            . " FROM `{$pre}monitor_abnormal_access` {$where}"
            . " GROUP BY `access_ip` ORDER BY `risk_score` DESC, hit_cnt DESC LIMIT ?, ?",
            $listBind
        );

        $banned = \app\common\util\IpBanRepository::listBanned();
        foreach ($list as &$row) {
            $row['is_banned'] = in_array((string)$row['access_ip'], $banned, true) ? 1 : 0;
        }
        unset($row);

        $this->assign('title', lang('admin/monitor/title_access'));
        $this->assign('list', $list);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('hours', $hours);
        $this->assign('level', $level);
        $this->assign('banned_count', count($banned));
        $this->assign('track_enabled', $this->monitorConfig()['access_track_enabled']);
        return $this->fetch('monitor/access');
    }

    public function access_ban()
    {
        $ip = trim((string)input('post.ip/s', ''));
        $res = \app\common\util\IpBanRepository::ban($ip);
        return json($res);
    }

    public function access_unban()
    {
        $ip = trim((string)input('post.ip/s', ''));
        $res = \app\common\util\IpBanRepository::unban($ip);
        return json($res);
    }

    private function heartbeatStatus()
    {
        $last = MonitorState::getNum('cron_heartbeat', 0);
        $age = $last > 0 ? (time() - $last) : -1;
        return [
            'last'  => $last,
            'age'   => $age,
            'alive' => ($last > 0 && $age <= self::HEARTBEAT_DEAD_SEC),
        ];
    }

    private function cronUrl($token = '')
    {
        $cfg = $this->monitorConfig();
        if ($token === '') {
            $token = isset($cfg['cron_token']) ? (string)$cfg['cron_token'] : '';
        }
        $host = isset($GLOBALS['config']['site']['site_url']) ? rtrim((string)$GLOBALS['config']['site']['site_url'], '/') : '';
        return $host . '/api.php/monitor/cron?token=' . $token;
    }

    private function monitorConfig()
    {
        $cfg = isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
        $defaults = [
            'enabled'             => '1',
            'cron_token'          => '',
            'req_metrics_enabled' => '1',
            'req_sample_rate'     => '100',
            'slow_ms'             => '1000',
            'allow_shell'         => '0',
            'disk_mounts'         => '',
            'retain_min_days'     => '3',
            'retain_hour_days'    => '90',
            'heartbeat_url'       => '',
            'notify_user_ids'     => '',
            'alert_emails'        => '',
            'webhook_url'         => '',
            'webhook_secret'      => '',
            'webhook_allow_private' => '0',
            'telegram_token'      => '',
            'telegram_chat_id'    => '',
            'dingtalk_token'      => '',
            'dingtalk_secret'     => '',
            'wecom_key'           => '',
            'serverchan_key'      => '',
            'notify_budget_hour'  => '20',
            'access_track_enabled' => '0',
            'access_cc_threshold'  => '120',
            'access_err4_threshold' => '20',
            'access_track_max_ip'  => '300',
            'retain_access_days'   => '30',
            'ban_whitelist'        => '',
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($cfg[$k])) {
                $cfg[$k] = $v;
            }
        }
        return $cfg;
    }

    private function skippedReasons()
    {
        $raw = MonitorState::getVal('capabilities', '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function analyticsAvailable()
    {
        try {
            $pre = Db::getConfig('connections.mysql.prefix');
            return !empty(Db::query("SHOW TABLES LIKE '{$pre}analytics_pageview'"));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function lastOf(array $points)
    {
        if (empty($points)) {
            return 0;
        }
        ksort($points);
        return floatval(end($points));
    }

    private function sparkOf(array $points, $from, $to)
    {
        $axis = [];
        $vals = [];
        $start = intval(floor($from / 60) * 60);
        for ($t = $start; $t <= $to; $t += 60) {
            $axis[] = $t;
            $vals[] = isset($points[$t]) ? floatval($points[$t]) : null;
        }
        return [$axis, $vals];
    }
}
