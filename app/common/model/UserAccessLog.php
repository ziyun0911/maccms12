<?php
namespace app\common\model;

use think\facade\Db;
use think\facade\Cache;

class UserAccessLog extends Base
{
    protected $name = 'user_access_log';

    protected $createTime = '';
    protected $updateTime = '';

    private static $actions = [
        'login', 'login_fail', 'register', 'play', 'down',
        'fav', 'want', 'buy', 'api_token', 'comment',
    ];

    private static function cfg()
    {
        return isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
    }

    public static function enabled()
    {
        $cfg = self::cfg();
        return (string)(isset($cfg['user_access_log_enabled']) ? $cfg['user_access_log_enabled'] : '1') === '1';
    }

    private static function throttleMin()
    {
        $cfg = self::cfg();
        $n = intval(isset($cfg['user_access_throttle_min']) ? $cfg['user_access_throttle_min'] : 10);
        return max(0, min(1440, $n));
    }

    private static function failIpCapWindow()
    {
        $cfg = self::cfg();
        $n = intval(isset($cfg['user_access_fail_ip_window']) ? $cfg['user_access_fail_ip_window'] : 3600);
        return max(60, min(86400, $n));
    }

    private static function failIpCapMax()
    {
        $cfg = self::cfg();
        $n = intval(isset($cfg['user_access_fail_ip_cap']) ? $cfg['user_access_fail_ip_cap'] : 200);
        return max(0, min(100000, $n));
    }

