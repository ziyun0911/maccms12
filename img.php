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

// 可选兜底：调用方带 fb=<台名> 时，上游取图失败不再返回纯文本错误，而是返回
// 一张本地生成的 SVG 文字台标，避免直播卡片破图 / 空白。不传 fb 时行为与改造前
// 完全一致，img.php 仍是一个通用图片代理。SVG 由服务端生成、不含任何外链，
// 原有的 SSRF 校验（macImgTarget / macImgPublicIps）与图片魔数校验均不改动。
$macImgFallbackName = '';
if (isset($_GET['fb'])) {
    // 非法 UTF-8 的 fb 会让 preg_replace 返回 null，统一转回字符串再判空；
    // 只要调用方传了非空 fb，就保证能拿到兜底图（内容异常时用默认台标名）
    $fbRaw = (string)preg_replace('/[\x00-\x1F\x7F]+/u', '', trim((string)$_GET['fb']));
    if ($fbRaw !== '') {
        $macImgFallbackName = mb_substr($fbRaw, 0, 16, 'UTF-8');
    } elseif (trim((string)$_GET['fb']) !== '') {
        $macImgFallbackName = '直播';
    }
}
$macImgFailCacheDir = __DIR__ . '/runtime/img_fallback/';
$macImgFailCacheTtl = 1800;
$macImgOriginUrl = '';

// 框体尺寸：调用方可用 w / h 指定图片实际容器的宽高（默认 200×290 竖版台标）。
// 字号、行距、装饰圆、圆角、底部条全部按框体计算，不再写死像素；
// 播放页侧栏 40×40 的小框会传 &w=40&h=40，专栏横版传 &w=276&h=160。
// 限制范围防止异常参数生成过大的 SVG；不传 fb 时这些值不参与任何逻辑。
$macImgBoxW = isset($_GET['w']) ? (int)$_GET['w'] : 200;
$macImgBoxH = isset($_GET['h']) ? (int)$_GET['h'] : 290;
if ($macImgBoxW < 32 || $macImgBoxW > 800) {
    $macImgBoxW = 200;
}
if ($macImgBoxH < 32 || $macImgBoxH > 800) {
    $macImgBoxH = 290;
}

function macImgNum($v)
{
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
}

// 以 em 为单位估算一行文字的占位宽度：CJK/全角 1.0、ASCII 大写数字 0.62、
// 小写 0.55、空格 0.30、其他 0.80。比「按字符数乘固定系数」准得多，
// CCTV-14 与「少儿」不再被当成同样宽。
function macImgTextUnits($s)
{
    $chars = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) {
        return max(0.5, mb_strlen((string)$s, 'UTF-8') * 0.8);
    }
    $units = 0.0;
    foreach ($chars as $ch) {
        if ($ch === ' ') {
            $units += 0.30;
        } elseif (preg_match('/[A-Z0-9+\-\.]/', $ch)) {
            $units += 0.62;
        } elseif (preg_match('/[a-z]/', $ch)) {
            $units += 0.55;
        } elseif (preg_match('/^[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]$/u', $ch)) {
            $units += 1.00;
        } else {
            $units += 0.80;
        }
    }
    return max(0.5, $units);
}

// 分行优先按「频道编号 + 名称」的语义边界，避免把 CCTV-14 拆成 CCTV-1 / 4。
function macImgSplitLines($name)
{
    $len = mb_strlen($name, 'UTF-8');
    if (preg_match('/^([A-Za-z0-9][A-Za-z0-9+\-]{0,11})\s+(.+)$/u', $name, $m)) {
        $tail = mb_substr($m[2], 0, 8, 'UTF-8');
        $lines = [$m[1], $tail . (mb_strlen($m[2], 'UTF-8') > 8 ? '…' : '')];
    } elseif (mb_strpos($name, ' ') !== false) {
        $p = preg_split('/\s+/u', $name, 2);
        $lines = [mb_substr($p[0], 0, 9, 'UTF-8'), mb_substr($p[1] ?? '', 0, 9, 'UTF-8')];
    } elseif ($len > 6) {
        $cut = (int)ceil($len / 2);
        $lines = [mb_substr($name, 0, $cut, 'UTF-8'), mb_substr($name, $cut, 8, 'UTF-8')];
    } else {
        $lines = [$name];
    }
    return array_values(array_filter($lines, fn($l) => $l !== ''));
}

// 小框体（如播放页侧栏 40×40）放不下完整台标，改用短标识：
// 有「编号 + 名称」时取名称前 2 字（少儿 / 社会），纯编号取前 4 字符（CETV），
// 纯中文台名取前 2 字（浙江）。这样小框里字号仍能撑满，不会被压到 6px。
function macImgShortLabel($name, $lines)
{
    $tail = count($lines) > 1 ? end($lines) : $name;
    $tail = preg_replace('/\s+/u', '', (string)$tail);
    $chars = preg_split('//u', $tail, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars) || $chars === []) {
        return mb_substr($name, 0, 2, 'UTF-8');
    }
    $cjk = (bool)preg_match('/^[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}]$/u', $chars[0]);
    return implode('', array_slice($chars, 0, $cjk ? 2 : 4));
}

// 按框体宽高求最大可容纳字号：宽度约束 = 可用宽 / 最长行 em 数，
// 高度约束 = 可用高 / (行数 × 行距)，再取框体自身的上限，最后留 6% 安全余量。
function macImgFitFontSize($lines, $W, $H)
{
    $padX = max(3, $W * 0.10);
    $padY = max(3, $H * 0.10);
    $availW = max(8, $W - 2 * $padX);
    $availH = max(8, $H - 2 * $padY);
    $units = 0.5;
    foreach ($lines as $l) {
        $units = max($units, macImgTextUnits($l));
    }
    $count = max(1, count($lines));
    $byW = $availW / $units;
    $byH = $availH / ($count * 1.28);
    $cap = min($H * 0.62, $W * 0.85, 180);
    $size = (int)floor(min($byW, $byH, $cap) * 0.94);
    return max(5, $size);
}

