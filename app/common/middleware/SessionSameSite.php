<?php
namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 苹果CMS v12 —— Session SameSite 中间件
 *
 * TP8 会话由 think\middleware\SessionInit 依据 config/session.php 启动，
 * 该配置已含 samesite=Lax。此中间件保留为兼容层：若会话尚未启动，
 * 则按配置显式应用 PHP 7.3+ 的 session_set_cookie_params。
 */
class SessionSameSite
{
    public function handle(Request $request, Closure $next)
    {
        if (PHP_SAPI !== 'cli') {
            if (PHP_VERSION_ID >= 70300 && !headers_sent() && session_status() !== PHP_SESSION_ACTIVE) {
                $cfg = function_exists('config') ? config('session') : [];
                if (is_array($cfg)) {
                    $ss = isset($cfg['samesite']) ? trim((string)$cfg['samesite']) : '';
                    if ($ss !== '' && $ss !== '0') {
                        $lifetime = isset($cfg['expire']) && (int)$cfg['expire'] > 0 ? (int)$cfg['expire'] : (int)ini_get('session.cookie_lifetime');
                        $path = (string)ini_get('session.cookie_path');
                        if ($path === '') {
                            $path = '/';
                        }
                        $domain = isset($cfg['domain']) ? (string)$cfg['domain'] : (string)ini_get('session.cookie_domain');
                        $secure = isset($cfg['secure']) ? (bool)$cfg['secure'] : (bool)ini_get('session.cookie_secure');
                        $httponly = array_key_exists('httponly', $cfg) ? (bool)$cfg['httponly'] : (bool)ini_get('session.cookie_httponly');
                        session_set_cookie_params([
                            'lifetime' => $lifetime,
                            'path'     => $path,
                            'domain'   => $domain,
                            'secure'   => $secure,
                            'httponly' => $httponly,
                            'samesite' => $ss,
                        ]);
                    }
                }
            }
        }
        return $next($request);
    }
}
