<?php
/*
 * liveproxy.php —— 直播流同源代理（MACCMS v12 / PHP 8.x）
 *
 * 背景：站内 331 个频道的直播线路全部是第三方外链 m3u8，Hls.js 用 XHR 拉
 * playlist，而这些源不返回 Access-Control-Allow-Origin，浏览器直接拒绝响应。
 * 本脚本把「上游请求」放到服务端完成，浏览器只访问本站同源地址，从而绕开 CORS。
 *
 * 关键实现（缺一条都播不了）：
 *  1. 重写 m3u8 内所有 URL：分片、子 playlist、#EXT-X-KEY / MAP / MEDIA /
 *     I-FRAME-STREAM-INF 的 URI 全部改成同源代理地址，否则 ts 仍会跨域被拦。
 *  2. 手动逐跳跟随 302/307（上游时效鉴权跳转常见），每一跳都重新做 SSRF 校验。
 *  3. playlist 用 no-store（TARGETDURATION 会刷新），二进制分片流式转发不缓冲。
 *  4. 类型判定看响应体开头 #EXTM3U 与 Content-Type，不依赖 URL 后缀。
 *
 * 安全说明：
 *  - 禁止私网/回环/保留段/CGNAT/IPv6 本地段，DNS 解析结果全部必须公网；
 *    用 CURLOPT_RESOLVE 固定解析结果，避免 DNS rebinding 绕过校验。
 *  - 允许的源请在 $macLiveAllowHosts 里配置白名单（默认空 = 不限制，便于
 *    覆盖现有 152 个上游域名）。生产环境强烈建议填写白名单，并打开
 *    $macLiveRequireSameOriginReferer，避免本脚本被当作通用开放代理。
 *  - 只代理有权播放的直播源，遵守来源站点的服务条款与版权要求。
 *
 * 性能提示：PHP 逐分片转发有 CPU/带宽压力，生产建议改用 Nginx proxy_pass +
 * sub_filter 重写，或独立流代理服务。本脚本适合中小流量与验证环境。
 */

error_reporting(E_ERROR | E_PARSE);
@ini_set('display_errors', '0');           // 任何警告文本都会破坏二进制 ts 流
@ini_set('max_execution_time', '90');
@set_time_limit(90);

$macLiveMaxBytes   = 16 * 1024 * 1024;    // 单个响应上限，防内存被撑爆
$macLiveMaxHops    = 4;                   // 最大重定向跳数
$macLiveProbeBytes = 8192;                // 缓冲多少字节后判定是否 playlist
// 单个分片实测 1~5MB、经 PHP 逐块转发需数秒；原 25s/40s 常在中途被切断产生 500
$macLiveTimeout    = 60;
$macLiveConnectTmo = 8;

// 生产环境建议填上实际采集源域名（支持 *.example.com 通配），留空表示不限制。
$macLiveAllowHosts = [];
// 打开后只接受来自本站的请求（Hls.js 的 XHR 会带本站 Referer）。
$macLiveRequireSameOriginReferer = false;

// 常被滥用于内网服务的端口，直播源一般用 80/443/82/98/8080 等，不在其中。
$macLiveBlockedPorts = [21, 22, 23, 25, 110, 143, 445, 1433, 1521, 3306, 3389, 5432, 6379, 9200, 11211, 27017];

// 脚本自身路径：用 SCRIPT_NAME 生成重写地址，站点部署在子目录时也成立。
$macLiveScript = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/liveproxy.php';
$macLiveScript = preg_replace('#[^A-Za-z0-9_/\.\-]#', '', $macLiveScript);
if ($macLiveScript === '' || $macLiveScript[0] !== '/') {
    $macLiveScript = '/liveproxy.php';
}
$macLiveIsHead = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');

function macLiveStop($code, $msg)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $msg;
    exit;
}

/** 是否为公网 IP；IPv4 额外排除 CGNAT 100.64/10 与 198.18/15 基准测试段。 */
function macLiveIpPublic($ip)
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
            if (macLiveInCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }
    $low = strtolower($ip);
    if (strpos($low, '::ffff:') === 0) {
        return macLiveIpPublic(substr($low, 7));
    }
    foreach (['::1', 'fe80', 'fc', 'fd', 'ff'] as $blocked) {
        if (strpos($low, $blocked) === 0) {
            return false;
        }
    }
    return true;
}

function macLiveInCidr($ip, $cidr)
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