function macImgFallbackSvg($name, $W, $H)
{
    $lines = macImgSplitLines($name);
    // 小框强制单行 + 短标识，字号由框体决定而不是由台名长度硬编码
    if (min($W, $H) < 90) {
        $lines = [macImgShortLabel($name, $lines)];
    }
    $size = macImgFitFontSize($lines, $W, $H);
    $lineHeight = $size * 1.28;
    $centerY = $H * 0.52;
    $top = $centerY - (count($lines) - 1) * $lineHeight / 2;
    $cx = $W / 2;
    $texts = '';
    foreach ($lines as $i => $l) {
        $texts .= '<text x="' . macImgNum($cx) . '" y="' . macImgNum($top + $i * $lineHeight) . '"'
            . ' text-anchor="middle" dominant-baseline="central"'
            . ' font-family="Microsoft YaHei,PingFang SC,Hiragino Sans GB,sans-serif"'
            . ' font-size="' . $size . '" font-weight="700" fill="#ffffff">'
            . htmlspecialchars($l, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</text>';
    }

    // 装饰元素同样按框体比例定位，40×40 与 276×160 都成立
    $rx = max(3, min($W, $H) * 0.05);
    $r = min($W, $H) * 0.18;
    $cy = $H * 0.28;
    $stroke = max(1, min($W, $H) * 0.007);
    $barW = $W * 0.44;
    $barH = max(1.5, $H * 0.014);
    $barX = ($W - $barW) / 2;
    $barY = $H * 0.89;
    $lineW = $r * 1.46;

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '"'
        . ' viewBox="0 0 ' . $W . ' ' . $H . '">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="#1f8a6d"/><stop offset="1" stop-color="#40cc92"/>'
        . '</linearGradient></defs>'
        . '<rect width="' . $W . '" height="' . $H . '" rx="' . macImgNum($rx) . '" fill="url(#g)"/>'
        . '<circle cx="' . macImgNum($cx) . '" cy="' . macImgNum($cy) . '" r="' . macImgNum($r) . '"'
        . ' fill="#ffffff" fill-opacity=".08"/>'
        . '<circle cx="' . macImgNum($cx) . '" cy="' . macImgNum($cy) . '" r="' . macImgNum($r) . '"'
        . ' fill="none" stroke="#ffffff" stroke-opacity=".28" stroke-width="' . macImgNum($stroke) . '"/>'
        . '<path d="M' . macImgNum($cx - $lineW / 2) . ' ' . macImgNum($cy) . 'h' . macImgNum($lineW) . '"'
        . ' stroke="#ffffff" stroke-opacity=".28" stroke-width="' . macImgNum($stroke) . '"/>'
        . $texts
        . '<rect x="' . macImgNum($barX) . '" y="' . macImgNum($barY) . '"'
        . ' width="' . macImgNum($barW) . '" height="' . macImgNum($barH) . '"'
        . ' rx="' . macImgNum($barH / 2) . '" fill="#ffffff" fill-opacity=".45"/>'
        . '</svg>';
}

function macImgFallbackEmit($name)
{
    global $macImgBoxW, $macImgBoxH;
    $svg = macImgFallbackSvg($name, $macImgBoxW, $macImgBoxH);
    http_response_code(200);
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Content-Length: ' . strlen($svg));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    echo $svg;
    exit;
}

function macImgFailCacheFile($url, $dir)
{
    return $dir . md5($url) . '.txt';
}

function macImgStop($code, $msg)
{
    global $macImgFallbackName, $macImgFailCacheDir, $macImgOriginUrl;
    if ($macImgFallbackName !== '') {
        // 记录失败，短时间内不再重复打上游，避免页面上大量失效台标逐个连接超时
        if ($macImgOriginUrl !== '') {
            if (!is_dir($macImgFailCacheDir)) {
                @mkdir($macImgFailCacheDir, 0755, true);
            }
            @file_put_contents(macImgFailCacheFile($macImgOriginUrl, $macImgFailCacheDir), (string)time());
        }
        macImgFallbackEmit($macImgFallbackName);
    }
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
$macImgOriginUrl = $src;
// 仅传 fb（无真实图源）时，直接返回品牌文字台标，保证空台标频道也有图可显
if ($src === '' && $macImgFallbackName !== '') {
    macImgFallbackEmit($macImgFallbackName);
}
if ($src === '' || !preg_match('#^https?://#i', $src)) {
    macImgStop(400, 'bad request');
}

// 失败缓存命中时直接返回文字台标，不再打上游（仅 fb 模式生效）
if ($macImgFallbackName !== '') {
    $failCacheFile = macImgFailCacheFile($macImgOriginUrl, $macImgFailCacheDir);
    if (is_file($failCacheFile) && (time() - (int)@filemtime($failCacheFile)) < $macImgFailCacheTtl) {
        macImgFallbackEmit($macImgFallbackName);
    }
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

// 上游恢复后清掉失败缓存，下一次请求立刻回到真实台标
if ($macImgFallbackName !== '') {
    $failCacheFile = macImgFailCacheFile($macImgOriginUrl, $macImgFailCacheDir);
    if (is_file($failCacheFile)) {
        @unlink($failCacheFile);
    }
}

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($response['body']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
echo $response['body'];
