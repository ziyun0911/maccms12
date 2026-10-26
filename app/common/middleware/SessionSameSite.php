<?php
namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

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