    private static function parseUa($ua, $part)
    {
        try {
            $cls = '\\app\\common\\util\\AnalyticsUa';
            if (class_exists($cls) && method_exists($cls, $part)) {
                return (string)$cls::$part($ua);
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    public static function record($action, array $extra = [])
    {
        try {
            if (!self::enabled()) {
                return;
            }
            $action = (string)$action;
            if (!in_array($action, self::$actions, true)) {
                return;
            }

            $userName = mb_substr(trim((string)(isset($extra['user_name']) ? $extra['user_name'] : '')), 0, 60);

            if ($action === 'login_fail') {
                $userId = 0;
            } elseif (array_key_exists('user_id', $extra)) {
                $userId = intval($extra['user_id']);
            } else {
                $userId = isset($GLOBALS['user']['user_id']) ? intval($GLOBALS['user']['user_id']) : 0;
            }
            $userId = max(0, $userId);
            if ($userId < 1 && !in_array($action, ['login_fail', 'register'], true)) {
                return;
            }

            $ip = (string)mac_get_client_ip();

            $ua = '';
            try {
                if (function_exists('request')) {
                    $ua = (string)request()->header('user-agent');
                }
            } catch (\Throwable $e) {
            }
            if ($ua === '' && isset($_SERVER['HTTP_USER_AGENT'])) {
                $ua = (string)$_SERVER['HTTP_USER_AGENT'];
            }
            $ua = mb_substr($ua, 0, 255);
            $uaHash = $ua === '' ? '' : md5($ua);

            $throttle = self::throttleMin();
            $ck = '';
            if ($throttle > 0) {
                $identity = $action === 'login_fail' ? strtolower($userName) : ($userId . '|' . $uaHash);
                $ck = 'ual_' . md5($action . '|' . $ip . '|' . $identity);
                if (Cache::get($ck)) {
                    return;
                }
            }

            if ($action === 'login_fail' && $ip !== '') {
                $capMax = self::failIpCapMax();
                if ($capMax > 0) {
                    try {
                        $capCnt = Db::name('user_access_log')
                            ->where('log_action', 'login_fail')
                            ->where('log_ip', $ip)
                            ->where('log_time', '>=', time() - self::failIpCapWindow())
                            ->count();
                        if ($capCnt >= $capMax) {
                            return;
                        }
                    } catch (\Throwable $e) {
                        trace('UserAccessLog::record failCap ' . $e->getMessage(), 'error');
                    }
                }
            }

            $ipLong = 0;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipLong = sprintf('%u', ip2long($ip));
            }

            $path = '';
            try {
                $path = mb_substr((string)request()->baseUrl(), 0, 255);
            } catch (\Throwable $e) {
            }

            $inserted = Db::name('user_access_log')->insert([
                'user_id'        => $userId,
                'user_name'      => $userName,
                'log_action'     => $action,
                'log_mid'        => max(0, intval(isset($extra['mid']) ? $extra['mid'] : 0)),
                'log_rid'        => max(0, intval(isset($extra['rid']) ? $extra['rid'] : 0)),
                'log_ip'         => mb_substr($ip, 0, 45),
                'log_ip_long'    => $ipLong,
                'log_ua'         => $ua,
                'log_ua_hash'    => $uaHash,
                'log_device'     => mb_substr(self::parseUa($ua, 'device'), 0, 20),
                'log_os'         => mb_substr(self::parseUa($ua, 'os'), 0, 30),
                'log_browser'    => mb_substr(self::parseUa($ua, 'browser'), 0, 30),
                'log_path'       => $path,
                'log_anonymized' => 0,
                'log_time'       => time(),
            ]);
            if ($inserted !== false && $throttle > 0 && $ck !== '') {
                Cache::set($ck, 1, (int)$throttle * 60);
            }
        } catch (\Throwable $e) {
            trace('UserAccessLog::record ' . $e->getMessage(), 'error');
        }
    }

    public function listData($where, $order = 'log_id desc', $page = 1, $limit = 20)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = max(1, min(200, $limit ? (int)$limit : 20));
        if (!is_array($where)) {
            $where = json_decode($where, true) ?: [];
        }
        $allowCols = ['log_id', 'log_time', 'user_id', 'log_action'];
        $orderCol = 'log_id';
        $orderDir = 'desc';
        if (is_string($order) && $order !== '') {
            $parts = preg_split('/\s+/', trim($order));
            $col = strtolower(isset($parts[0]) ? $parts[0] : '');
            if (in_array($col, $allowCols, true)) {
                $orderCol = $col;
            }
            if (isset($parts[1]) && strtolower($parts[1]) === 'asc') {
                $orderDir = 'asc';
            }
        }
        $order = $orderCol . ' ' . $orderDir;
        $limit_str = ($limit * ($page - 1)) . ',' . $limit;
        $total = Db::name('user_access_log')->where($where)->count();
        $list = Db::name('user_access_log')->where($where)->order($order)->limit($limit_str)->select();

        return [
            'code' => 1, 'msg' => lang('data_list'),
            'page' => $page, 'pagecount' => ceil($total / $limit),
            'limit' => $limit, 'total' => $total, 'list' => $list,
        ];
    }

    public function relatedAccounts($userId, $days = 7)
    {
        $userId = intval($userId);
        $days = max(1, intval($days));
        if ($userId < 1) {
            return [];
        }
        $cut = time() - $days * 86400;

        $ips = Db::name('user_access_log')
            ->where('user_id', $userId)->where('log_time', '>=', $cut)
            ->where('log_ip', '<>', '')->where('log_anonymized', 0)
            ->group('log_ip')->limit(200)->column('log_ip');
        $hashes = Db::name('user_access_log')
            ->where('user_id', $userId)->where('log_time', '>=', $cut)
            ->where('log_ua_hash', '<>', '')
            ->group('log_ua_hash')->limit(200)->column('log_ua_hash');

        $via = [];
        if (!empty($ips)) {
            $r = Db::name('user_access_log')
                ->where('log_ip', 'in', $ips)
                ->where('log_time', '>=', $cut)
                ->where('log_anonymized', 0)
                ->where('user_id', '<>', $userId)->where('user_id', '>', 0)
                ->group('user_id')->limit(1000)->column('user_id');
            foreach ($r as $u) {
                $via[$u] = isset($via[$u]) ? 'both' : 'ip';
            }
        }
        if (!empty($hashes)) {
            $r = Db::name('user_access_log')
                ->where('log_ua_hash', 'in', $hashes)
                ->where('log_time', '>=', $cut)
                ->where('user_id', '<>', $userId)->where('user_id', '>', 0)
                ->group('user_id')->limit(1000)->column('user_id');
            foreach ($r as $u) {
                $via[$u] = isset($via[$u]) ? 'both' : 'ua';
            }
        }

        $out = [];
        foreach ($via as $u => $v) {
            $out[] = ['user_id' => intval($u), 'via' => $v];
        }
        return $out;
    }

    public function relatedIps($userId, $days = 30)
    {
        $userId = intval($userId);
        $days = max(1, intval($days));
        if ($userId < 1) {
            return [];
        }
        $cut = time() - $days * 86400;
        $rows = Db::name('user_access_log')
            ->field('log_ip, count(*) as cnt, max(log_time) as last_time')
            ->where('user_id', $userId)->where('log_time', '>=', $cut)
            ->where('log_ip', '<>', '')
            ->where('log_anonymized', 0)
            ->group('log_ip')->order('last_time desc')->limit(200)->select();
        return $rows ?: [];
    }

    public static function purge($retainDays, $anonymizeDays, $maxRows = 50000)
    {
        $out = ['deleted' => 0, 'anonymized' => 0];
        $retainDays = max(1, intval($retainDays));
        $anonymizeDays = max(0, intval($anonymizeDays));
        if ($anonymizeDays > 0 && $anonymizeDays >= $retainDays) {
            $anonymizeDays = $retainDays - 1;
        }
        $maxRows = max(100, min(50000, intval($maxRows)));
        $delCut = time() - $retainDays * 86400;

        try {
            $deleted = Db::name('user_access_log')
                ->where('log_time', '<', $delCut)
                ->order('log_time asc,log_id asc')
                ->limit($maxRows)->delete();
            if ($deleted === false) {
                trace('UserAccessLog::purge delete returned false', 'error');
            } else {
                $out['deleted'] = intval($deleted);
            }
        } catch (\Throwable $e) {
            trace('UserAccessLog::purge delete ' . $e->getMessage(), 'error');
        }

        if ($anonymizeDays > 0) {
            try {
                $anonCut = time() - $anonymizeDays * 86400;
                $rows = Db::name('user_access_log')
                    ->field('log_id,log_ip')
                    ->where('log_time', '>=', $delCut)
                    ->where('log_time', '<', $anonCut)
                    ->where('log_anonymized', 0)
                    ->order('log_time asc,log_id asc')
                    ->limit($maxRows)->select();
                foreach ($rows as $r) {
                    try {
                        $updated = Db::name('user_access_log')->where('log_id', $r['log_id'])->update([
                            'log_ip'         => self::maskIp($r['log_ip']),
                            'log_ip_long'    => 0,
                            'log_ua'         => '',
                            'log_anonymized' => 1,
                        ]);
                        if ($updated !== false) {
                            $out['anonymized']++;
                        }
                    } catch (\Throwable $e) {
                        trace('UserAccessLog::purge anonymize row=' . intval($r['log_id']) . ' ' . $e->getMessage(), 'error');
                    }
                }
            } catch (\Throwable $e) {
                trace('UserAccessLog::purge anonymize ' . $e->getMessage(), 'error');
            }
        }
        return $out;
    }

    public static function maskIp($ip)
    {
        $ip = (string)$ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            if (count($p) === 4) {
                return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0/24';
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed !== false && strlen($packed) === 16) {
                $network = substr($packed, 0, 6) . str_repeat("\0", 10);
                $masked = @inet_ntop($network);
                if ($masked !== false) {
                    return $masked . '/48';
                }
            }
        }
        return $ip;
    }

    public function delData($where)
    {
        try {
            $affected = Db::name('user_access_log')->where($where)->delete();
            if ($affected === false) {
                trace('UserAccessLog::delData delete returned false', 'error');
                return ['code' => 1001, 'msg' => lang('del_err')];
            }
            return ['code' => 1, 'msg' => lang('del_ok')];
        } catch (\Throwable $e) {
            trace('UserAccessLog::delData ' . $e->getMessage(), 'error');
            return ['code' => 1001, 'msg' => lang('del_err')];
        }
    }
}
