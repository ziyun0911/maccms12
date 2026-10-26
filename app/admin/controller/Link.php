<?php
namespace app\admin\controller;

class Link extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input();
        $param['page'] = intval($param['page']) <1 ? 1 : $param['page'];
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : $param['limit'];
        $where=[];

        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['link_name'] = ['like','%'.$param['wd'].'%'];
        }

        $order='link_id desc';
        $res = (new \app\common\model\Link())->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);
        $this->assign('title',lang('admin/link/title'));
        return $this->fetch('admin@link/index');
    }

    public function info()
    {
        if (Request()->isPost()) {
            $param = input();
            $contentLang = $param['content_lang'] ?? [];
            unset($param['content_lang']);
            $res = (new \app\common\model\Link())->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            $linkId = (int)($res['link_id'] ?? 0);
            if ($linkId > 0 && is_array($contentLang)) {
                $defaultLang = mac_content_lang_default();
                foreach ($contentLang as $langCode => $fields) {
                    if ((string)$langCode === $defaultLang || !is_array($fields)) {
                        continue;
                    }
                    (new \app\common\model\ContentLang())->saveFields('link', $linkId, (string)$langCode, $fields, 'manual', 1);
                }
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['link_id'] = ['eq',$id];
        $res = (new \app\common\model\Link())->infoData($where);

        $content_lang_default = mac_content_lang_default();
        $content_lang_enabled = array_values(array_unique(array_merge([$content_lang_default], mac_content_lang_allow_list())));
        $content_lang_data = [];
        if (!empty($res['info']['link_id'])) {
            foreach ($content_lang_enabled as $langCode) {
                $content_lang_data[$langCode] = (new \app\common\model\ContentLang())->getFields('link', $res['info']['link_id'], $langCode);
            }
        }
        $this->assign('content_lang_default', $content_lang_default);
        $this->assign('content_lang_enabled', $content_lang_enabled);
        $this->assign('content_lang_multi', count($content_lang_enabled) > 1);
        $this->assign('content_lang_data', $content_lang_data);
        $this->assign('content_translate_available', mac_content_translate_available());

        $this->assign('info',$res['info']);
        $this->assign('title',lang('admin/link/title'));
        return $this->fetch('admin@link/info');
    }

    public function contentLangTranslate()
    {
        $text = (string)input('post.text', '');
        $from = (string)input('post.from', mac_content_lang_default());
        $to = (string)input('post.to', '');
        if ($text === '' || $to === '') {
            return $this->error(lang('param_err'));
        }
        if (!mac_content_translate_rate_limit($this->_admin['admin_id'])) {
            return $this->error(lang('frequently'));
        }
        $result = mac_content_translate($text, $from, $to, ['content_type' => 'link']);
        if ($result === '') {
            return $this->error(lang('admin/link/content_lang_translate_err'));
        }
        return json(['code' => 1, 'msg' => 'ok', 'data' => $result]);
    }

    public function del()
    {
        $param = input();
        $ids = $param['ids'];

        if(!empty($ids)){
            $where=[];
            $where['link_id'] = ['in',$ids];
            $res = (new \app\common\model\Link())->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function batch()
    {
        $param = input();
        $ids = $param['ids'];
        foreach ($ids as $k=>$id) {
            $data = [];
            $data['link_id'] = intval($id);
            $data['link_name'] = $param['link_name'][$k];
            $data['link_sort'] = $param['link_sort'][$k];
            $data['link_url'] = $param['link_url'][$k];
            $data['link_type'] = intval($param['link_type'][$k]);
            $data['link_logo'] = $param['link_logo'][$k];

            if (empty($data['link_name'])) {
                $data['link_name'] = lang('unknown');
            }
            $res = (new \app\common\model\Link())->saveData($data);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
        }
        return $this->success($res['msg']);
    }
}