/** 解析主机得到全部 A/AAAA 记录，任意一个不公网就整体拒绝。 */
function macLivePublicIps($host)
{
    if ($host === '' || $host === 'localhost'
        || substr($host, -6) === '.local' || substr($host, -9) === '.localhost'
        || substr($host, -9) === '.internal') {
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
        if (!macLiveIpPublic($ip)) {
            return [];
        }
    }
    return $ips;
}

function macLiveHostAllowed($host)
{
    global $macLiveAllowHosts;
    if ($macLiveAllowHosts === []) {
        return true;
    }
    foreach ($macLiveAllowHosts as $rule) {
        $rule = strtolower(trim((string)$rule));
        if ($rule === '') {
            continue;
        }
        if (strpos($rule, '*.') === 0) {
            if (substr($host, -strlen($rule) + 1) === substr($rule, 1)) {
                return true;
            }
        } elseif ($host === $rule) {
            return true;
        }
    }
    return false;
}

/** 校验单跳目标，返回 ['scheme','host','port','ips'] 或 null。 */
function macLiveTarget($url)
{
    global $macLiveBlockedPorts;
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
    if ($port < 1 || $port > 65535 || in_array($port, $macLiveBlockedPorts, true)) {
        return null;
    }
    $host = strtolower(trim($parts['host'], '[]'));
    if (!macLiveHostAllowed($host)) {
        return null;
    }
    $ips = macLivePublicIps($host);
    if ($ips === []) {
        return null;
    }
    return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips];
}

function macLiveCaReady()
{
    static $ready = null;
    if ($ready === null) {
        $ca = (string)(ini_get('curl.cainfo') ?: ini_get('openssl.cafile'));
        $ready = $ca !== '' && is_file($ca);
    }
    return $ready;
}

/** 规范化 URL 路径中的 ./ 与 ../，保留 query。 */
function macLiveNormalizeUrl($url)
{
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host'])) {
        return $url;
    }
    $path = $p['path'] ?? '/';
    $segs = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($segs);
            continue;
        }
        $segs[] = $seg;
    }
    $newPath = '/' . implode('/', $segs);
    if (substr($path, -1) === '/' && substr($newPath, -1) !== '/') {
        $newPath .= '/';
    }
    $root = ($p['scheme'] ?? 'http') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    return $root . $newPath . (isset($p['query']) ? '?' . $p['query'] : '');
}

/** 把 playlist 里的相对/协议相对/绝对引用解析成绝对 URL。 */
function macLiveResolveUri($base, $ref)
{
    $ref = trim((string)$ref);
    if ($ref === '') {
        return '';
    }
    // data: / blob: 是内联内容，不代理也不能解析
    if (preg_match('#^(data|blob|about):#i', $ref)) {
        return $ref;
    }
    if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $ref)) {
        return macLiveNormalizeUrl($ref);
    }
    $p = parse_url($base);
    if (!is_array($p) || empty($p['host'])) {
        return $ref;
    }
    $scheme = strtolower($p['scheme'] ?? 'http');
    $root = $scheme . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($ref, '//') === 0) {
        return macLiveNormalizeUrl($scheme . ':' . $ref);
    }
    if ($ref[0] === '/') {
        return macLiveNormalizeUrl($root . $ref);
    }
    $path = $p['path'] ?? '/';
    $slash = strrpos($path, '/');
    $dir = $slash === false ? '/' : substr($path, 0, $slash + 1);
    return macLiveNormalizeUrl($root . $dir . $ref);
}

/** 生成同源代理地址；内联 scheme 原样返回。 */
function macLiveProxyUrl($abs)
{
    global $macLiveScript;
    $abs = (string)$abs;
    if ($abs === '' || preg_match('#^(data|blob|about):#i', $abs)) {
        return $abs;
    }
    return $macLiveScript . '?url=' . rawurlencode($abs);
}

/** 用响应体开头或 Content-Type 判断是不是 HLS playlist。 */
function macLiveLooksPlaylist($buf, $ctype)
{
    if ($ctype !== '' && (stripos($ctype, 'mpegurl') !== false || stripos($ctype, 'x-mpegurl') !== false)) {
        return true;
    }
    $probe = preg_replace('/^\xEF\xBB\xBF/', '', $buf);
    return strncmp($probe, '#EXTM3U', 7) === 0;
}

