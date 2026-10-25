<?php
namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 苹果CMS v12 —— 业务 Cookie SameSite 中间件
 * （由 TP5 的 common\behavior\CookieSameSite 迁移）
 *
 * 在 TP8 中，Cookie 由 Response 对象统一管理；此处对已生成的所有 Set-Cookie
 * 响应头逐条补齐 SameSite 属性。
 */
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

    /**
     * 若 Set-Cookie 值尚未带 SameSite，则追加；已带则原样返回。
     */
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
