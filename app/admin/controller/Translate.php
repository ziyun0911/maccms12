<?php
namespace app\admin\controller;

use app\common\util\Translate as TranslateService;

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

        if (!in_array($config['type'], ['', 'baidu', 'tencent', 'deepl', 'openai'], true)) {
            $config['type'] = '';
        }

        $res = mac_arr2file(APP_PATH . 'extra/translate.php', $config);
        if ($res === false) {
            return $this->ajaxErrorWithFreshToken(lang('save_err'));
        }
        return json(['code' => 1, 'msg' => lang('save_ok')]);
    }

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

    private function ajaxErrorWithFreshToken($msg)
    {
        $t = token('__token__');
        return $this->error($msg, null, ['__token__' => $t]);
    }
}
