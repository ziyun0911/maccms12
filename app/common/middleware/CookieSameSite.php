<?php
namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

class CookieSameSite
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (PHP_SAPI === 'cli') {
            return $response;
        }
        if (!$response instanceof Response) {
            return $response;
        }

        $cfg = function_exists('config') ? config('cookie') : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $ss = isset($cfg['samesite']) ? trim((string)$cfg['samesite']) : '';
        if ($ss === '' || $ss === '0') {
            return $response;
        }

        $cookies = [];
        $current = $response->getHeader('Set-Cookie');
        foreach ((array)$current as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $h = ltrim(substr($h, strlen('Set-Cookie:')));
            }
            if ($h !== '') {
                $cookies[] = $h;
            }
        }
        if (empty($cookies)) {
            return $response;
        }

        $response->header('Set-Cookie', null);
        foreach ($cookies as $cookie) {
            $response->header('Set-Cookie', self::applySameSite($cookie, $ss));
        }

        return $response;
    }

    public static function applySameSite($cookie, $samesite)
    {
        if (stripos($cookie, 'samesite=') !== false) {
            return $cookie;
        }
        $cookie = rtrim($cookie);
        if ($cookie === '') {
            return $cookie;
        }
        if (substr($cookie, -1) !== ';') {
            $cookie .= ';';
        }
        return $cookie . ' SameSite=' . $samesite;
    }
}
