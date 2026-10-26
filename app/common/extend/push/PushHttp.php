<?php
namespace app\common\extend\push;

class PushHttp
{
    const CONNECT_TIMEOUT = 3;
    const TIMEOUT = 5;

    const BLOCKED_CIDRS = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '0.0.0.0/8',
        '100.64.0.0/10',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    public static function post($url, $body, array $headers = [])
    {
        $guard = self::guardUrl($url);
        if ($guard !== null) {
            return $guard;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        if ($errno !== 0) {
            return ['code' => 900, 'msg' => 'curl error: ' . $err, 'body' => ''];
        }
        if ($status < 200 || $status >= 300) {
            return ['code' => $status, 'msg' => 'http ' . $status, 'body' => (string)$resp];
        }
        return ['code' => 1, 'msg' => 'ok', 'body' => (string)$resp];
    }

    public static function guardUrl($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return ['code' => 901, 'msg' => 'push url is empty', 'body' => ''];
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return ['code' => 902, 'msg' => 'push url must be http or https', 'body' => ''];
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return ['code' => 903, 'msg' => 'push url has no host', 'body' => ''];
        }

        if (self::allowPrivate()) {
            return null;
        }

        foreach (self::resolveIps($host) as $ip) {
            if (self::isBlockedIp($ip)) {
                return [
                    'code' => 904,
                    'msg'  => 'push url resolves to a private/loopback address (' . $ip . '); '
                        . 'enable monitor.webhook_allow_private if this is intentional',
                    'body' => '',
                ];
            }
        }
        return null;
    }

    public static function isBlockedIp($ip)
    {
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (mac_ip_in_cidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function resolveIps($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        return $ips;
    }

    private static function allowPrivate()
    {
        $cfg = isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
        return isset($cfg['webhook_allow_private']) && (string)$cfg['webhook_allow_private'] === '1';
    }

    public static function jsonHeaders()
    {
        return ['Content-Type: application/json; charset=utf-8'];
    }
}
