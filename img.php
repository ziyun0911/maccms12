<?php
/*
'软件名称：苹果CMS 源码库：https://github.com/magicblack
'--------------------------------------------------------
'Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
'遵循Apache2开源协议发布，并提供免费使用。
'--------------------------------------------------------
*/
/**
 * 外链图片代理：后台编辑器里命中 upload.img_key 关键字的外链图会被改写成
 * <img src="/img.php?url=原地址">（见 static/js/admin_common.js::mac_url_img），
 * 由本机代为取图，绕开对方的防盗链。
 *
 * 本文件不引导框架，是独立入口，因此 SSRF 防护必须自带：
 *   1. 只接受 http/https，且只允许 80/443 标准端口（堵内网端口探测与 user:pass@ 凭据外带）；
 *   2. 主机名解析出的每一个地址都必须是公网地址，解析失败一律拒绝（fail-closed）；
 *   3. 域名请求经 CURLOPT_RESOLVE 固定到已校验的 IP，关死「校验后再解析」的 DNS 重绑定窗口；
 *   4. 不交给 curl 自动跳转，逐跳重新走 1~3，最多 3 跳；
 *   5. 响应体必须嗅出真实图片魔数才回给浏览器，上限 5MB。
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

/** 校验单个 URL，通过则返回拆好的目标信息，否则 null。 */
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

/** 主机 → 全部解析地址；只要有一个落在内网/保留段就整体拒绝。 */
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
        // PHP 的 NO_RES_RANGE 不覆盖这两段，实测 100.64.0.1 能穿过上面的检查
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

/** 把响应里的 Location 归一化成绝对地址。 */
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

/** 有 CA 根证书才开启校验：本机 php.ini 未配 curl.cainfo 时强开会让所有 https 图取不到。 */
function macImgCaReady()
{
    static $ready = null;
    if ($ready === null) {
        $ca = (string)(ini_get('curl.cainfo') ?: ini_get('openssl.cafile'));
        $ready = $ca !== '' && is_file($ca);
    }
    return $ready;
}

/** 发一次请求（不自动跳转），返回状态码/响应体/Location。 */
function macImgRequest($url, $target, $maxBytes)
{
    $body = '';
    $status = 0;
    $location = '';
    $tooBig = false;

    // 主机名本身就是 IP 字面量时没有 DNS 环节可劫，写 RESOLVE 反而会被 libcurl 判为
    // 非法条目（[ipv6]:port:addr 解析不了）并直接中断请求，所以只在真域名时固定。
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