/** 重写 playlist：所有非注释行 + 所有标签里的 URI 属性都改成同源地址。 */
function macLiveRewritePlaylist($body, $base)
{
    $lines = preg_split('/\R/', $body);
    if (!is_array($lines)) {
        $lines = [$body];
    }
    $out = [];
    foreach ($lines as $line) {
        $line = rtrim((string)$line, "\r\n");
        if (trim($line) === '') {
            $out[] = '';
            continue;
        }
        if (ltrim($line)[0] === '#') {
            // EXT-X-KEY / MAP / MEDIA / SESSION-KEY / I-FRAME-STREAM-INF 的 URI
            $out[] = preg_replace_callback(
                '/\bURI\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^,\s]+))/i',
                function ($m) use ($base) {
                    $uri = '';
                    if (isset($m[1]) && $m[1] !== '') {
                        $uri = $m[1];
                    } elseif (isset($m[2]) && $m[2] !== '') {
                        $uri = $m[2];
                    } elseif (isset($m[3])) {
                        $uri = $m[3];
                    }
                    $abs = macLiveResolveUri($base, $uri);
                    return 'URI="' . macLiveProxyUrl($abs) . '"';
                },
                $line
            );
        } else {
            $out[] = macLiveProxyUrl(macLiveResolveUri($base, trim($line)));
        }
    }
    return implode("\n", $out) . "\n";
}

function macLiveEmitPlaylistHeaders()
{
    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
}

function macLiveEmitMediaHeaders($ctype)
{
    $type = trim((string)$ctype);
    if ($type === '' || stripos($type, 'text/html') !== false) {
        $type = 'video/mp2t';
    }
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=2');
    header('Access-Control-Allow-Origin: *');
}

/**
 * 请求一跳。3xx 只缓冲；200 时若判定为 playlist 则完整缓冲待重写，
 * 若是二进制分片则发头后边收边发，不把整个分片留在内存里。
 */
function macLiveFetch($url, $target, $maxBytes, $probeBytes, $timeout, $connectTmo)
{
    global $macLiveIsHead;
    $status = 0;
    $ctype = '';
    $location = '';
    $buf = '';
    $classified = false;
    $isPlaylist = false;
    $sent = false;
    $tooBig = false;
    $total = 0;

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
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => $connectTmo,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => 'identity',       // 禁止 gzip，否则二进制/文本判断都会出错
        CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0', 'Accept: */*'],
        CURLOPT_SSL_VERIFYPEER => macLiveCaReady(),
        CURLOPT_SSL_VERIFYHOST => macLiveCaReady() ? 2 : 0,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$status, &$ctype, &$location) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m)) {
                $status = (int)$m[1];
                $ctype = '';
                $location = '';
            } elseif (preg_match('#^Content-Type:\s*(.+?)\s*$#i', $header, $m)) {
                $ctype = trim($m[1]);
            } elseif (preg_match('#^Location:\s*(.+?)\s*$#i', $header, $m)) {
                $location = trim($m[1]);
            }
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (
            &$status, &$ctype, &$buf, &$classified, &$isPlaylist, &$sent,
            &$tooBig, &$total, $maxBytes, $probeBytes
        ) {
            global $macLiveIsHead;
            $len = strlen($chunk);
            $total += $len;
            if ($total > $maxBytes) {
                $tooBig = true;
                return -1;                    // 中止 curl
            }
            // 非 200 的响应（重定向体、错误页）只缓冲，交给调用方判断
            if ($status < 200 || $status >= 300) {
                $buf .= $chunk;
                return $len;
            }
            if (!$classified) {
                $buf .= $chunk;
                if (macLiveLooksPlaylist($buf, $ctype)) {
                    $classified = true;
                    $isPlaylist = true;
                    return $len;              // playlist 继续缓冲，最后统一重写
                }
                if (strlen($buf) >= $probeBytes) {
                    $classified = true;
                    $isPlaylist = false;
                    macLiveEmitMediaHeaders($ctype);
                    if (!$macLiveIsHead) {
                        echo $buf;
                    }
                    $buf = '';
                    $sent = true;
                }
                return $len;
            }
            if ($isPlaylist) {
                $buf .= $chunk;
            } elseif (!$macLiveIsHead) {
                echo $chunk;                  // 流式转发分片
            }
            return $len;
        },
    ];
    if ($resolve !== []) {
        $opts[CURLOPT_RESOLVE] = $resolve;
    }
    curl_setopt_array($ch, $opts);
    $ok = curl_exec($ch);
    curl_close($ch);

    // 响应不足 probeBytes 时 WRITEFUNCTION 未能分类，这里收尾判定
    if (!$classified && !$sent && $status >= 200 && $status < 300) {
        $isPlaylist = macLiveLooksPlaylist($buf, $ctype);
        $classified = true;
    }
    return [
        'ok' => $ok !== false,
        'status' => $status,
        'ctype' => $ctype,
        'location' => $location,
        'body' => $buf,
        'isPlaylist' => $isPlaylist,
        'sent' => $sent,
        'tooBig' => $tooBig,
    ];
}

/* ------------------------------------------------------- 302 节点粘性（签名 Cookie）
 * 线路入口每次 302 都轮询到不同后端节点，各节点 MEDIA-SEQUENCE 完全不同步，
 * hls.js 刷新 playlist 遇到序号突变会跳片、清空缓冲。这里把首次解析出的
 * 「最终节点 URL（含 tm/key）」记进签名 Cookie，同一播放会话内后续 playlist
 * 请求直接复用它；节点下线或鉴权过期时自动回退到入口重新解析。
 * 刻意不用 $_SESSION：PHP 会话文件锁会把并发分片请求串行化，反而拖慢播放。
 * 服务端仍会对粘性 URL 走完整的 macLiveTarget 公网/端口校验，Cookie 不能绕过 SSRF 防护。
 */
$macLiveStickyTtl    = 180;   // 实测 45s 内鉴权有效；播放中会滑动续期，失败则立即重解析
$macLiveStickyMax    = 4;     // Cookie 里最多保留 4 条线路映射
$macLiveStickyCookie = 'mlsticky';

function macLiveStickySecret()
{
    return hash('sha256', 'maccms-live-proxy-sticky|' . (string)($_SERVER['HTTP_HOST'] ?? ''), true);
}

function macLiveStickyLoad()
{
    global $macLiveStickyCookie;
    $raw = (string)($_COOKIE[$macLiveStickyCookie] ?? '');
    if ($raw === '' || substr_count($raw, '.') !== 1) {
        return [];
    }
    list($body, $sig) = explode('.', $raw, 2);
    $want = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, macLiveStickySecret(), true)), '+/', '-_'), '=');
    if (!hash_equals($want, $sig)) {
        return [];
    }
    $data = json_decode((string)base64_decode(strtr($body, '-_', '+/'), true), true);
    return is_array($data) ? $data : [];
}

