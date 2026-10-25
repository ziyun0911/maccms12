<?php
namespace app\common\middleware;

use Closure;
use app\common\util\RequestXssSanitizer;
use think\Request;
use think\Response;

/**
 * 苹果CMS v12 —— 请求 XSS 清洗中间件
 * （由 TP5 的 common\behavior\RequestSecurity 迁移）
 */
class RequestSecurity
{
    public function handle(Request $request, Closure $next)
    {
        $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app'])
            ? $GLOBALS['config']['app']
            : [];

        if (empty($app['security_xss_input']) || (string)$app['security_xss_input'] === '0') {
            return $next($request);
        }

        if (defined('ENTRANCE')) {
            if (ENTRANCE === 'install') {
                return $next($request);
            }
            if (ENTRANCE === 'admin') {
                $adminOn = isset($app['security_xss_admin']) && (string)$app['security_xss_admin'] === '1';
                if (!$adminOn) {
                    return $next($request);
                }
            }
        }

        $skipJson = !isset($app['security_xss_skip_json']) || (string)$app['security_xss_skip_json'] !== '0';
        if ($skipJson && !empty($_SERVER['CONTENT_TYPE'])
            && stripos((string)$_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
            $_GET = RequestXssSanitizer::sanitizeDeep($_GET);
            return $next($request);
        }

        $_GET  = RequestXssSanitizer::sanitizeDeep($_GET);
        $_POST = RequestXssSanitizer::sanitizeDeep($_POST);

        return $next($request);
    }
}
