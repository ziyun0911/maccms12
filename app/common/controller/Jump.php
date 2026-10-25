<?php
namespace app\common\controller;

use think\facade\Config;

/**
 * 苹果CMS v12 —— 跳转（success/error/redirect）trait
 *
 * ThinkPHP 8 已移除 think\Controller 及其内置 success/error，此处按 TP5 语义重建：
 *  - AJAX 请求返回 JSON；
 *  - 普通请求渲染 dispatch_success/error 模板（public/jump），返回渲染后的字符串。
 */
trait Jump
{
    /**
     * 渲染跳转结果
     * @param int    $code 1 成功 / 0 失败
     * @param string $msg  提示信息
     * @param string $url  跳转地址
     * @param mixed  $data 附加数据
     * @param int    $wait 停留秒数
     * @return mixed  AJAX 返回 Response(JSON)，否则返回渲染后的 HTML 字符串
     */
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

        // 绝对路径模板文件
        if (is_string($tpl) && $tpl !== '' && is_file($tpl)) {
            return $this->display(file_get_contents($tpl));
        }
        // 相对当前视图目录的模板（public/jump）
        return $this->fetch('public/jump');
    }

    /**
     * 操作成功跳转
     * @param string $msg 提示信息
     * @param string $url 跳转的URL地址
     * @param mixed  $data 返回的数据
     * @param int    $wait 跳转等待时间
     * @return mixed
     */
    protected function success($msg = '', $url = null, $data = '', $wait = 3)
    {
        return $this->jump(1, $msg, $url, $data, $wait);
    }

    /**
     * 操作错误跳转
     * @param string $msg 错误信息
     * @param string $url 跳转的URL地址
     * @param mixed  $data 返回的数据
     * @param int    $wait 跳转等待时间
     * @return mixed
     */
    protected function error($msg = '', $url = null, $data = '', $wait = 3)
    {
        return $this->jump(0, $msg, $url, $data, $wait);
    }

    /**
     * URL重定向
     * @param string $url   跳转的URL表达式
     * @param array  $params 其它URL参数
     * @param int    $code  http code
     * @return \think\Response
     */
    protected function redirect($url, $params = [], $code = 302)
    {
        // TP8 的全局 redirect($url,$code) 不再接收 params，这里按 TP5 语义用 url() 拼装后再跳转
        if (!(strpos($url, '://') || 0 === strpos($url, '/'))) {
            $url = url($url, $params);
        }
        return redirect((string) $url, $code);
    }

    /**
     * JSON 返回数据（后台 AJAX 常用）
     * @param mixed  $data 数据
     * @param int    $code 状态码
     * @param string $msg  提示信息
     * @return \think\Response
     */
    protected function ajaxReturn($data = [], $code = 1, $msg = '')
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => $data]);
    }

    /**
     * 简单 JSON 返回（前台 ajax 控制器使用）
     * @param array $data
     * @return \think\Response
     */
    protected function jsonReturn($data = [])
    {
        return json($data);
    }
}
