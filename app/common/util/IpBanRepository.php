<?php
namespace app\common\util;

class IpBanRepository
{
    public static function listBanned()
    {
        $blacks = config('blacks');
        if (!is_array($blacks) || empty($blacks['black_ip_list']) || !is_array($blacks['black_ip_list'])) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $blacks['black_ip_list']), 'strlen'));
    }

    public static function isBanned($ip)
    {
        return in_array(trim((string)$ip), self::listBanned(), true);
    }

    public static function ban($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['code' => 0, 'msg' => lang('admin/monitor/ban_ip_invalid')];
        }

        $protected = self::isProtected($ip);
        if ($protected !== null) {
            return ['code' => 0, 'msg' => $protected];
        }

        $list = self::listBanned();
        if (in_array($ip, $list, true)) {
            return ['code' => 1, 'msg' => lang('admin/monitor/ban_already')];
        }

        $list[] = $ip;
        return self::write($list);
    }

    public static function unban($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return ['code' => 0, 'msg' => lang('admin/monitor/ban_ip_invalid')];
        }

        $list = self::listBanned();
        $next = array_values(array_filter($list, function ($one) use ($ip) {
            return $one !== $ip;
        }));

        if (count($next) === count($list)) {
            return ['code' => 1, 'msg' => lang('admin/monitor/unban_not_in_list')];
        }
        return self::write($next);
    }

    public static function isProtected($ip)
    {
        $ip = trim((string)$ip);

        $self = (string)mac_get_client_ip();
        if ($self !== '' && $ip === $self) {
            return lang('admin/monitor/ban_reject_self');
        }

        $private = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7'];
        foreach ($private as $cidr) {
            if (mac_ip_in_cidr($ip, $cidr)) {
                return lang('admin/monitor/ban_reject_private');
            }
        }

        $siteUrl = isset($GLOBALS['config']['site']['site_url']) ? (string)$GLOBALS['config']['site']['site_url'] : '';
        if ($siteUrl !== '') {
            $host = parse_url(
                (strpos($siteUrl, '://') === false ? 'http://' : '') . $siteUrl,
                PHP_URL_HOST
            );
            if (!empty($host)) {
                $ips = @gethostbynamel($host);
                if (is_array($ips) && in_array($ip, $ips, true)) {
                    return lang('admin/monitor/ban_reject_site');
                }
            }
        }

        $cfg = isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
        $wl = isset($cfg['ban_whitelist']) ? trim((string)$cfg['ban_whitelist']) : '';
        if ($wl !== '') {
            foreach (explode(',', $wl) as $one) {
                $one = trim($one);
                if ($one === '') {
                    continue;
                }
                if ($ip === $one || mac_ip_in_cidr($ip, $one)) {
                    return lang('admin/monitor/ban_reject_whitelist');
                }
            }
        }

        return null;
    }

    private static function write(array $ipList)
    {
        $file = APP_PATH . 'extra/blacks.php';

        $blacks = config('blacks');
        if (!is_array($blacks)) {
            $blacks = [];
        }
        if (!isset($blacks['black_keyword_list']) || !is_array($blacks['black_keyword_list'])) {
            $blacks['black_keyword_list'] = [];
        }
        $blacks['black_ip_list'] = array_values(array_unique($ipList));

        @chmod($file, 0644);
        if (mac_arr2file($file, $blacks) === false) {
            return ['code' => 0, 'msg' => lang('write_err_config')];
        }

        config('blacks', $blacks);

        return ['code' => 1, 'msg' => lang('save_ok')];
    }
}
