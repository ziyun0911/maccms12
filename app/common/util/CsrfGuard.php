<?php
namespace app\common\util;

trait CsrfGuard
{
    protected function checkCsrf()
    {
        $token = input('post.__token__');
        if (empty($token)) {
            $token = request()->header('x-csrf-token');
        }
        $sess = session('csrf_token');
        if (!empty($token) && !empty($sess) && hash_equals((string)$sess, (string)$token)) {
            return null;
        }
        $xhr = request()->header('x-requested-with') === 'XMLHttpRequest';
        $origin = request()->header('origin');
        $host = preg_replace('/:\d+$/', '', (string)request()->host());
        $sameOrigin = empty($origin) || parse_url($origin, PHP_URL_HOST) === $host;
        if ($xhr && $sameOrigin) {
            return null;
        }
        return ['code' => 1001, 'msg' => lang('param_err')];
    }
}