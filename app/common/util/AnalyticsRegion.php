<?php
namespace app\common\util;

use ip_limit\IpLocationQuery;
use think\facade\Cache;

class AnalyticsRegion
{
    const CACHE_TTL = 86400;

    private static $query = null;

    public static function resolve($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return '';
        }

        $key = 'analytics:region:' . md5($ip);
        $cached = Cache::get($key, null);
        if ($cached !== null) {
            return (string)$cached;
        }

        $province = '';
        try {
            if (self::$query === null) {
                self::$query = new IpLocationQuery();
            }
            $province = (string)self::$query->queryProvince($ip);
        } catch (\Throwable $e) {
            $province = '';
        }
        $province = mb_substr($province, 0, 16, 'UTF-8');

        Cache::set($key, $province, self::CACHE_TTL);
        return $province;
    }
}
