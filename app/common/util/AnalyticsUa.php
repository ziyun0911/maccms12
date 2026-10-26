<?php
namespace app\common\util;

class AnalyticsUa
{
    public static function device($ua)
    {
        $ua = (string)$ua;
        if (preg_match('/Android/i', $ua)) {
            return 'android';
        }
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return 'ios';
        }
        if (preg_match('/Mobile/i', $ua)) {
            return 'h5';
        }
        return 'web';
    }

    public static function os($ua)
    {
        $ua = (string)$ua;
        if ($ua === '') {
            return 'other';
        }
        if (preg_match('/Windows/i', $ua)) {
            return 'windows';
        }
        if (preg_match('/Android/i', $ua)) {
            return 'android';
        }
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return 'ios';
        }
        if (preg_match('/Mac OS X/i', $ua)) {
            return 'macos';
        }
        if (preg_match('/Linux/i', $ua)) {
            return 'linux';
        }
        return 'other';
    }

    public static function browser($ua)
    {
        $ua = (string)$ua;
        if ($ua === '') {
            return 'other';
        }
        if (preg_match('#Edg/#i', $ua)) {
            return 'edge';
        }
        if (preg_match('#Chrome/#i', $ua)) {
            return 'chrome';
        }
        if (preg_match('#Safari/#i', $ua)) {
            return 'safari';
        }
        if (preg_match('#Firefox/#i', $ua)) {
            return 'firefox';
        }
        if (preg_match('/MSIE|Trident/i', $ua)) {
            return 'ie';
        }
        return 'other';
    }

    public static function refererHost($referer)
    {
        $referer = trim((string)$referer);
        if ($referer === '') {
            return '';
        }
        $scheme = strtolower((string)parse_url($referer, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        $host = parse_url($referer, PHP_URL_HOST);
        if (empty($host)) {
            return '';
        }
        return mb_substr(strtolower((string)$host), 0, 255);
    }
}
