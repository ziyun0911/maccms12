<?php

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
    || preg_match('/\.(bak|old|orig)([._]|$)/i', $last)
    || (preg_match('/\.([a-z0-9]+)$/i', $last, $m) && in_array(strtolower($m[1]), $deniedExt, true));

if ($denied) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    return true;
}

return false;
