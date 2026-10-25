<?php
namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 苹果CMS v12 —— 安全响应头中间件
 * （由 TP5 的 common\behavior\SecurityHeaders 迁移，改为对 Response 对象注入响应头）
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (!$response instanceof Response) {
            return $response;
        }

        // 前台模板缺失时 All::fetch() 回退到 404 页面，这里把响应码同步为 404
        if (!empty($GLOBALS['__tpl_not_found']) && $response->getCode() === 200) {
            $response->code(404);
        }

        $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app'])
            ? $GLOBALS['config']['app']
            : [];

        $base = !empty($app['security_headers_base']) && (string)$app['security_headers_base'] === '0'
            ? []
            : [
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy'        => 'strict-origin-when-cross-origin',
                'X-DNS-Prefetch-Control' => 'off',
                'X-Frame-Options'        => 'SAMEORIGIN',
            ];
        if ($base !== []) {
            $response->header($base);
            if (self::isHttpsRequest()) {
                $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            }
        }

        if (defined('ENTRANCE') && ENTRANCE === 'install') {
            return $response;
        }

        $cspMode = isset($app['security_csp']) ? (string)$app['security_csp'] : '0';
        if ($cspMode === '' || $cspMode === '0') {
            return $response;
        }

        $policy = isset($app['security_csp_policy']) ? trim((string)$app['security_csp_policy']) : '';
        if ($policy === '') {
            $policy = self::defaultCspPolicy();
        }

        $report = isset($app['security_csp_report_uri']) ? trim((string)$app['security_csp_report_uri']) : '';
        if ($report !== '') {
            $policy .= (substr(rtrim($policy), -1) === ';' ? ' ' : '; ') . 'report-uri ' . $report;
        }

        if ($cspMode === '2') {
            $response->header('Content-Security-Policy-Report-Only', $policy);
        } else {
            $response->header('Content-Security-Policy', $policy);
        }

        return $response;
    }

    /**
     * 兼容苹果 CMS 常见模板：允许内联脚本/样式、外链图片与播放器 iframe。
     */
    public static function defaultCspPolicy()
    {
        return implode(' ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https: http:",
            "style-src 'self' 'unsafe-inline' https: http:",
            "img-src 'self' data: blob: https: http:",
            "font-src 'self' data: https: http:",
            "connect-src 'self' https: http: ws: wss:",
            "media-src 'self' blob: https: http:",
            "frame-src 'self' https: http:",
            "worker-src 'self' blob:",
            "form-action 'self' https: http:",
        ]);
    }

    protected static function isHttpsRequest()
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')) {
            return true;
        }
        return false;
    }
}
