<?php
/*
'软件名称：苹果CMS 源码库：https://github.com/magicblack
'--------------------------------------------------------
'Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
'遵循Apache2开源协议发布，并提供免费使用。
'--------------------------------------------------------
*/

error_reporting(E_ERROR | E_PARSE);
@ini_set('max_execution_time', '30');

$macImgMaxBytes = 5 * 1024 * 1024;
$macImgMaxHops = 3;

function macImgStop($code, $msg)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $msg;
    exit;
}

function macImgTarget($url)
{
    $parts = parse_url((string)$url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if ($port !== 80 && $port !== 443) {
        return null;
    }
    $host = strtolower(trim($parts['host'], '[]'));
    $ips = macImgPublicIps($host);
    if ($ips === []) {
        return null;
    }
    return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips];
}

function macImgPublicIps($host)
{
    if ($host === '' || $host === 'localhost' || substr($host, -6) === '.local') {
        return [];
    }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $rows = @dns_get_record($host, $type);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!empty($row['ip'])) {
                        $ips[] = $row['ip'];
                    }
                    if (!empty($row['ipv6'])) {
                        $ips[] = $row['ipv6'];
                    }
                }
            }
        }
        $ips = array_values(array_unique($ips));
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            $ips = is_array($v4) ? $v4 : [];
        }
    }
    if ($ips === []) {
        return [];
    }
    foreach ($ips as $ip) {
        if (!macImgIpPublic($ip)) {
            return [];
        }
    }
    return $ips;
}

function macImgIpPublic($ip)
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if (filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (['100.64.0.0/10', '198.18.0.0/15'] as $cidr) {
            if (macImgInCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }
    $low = strtolower($ip);
    if (strpos($low, '::ffff:') === 0) {
        return macImgIpPublic(substr($low, 7));
    }
    foreach (['::1', 'fe80', 'fc', 'fd', 'ff'] as $blocked) {
        if (strpos($low, $blocked) === 0) {
            return false;
        }
    }
    return true;
}

function macImgInCidr($ip, $cidr)
{
    list($net, $bits) = explode('/', $cidr);
    $long = ip2long((string)$ip);
    $base = ip2long((string)$net);
    if ($long === false || $base === false) {
        return false;
    }
    $mask = -1 << (32 - (int)$bits);
    return ($long & $mask) === ($base & $mask);
}

function macImgNextUrl($base, $location)
{
    $location = trim((string)$location);
    if ($location === '' || preg_match('#^https?://#i', $location)) {
        return $location;
    }
    $parts = parse_url($base);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $root = $parts['scheme'] . '://' . $parts['host']
        . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if ($location[0] === '/') {
        return $root . $location;
    }
    $dir = isset($parts['path']) ? rtrim(substr($parts['path'], 0, strrpos($parts['path'], '/') ?: 0), '/') : '';
    return $root . $dir . '/' . $location;
}

function macImgCaReady()
{
    static $ready = null;
    if ($ready === null) {
        $ca = (string)(ini_get('curl.cainfo') ?: ini_get('openssl.cafile'));
        $ready = $ca !== '' && is_file($ca);
    }
    return $ready;
}

function macImgRequest($url, $target, $maxBytes)
{
    $body = '';
    $status = 0;
    $location = '';
    $tooBig = false;

    $resolve = [];
    if (!filter_var($target['host'], FILTER_VALIDATE_IP)) {
        $addrs = [];
        foreach ($target['ips'] as $ip) {
            $addrs[] = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : $ip;
        }
        $resolve = [$target['host'] . ':' . $target['port'] . ':' . implode(',', $addrs)];
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_REFERER => dirname($url) . '/',
        CURLOPT_SSL_VERIFYPEER => macImgCaReady(),
        CURLOPT_SSL_VERIFYHOST => macImgCaReady() ? 2 : 0,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$status, &$location) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m)) {
                $status = (int)$m[1];
            } elseif (preg_match('#^Location:\s*(.+?)\s*$#i', $header, $m)) {
                $location = $m[1];
            }
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $maxBytes, &$tooBig) {
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                $tooBig = true;
                return -1;
            }
            return strlen($chunk);
        },
    ];
    if ($resolve !== []) {
        $opts[CURLOPT_RESOLVE] = $resolve;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => $body, 'location' => $location, 'too_big' => $tooBig];
}

function macImgImageType($body)
{
    if (strncmp($body, 'GIF87a', 6) === 0 || strncmp($body, 'GIF89a', 6) === 0) {
        return 'image/gif';
    }
    if (strncmp($body, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return 'image/png';
    }
    if (strncmp($body, "\xFF\xD8\xFF", 3) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($body, 'RIFF', 4) === 0 && substr($body, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return null;
}

$src = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($src === '' || !preg_match('#^https?://#i', $src)) {
    macImgStop(400, 'bad request');
}

$response = null;
for ($hop = 0; $hop <= $macImgMaxHops; $hop++) {
    $target = macImgTarget($src);
    if ($target === null) {
        macImgStop(403, 'forbidden');
    }
    $response = macImgRequest($src, $target, $macImgMaxBytes);
    if ($response['too_big']) {
        macImgStop(413, 'image too large');
    }
    if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== '') {
        $src = macImgNextUrl($src, $response['location']);
        continue;
    }
    break;
}

if ($hop > $macImgMaxHops) {
    macImgStop(414, 'too many redirects');
}
if ($response['status'] === 0) {
    macImgStop(502, 'fetch failed');
}
if ($response['status'] >= 400) {
    macImgStop($response['status'] < 600 ? $response['status'] : 502, 'upstream error');
}
if ($response['body'] === '') {
    macImgStop(404, 'not found');
}
$type = macImgImageType($response['body']);
if ($type === null) {
    macImgStop(415, 'not an image');
}

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($response['body']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
echo $response['body'];
