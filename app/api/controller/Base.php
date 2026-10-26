<?php
namespace app\api\controller;

use think\Request;
use app\common\controller\All;
use app\common\util\ApiMeilisearchSuggest;

class Base extends All
{
    public function __construct()
    {
        parent::__construct();
        \app\common\model\ContentLang::setCurrent(mac_get_content_lang());
        $this->label_user();
        $config = $GLOBALS['config']['site'];
        $this->assign($config);

        if($config['site_status'] == 0){
        }
    }

    protected function jsonSuggestByKind(Request $request, $kind, $urlFlag = null)
    {
        if ($GLOBALS['config']['app']['search'] != '1') {
            return json(['code' => 999, 'msg' => lang('suggest_close')]);
        }
        $urlFlag = ($urlFlag !== null && $urlFlag !== '') ? (string)$urlFlag : (string)$kind;
        $param = $request->param();
        $param = mac_search_len_check(array_merge(['wd' => '', 'tag' => '', 'class' => '', 'letter' => '', 'name' => '', 'state' => '', 'level' => '', 'area' => '', 'lang' => '', 'version' => '', 'actor' => '', 'director' => '', 'starsign' => '', 'blood' => ''], $param));
        $wd = mac_filter_xss(trim((string)($param['wd'] ?? '')));
        if ($wd === '') {
            return json(['code' => 1001, 'msg' => '参数错误']);
        }
        $limit = max(1, min(20, intval($param['limit'] ?? 10)));
        $res = ApiMeilisearchSuggest::suggestListDataRes($kind, $wd, $limit);
        $res['url'] = mac_url_search(['wd' => urlencode($wd)], $urlFlag);

        return json($res);
    }
}