function macLiveStickySave($data)
{
    global $macLiveStickyCookie, $macLiveStickyMax;
    while (count($data) > $macLiveStickyMax) {
        array_shift($data);
    }
    $body = rtrim(strtr(base64_encode((string)json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $sig  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, macLiveStickySecret(), true)), '+/', '-_'), '=');
    setcookie($macLiveStickyCookie, $body . '.' . $sig, [
        'expires'  => time() + 600,
        'path'     => '/',
        'samesite' => 'Lax',
        'httponly' => true,
    ]);
}

function macLiveStickyGet($data, $key)
{
    global $macLiveStickyTtl;
    if (!isset($data[$key]) || !is_array($data[$key])) {
        return '';
    }
    $url = (string)($data[$key]['u'] ?? '');
    $ts  = (int)($data[$key]['t'] ?? 0);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    if ($ts <= 0 || $ts > time() + 60 || time() - $ts > $macLiveStickyTtl) {
        return '';
    }
    return $url;
}

/** 跑一遍「请求 + 逐跳 302」，返回最终 URL 与响应。二进制分片会边收边发并直接 exit。 */
function macLiveRunChain($startUrl, &$finalUrlOut, &$redirectedOut)
{
    global $macLiveMaxHops, $macLiveMaxBytes, $macLiveProbeBytes,
           $macLiveTimeout, $macLiveConnectTmo;
    $finalUrl   = $startUrl;
    $redirected = false;
    $response   = null;
    for ($hop = 0; $hop <= $macLiveMaxHops; $hop++) {
        $target = macLiveTarget($finalUrl);
        if ($target === null) {
            return ['forbidden' => true];
        }
        $response = macLiveFetch(
            $finalUrl, $target, $macLiveMaxBytes, $macLiveProbeBytes,
            $macLiveTimeout, $macLiveConnectTmo
        );
        if ($response['sent']) {
            exit;                            // 分片已流式输出，不能重试
        }
        if ($response['tooBig']) {
            macLiveStop(413, 'upstream response too large');
        }
        $status = $response['status'];
        if ($status >= 300 && $status < 400 && $response['location'] !== '') {
            $next = macLiveResolveUri($finalUrl, $response['location']);
            if ($next === '') {
                macLiveStop(502, 'bad redirect');
            }
            // 每一跳都会重新走 macLiveTarget 的公网/端口校验
            $finalUrl   = $next;
            $redirected = true;
            continue;
        }
        $finalUrlOut   = $finalUrl;
        $redirectedOut = $redirected;
        return ['response' => $response];
    }
    macLiveStop(414, 'too many redirects');
}

