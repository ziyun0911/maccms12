<?php
namespace app\admin\controller;

use app\common\util\Translate as TranslateService;

/**
 * 翻译设置（maccms12 / TP8）
 *
 * 负责后台「翻译设置」页：读取/保存 app\extra\translate.php 中的翻译 API 配置，
 * 并提供「测试翻译」动作验证凭据与连通性。保存/测试均带权限校验与令牌刷新。
 */
class Translate extends Base
{
    public function index()
    {
        $config = config('translate');
        if (!is_array($config)) {
            $config = [];
        }
        $this->assign('config', $config);
        $this->assign('available', TranslateService::available() ? 1 : 0);
        $this->assign('title', lang('admin/translate/title'));
        return $this->fetch('admin@translate/config');
    }

    /**
     * 保存翻译 API 配置（写入 app\extra\translate.php）。
     */
    public function save()
    {
        $param = input('post.');
        unset($param['__token__']);

        $config = [
            'type'       => isset($param['type']) ? trim((string)$param['type']) : '',
            'appid'      => isset($param['appid']) ? trim((string)$param['appid']) : '',
            'secret'     => isset($param['secret']) ? trim((string)$param['secret']) : '',
            'secret_id'  => isset($param['secret_id']) ? trim((string)$param['secret_id']) : '',
            'secret_key' => isset($param['secret_key']) ? trim((string)$param['secret_key']) : '',
            'region'     => isset($param['region']) ? trim((string)$param['region']) : 'ap-guangzhou',
            'auth_key'   => isset($param['auth_key']) ? trim((string)$param['auth_key']) : '',
            'api_key'    => isset($param['api_key']) ? trim((string)$param['api_key']) : '',
            'model'      => isset($param['model']) ? trim((string)$param['model']) : 'gpt-3.5-turbo',
            'base_url'   => isset($param['base_url']) ? rtrim(trim((string)$param['base_url']), '/') : 'https://api.openai.com',
            'enable_map' => [],
        ];

        // 允许的供应商白名单，防脏数据
        if (!in_array($config['type'], ['', 'baidu', 'tencent', 'deepl', 'openai'], true)) {
            $config['type'] = '';
        }

        $res = mac_arr2file(APP_PATH . 'extra/translate.php', $config);
        if ($res === false) {
            return $this->ajaxErrorWithFreshToken(lang('save_err'));
        }
        return json(['code' => 1, 'msg' => lang('save_ok')]);
    }

    /**
     * 测试翻译：用当前已保存配置把一句示例文本翻成 en-us。
     */
    public function test()
    {
        if (!TranslateService::available()) {
            return json(['code' => 0, 'msg' => lang('admin/translate/not_configured')]);
        }
        $from = (string)input('post.from', 'zh-cn');
        $to   = (string)input('post.to', 'en-us');
        $text = (string)input('post.text', '苹果CMS 是一套优秀的视频点播系统。');
        $result = TranslateService::translate($text, $from, $to);
        if ($result === null || $result === '') {
            return json(['code' => 0, 'msg' => lang('admin/translate/test_fail')]);
        }
        return json(['code' => 1, 'msg' => lang('admin/translate/test_ok'), 'result' => $result]);
    }

    /**
     * AJAX form error with a new __token__ (ThinkPHP deletes the session token when validation runs).
     */
    private function ajaxErrorWithFreshToken($msg)
    {
        $t = token('__token__');
        return $this->error($msg, null, ['__token__' => $t]);
    }
}
