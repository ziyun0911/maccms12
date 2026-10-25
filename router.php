<?php
/**
 * php -S 内置服务器访问控制路由（仅本地开发用）。
 * 内置服务器不读 .htaccess，也不吃 nginx/IIS 配置，所以根目录 .htaccess 里的
 * 拦截规则在这里等价复刻：命中内部路径返回 403，其余一律 return false，
 * 交回内置服务器按原样处理（PATH_INFO 入口与静态文件行为完全不变）。
 *
 * 判断以 realpath() 解析出的磁盘真实路径为准，不按请求字符串比对：
 * Windows 版内置服务器会把 REQUEST_URI 按系统 ANSI(GBK) 码页解释，
 * 同一份文档既可能以 UTF-8 百分号编码到达，也可能以 GBK 原始字节到达，
 * 只有解析到同一个 inode 才能确保拦得住；顺带也拦掉 ../ 穿越写法。
 *
 * 启用方式见 D:/wwwroot/maccms/_tmp/start_8080.ps1：
 *   php -S 127.0.0.1:8080 -t <docroot> router.php
 */

$internalDirs = ['说明文档', 'runtime', 'vendor', 'config', 'app', 'extend'];
$internalFiles = ['composer.json', 'composer.lock', 'think', '.user.ini', 'router.php'];
$deniedExt = ['md', 'sql', 'log', 'lock', 'wpm', 'sh', 'ps1', 'py'];

$uri = $_SERVER['REQUEST_URI'] ?? '/';
if (($q = strpos($uri, '?')) !== false) {
    $uri = substr($uri, 0, $q);
}
$uri = str_replace('\\', '/', rawurldecode($uri));

$docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
$rel = trim($uri, '/');
if ($docRoot !== '') {
    $hit = realpath($docRoot . $uri);
    if ($hit === false) {
        // 请求里还留着未解码的百分号写法时，解码后再试一次
        $hit = realpath($docRoot . rawurldecode($uri));
    }
    if ($hit !== false) {
        $hit = str_replace('\\', '/', $hit);
        if (strpos($hit, $docRoot . '/') === 0) {
            $rel = substr($hit, strlen($docRoot) + 1);
        }
    }
}

$segments = array_values(array_filter(explode('/', $rel), function ($s) {
    return $s !== '';
}));
$first = $segments[0] ?? '';
$last = $segments ? (string)end($segments) : '';

$denied = in_array($first, $internalDirs, true)
    || in_array($last, $internalFiles, true)
    || ($first !== '' && $first[0] === '.' && $first !== '.well-known')
    || (preg_match('/\.([a-z0-9]+)$/i', $last, $m) && in_array(strtolower($m[1]), $deniedExt, true));

if ($denied) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    return true;
}

return false;
