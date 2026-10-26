<?php
namespace app\common\middleware;

use Closure;
use think\exception\HttpResponseException;
use think\Request;
use think\Response;

class IpBlock
{
    public function handle(Request $request, Closure $next)
    {
        try {
            if (PHP_SAPI === 'cli') {
                return $next($request);
            }
            if (defined('ENTRANCE') && ENTRANCE === 'admin') {
                return $next($request);
            }

            $blacks = config('blacks');
            if (!is_array($blacks) || empty($blacks['black_ip_list']) || !is_array($blacks['black_ip_list'])) {
                return $next($request);
            }

            $ip = (string)mac_get_client_ip();
            if ($ip === '' || $ip === '0.0.0.0') {
                return $next($request);
            }

            $map = array_flip(array_map('strval', $blacks['black_ip_list']));
            if (!isset($map[$ip])) {
                return $next($request);
            }

            throw new HttpResponseException(response('', 403));
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
        }

        return $next($request);
    }
}
