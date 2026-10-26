<?php
namespace app\common\controller;

use think\facade\Config;

trait Jump
{
    protected function jump($code, $msg = '', $url = null, $data = '', $wait = 3)
    {
        if (is_null($url)) {
            $url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'javascript:history.back(-1);';
        } elseif ($url !== '') {
            $url = (strpos($url, '://') || 0 === strpos($url, '/')) ? $url : url($url);
        }
        $data = is_array($data) ? $data : [];

        if (request()->isAjax()) {
            return json([
                'code' => $code,
                'msg'  => $msg,
                'data' => $data,
                'url'  => $url,
                'wait' => $wait,
            ]);
        }

        $key = $code ? 'app.dispatch_success_tmpl' : 'app.dispatch_error_tmpl';
        $tpl = Config::get($key, '');

        $this->assign('code', $code);
        $this->assign('msg', $msg);
        $this->assign('url', $url);
        $this->assign('data', $data);
        $this->assign('wait', $wait);

        if (is_string($tpl) && $tpl !== '' && is_file($tpl)) {
            return $this->display(file_get_contents($tpl));
        }
        return $this->fetch('public/jump');
    }

    protected function success($msg = '', $url = null, $data = '', $wait = 3)
    {
        return $this->jump(1, $msg, $url, $data, $wait);
    }

    protected function error($msg = '', $url = null, $data = '', $wait = 3)
    {
        return $this->jump(0, $msg, $url, $data, $wait);
    }

    protected function redirect($url, $params = [], $code = 302)
    {
        if (!(strpos($url, '://') || 0 === strpos($url, '/'))) {
            $url = url($url, $params);
        }
        return redirect((string) $url, $code);
    }

    protected function ajaxReturn($data = [], $code = 1, $msg = '')
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => $data]);
    }

    protected function jsonReturn($data = [])
    {
        return json($data);
    }
}