/* ---------------------------------------------------------------- 主流程 */

$src = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($src === '') {
    macLiveStop(400, 'bad request: missing url');
}
if (!preg_match('#^https?://#i', $src)) {
    macLiveStop(400, 'bad request: only http(s) allowed');
}

// 可选：只接受本站发起的请求，降低被当开放代理的风险
if ($macLiveRequireSameOriginReferer) {
    $refHost = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
    $selfHost = $_SERVER['HTTP_HOST'] ?? '';
    $selfHost = preg_replace('#:\d+$#', '', (string)$selfHost);
    if ($refHost === null || strcasecmp((string)$refHost, $selfHost) !== 0) {
        macLiveStop(403, 'forbidden referer');
    }
}

// 关掉输出缓冲，保证 ts 分片边收边发；任何 PHP 警告都会污染二进制流，已在顶部关闭。
// mod_fcgid 下可能存在不可移除的缓冲层，ob_end_clean() 会返回 false 且层级不变，
// 所以必须带上限并在失败时跳出，否则会死循环导致 500。
for ($i = 0; $i < 8 && ob_get_level() > 0; $i++) {
    if (!@ob_end_clean()) {
        break;
    }
}
@ob_implicit_flush(true);

$stickyKey  = substr(md5($src), 0, 12);
$stickyData = macLiveStickyLoad();
$stickyUrl  = macLiveStickyGet($stickyData, $stickyKey);

$chosen = null;

// 优先复用已锁定的节点：跳过入口 302，避免每次刷新 playlist 都换节点
if ($stickyUrl !== '') {
    $stickyFinal     = '';
    $stickyRedirect  = false;
    $try             = macLiveRunChain($stickyUrl, $stickyFinal, $stickyRedirect);
    $tryResp         = isset($try['response']) ? $try['response'] : null;
    if ($tryResp !== null && $tryResp['ok'] && $tryResp['status'] >= 200
        && $tryResp['status'] < 300 && $tryResp['body'] !== ''
        && macLiveLooksPlaylist($tryResp['body'], $tryResp['ctype'])) {
        // 复用成功：滑动续期，持续播放期间节点始终固定
        $stickyData[$stickyKey] = ['u' => $stickyFinal, 't' => time()];
        macLiveStickySave($stickyData);
        $chosen = ['response' => $tryResp, 'finalUrl' => $stickyFinal];
    } else {
        // 节点下线 / 鉴权过期：清掉映射，回退到入口重新解析
        unset($stickyData[$stickyKey]);
        macLiveStickySave($stickyData);
    }
}

if ($chosen === null) {
    $finalUrl   = '';
    $redirected = false;
    $try        = macLiveRunChain($src, $finalUrl, $redirected);
    if (isset($try['forbidden'])) {
        macLiveStop(403, 'forbidden target');
    }
    $response = $try['response'];
    if (!$response['ok'] || $response['status'] === 0) {
        macLiveStop(502, 'upstream fetch failed');
    }
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $code = $response['status'] < 600 ? $response['status'] : 502;
        macLiveStop($code, 'upstream error');
    }
    if ($response['body'] === '') {
        macLiveStop(404, 'empty upstream response');
    }
    $chosen = ['response' => $response, 'finalUrl' => $finalUrl];
    if ($redirected && macLiveLooksPlaylist($response['body'], $response['ctype'])) {
        // 只记录「入口发生过 302」的 playlist 请求；分片 URL 的 key 不同，不会被误锁
        $stickyData[$stickyKey] = ['u' => $finalUrl, 't' => time()];
        macLiveStickySave($stickyData);
    }
}

$response = $chosen['response'];
$finalUrl = $chosen['finalUrl'];

if ($response['isPlaylist']) {
    // 基准地址用「重定向后的最终 URL」，否则相对分片会解析到错误的目录
    $out = macLiveRewritePlaylist($response['body'], $finalUrl);
    macLiveEmitPlaylistHeaders();
    if (!$macLiveIsHead) {
        echo $out;
    }
} else {
    macLiveEmitMediaHeaders($response['ctype']);
    if (!$macLiveIsHead) {
        echo $response['body'];
    }
}
