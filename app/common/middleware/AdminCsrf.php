<?php
namespace app\common\middleware;

use Closure;
use think\facade\Session;
use think\Request;
use think\Response;

/**
 * 后台 CSRF 强制校验。
 *
 * 在控制器构造函数中按已解析的 controller/action 调用，避免应用级中间件
 * 阶段拿不到路由信息。只处理 admin 入口的非安全方法。
 *
 * 不调用 Request::checkToken()，避免消费一次性 __token__ 导致控制器里的
 * Token 校验器二次校验失败。
 */
class AdminCsrf
{
    public static function enforce(Request $request, string $route)
    {
        return (new self())->check($request, $route);
    }

    public function handle(Request $request, Closure $next)
    {
        $route = strtolower(trim((string)$request->controller() . '/' . (string)$request->action(), '/'));
        $response = $this->check($request, $route);

        return $response === null ? $next($request) : $response;
    }

    protected function check(Request $request, string $route)
    {
        if (PHP_SAPI === 'cli' || !defined('ENTRANCE') || ENTRANCE !== 'admin') {
            return null;
        }

        $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app'])
            ? $GLOBALS['config']['app']
            : [];
        if (empty($app['security_csrf_admin']) || (string)$app['security_csrf_admin'] !== '1') {
            return null;
        }

        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $route = strtolower(trim($route, '/'));
        if ($this->isExempt($route, $app)) {
            return null;
        }

        $token = (string)$request->post('__token__', '');
        if ($token === '') {
            $token = (string)$request->param('__token__', '');
        }
        if ($token === '') {
            $token = (string)$request->header('X-CSRF-Token', '');
        }

        $stable = (string)Session::get('admin_csrf');
        $legacy = (string)Session::get('__token__');
        $valid = ($stable !== '' && $token !== '' && hash_equals($stable, $token))
            || ($legacy !== '' && $token !== '' && hash_equals($legacy, $token));

        if ($valid) {
            return null;
        }

        $status = isset($app['security_csrf_http_code']) ? (int)$app['security_csrf_http_code'] : 403;
        if ($status < 400 || $status > 599) {
            $status = 403;
        }

        $fresh = function_exists('mac_admin_csrf_token')
            ? (string)mac_admin_csrf_token()
            : (string)Session::get('admin_csrf');

        $accept = (string)$request->header('accept', '');
        $wantJson = $request->isAjax()
            || stripos($accept, 'application/json') !== false
            || stripos((string)$request->header('content-type', ''), 'application/json') !== false;

        if ($wantJson) {
            return json([
                'code' => 1001,
                'msg'  => lang('token_err'),
                'data' => ['__token__' => $fresh],
            ], $status);
        }

        return Response::create(lang('token_err'), 'html', $status);
    }

    protected function isExempt(string $route, array $app): bool
    {
        $hard = ['upload/ueditor', 'upload/ueditorai', 'upload/ueditor_ai', 'assistant/chat'];
        foreach ($hard as $rule) {
            if ($this->matchRule($route, $rule)) {
                return true;
            }
        }

        $exempt = isset($app['security_csrf_admin_exempt'])
            ? (string)$app['security_csrf_admin_exempt']
            : 'upload/*';
        foreach (explode(',', $exempt) as $rule) {
            $rule = strtolower(trim($rule));
            if ($rule !== '' && $this->matchRule($route, $rule)) {
                return true;
            }
        }

        return false;
    }

    protected function matchRule(string $route, string $rule): bool
    {
        if ($rule === '') {
            return false;
        }
        if (strpos($rule, '*') === false) {
            return $route === $rule;
        }

        $prefix = substr($rule, 0, -1);
        return strpos($route, $prefix) === 0;
    }
}
