<?php
namespace app\admin\controller;
use think\facade\Db;
use app\common\util\Pinyin;

class Art extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function data()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : $param['page'];
        $param['limit'] = intval($param['limit']) < 1 ? $this->_pagesize : $param['limit'];

        $where = [];
        if (!empty($param['type'])) {
            $where['type_id|type_id_1'] = ['eq', $param['type']];
        }
        if (!empty($param['level'])) {
            $where['art_level'] = ['eq', $param['level']];
        }
        if (in_array($param['status'], ['0', '1'])) {
            $where['art_status'] = ['eq', $param['status']];
        }
        if (!empty($param['lock'])) {
            $where['art_lock'] = ['eq', $param['lock']];
        }
        if(!empty($param['pic'])){
            if($param['pic'] == '1'){
                $where['art_pic'] = ['eq',''];
            }
            elseif($param['pic'] == '2'){
                $where['art_pic'] = ['like','http%'];
            }
            elseif($param['pic'] == '3'){
                $where['art_pic'] = ['like','%#err%'];
            }
        }
        if(!empty($param['wd'])){
            $param['wd'] = urldecode($param['wd']);
            $param['wd'] = mac_filter_xss($param['wd']);
            $like = mac_search_wd_like($param['wd']);
            if ($like) {
                $where['art_name'] = $like;
            }
        }
        if (!empty($param['recycle'])) {
            $where['art_recycle_time'] = ['>', 0];
        }

        if(!empty($param['repeat'])){
            if($param['page'] ==1){
                Db::execute('DROP TABLE IF EXISTS '.config('database.connections.mysql.prefix').'tmpart');
                Db::execute('CREATE TABLE `'.config('database.connections.mysql.prefix').'tmpart` (`id1` int unsigned DEFAULT NULL, `name1` varchar(1024) NOT NULL DEFAULT \'\') ENGINE=MyISAM');
                Db::execute('INSERT INTO `'.config('database.connections.mysql.prefix').'tmpart` (SELECT min(art_id)as id1,art_name as name1 FROM '.config('database.connections.mysql.prefix').'art WHERE art_recycle_time = 0 GROUP BY name1 HAVING COUNT(name1)>1)');
            }
            $order='art_name asc';
            $res = (new \app\common\model\Art())->listRepeatData($where,$order,$param['page'],$param['limit']);
        }
        else{
            $order='art_time desc';
            $res = (new \app\common\model\Art())->listData($where,$order,$param['page'],$param['limit']);
        }

        $artIds = array_column($res['list'] instanceof \think\Collection ? $res['list']->toArray() : $res['list'], 'art_id');
        $artSeoStatusMap = [];
        if (!empty($artIds)) {
            $seoRows = Db::name('seo_ai_result')
                ->field('seo_obj_id,seo_status')
                ->where('seo_mid', 2)
                ->where('seo_obj_id', 'in', $artIds)
                ->where('seo_status', 'in', [1, 2])
                ->select();
            foreach ((array)$seoRows as $seoRow) {
                $artSeoStatusMap[intval($seoRow['seo_obj_id'])] = intval($seoRow['seo_status']);
            }
        }

        foreach($res['list'] as $k=>&$v){
            $v['ismake'] = 1;
            if($GLOBALS['config']['view']['art_detail'] >0 && $v['art_time_make'] < $v['art_time']){
                $v['ismake'] = 0;
            }
            $v['seo_ai_status'] = isset($artSeoStatusMap[$v['art_id']]) ? intval($artSeoStatusMap[$v['art_id']]) : 0;
        }

        $this->assign('list', $res['list']);
        $this->assign('total', $res['total']);
        $this->assign('page', $res['page']);
        $this->assign('limit', $res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param', $param);

        $type_tree = (new \app\common\model\Type())->getCache('type_tree');
        $this->assign('type_tree', $type_tree);

        $this->assign('title', lang('admin/art/title'));
        return $this->fetch('admin@art/index');
    }

    public function batch()
    {
        $param = input();
        if (!empty($param)) {
            mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');

            if(empty($param['ck_del']) && empty($param['ck_level']) && empty($param['ck_status']) && empty($param['ck_lock']) && empty($param['ck_hits']) && empty($param['ck_type']) && empty($param['ck_replace']) ){
                return $this->error(lang('param_err'));
            }
            $where = $this->artBatchFilterWhere($param);
            if($param['ck_del'] == 1){
                $res = (new \app\common\model\Art())->recycleData($where);
                mac_echo($res['code'] == 1 ? lang('recycle_ok') : $res['msg']);
                mac_jump( url('art/batch') ,3);
                exit;
            }
            if($param['ck_del'] == 4){
                $res = (new \app\common\model\Art())->delData($where);
                mac_echo(lang('multi_del_ok'));
                mac_jump( url('art/batch') ,3);
                exit;
            }

            $batch_type_id = 0;
            $batch_type_pid = 0;
            if(!empty($param['ck_type']) && !empty($param['val_type'])){
                $batch_type_id = intval($param['val_type']);
                $type_list = (new \app\common\model\Type())->getCache();
                if($batch_type_id < 1 || !isset($type_list[$batch_type_id]) || intval($type_list[$batch_type_id]['type_mid']) != 2){
                    return $this->error(lang('select_type_must'));
                }
                $batch_type_pid = intval($type_list[$batch_type_id]['type_pid']);
            }

            if(empty($param['page'])){
                $param['page'] = 1;
            }
            if(empty($param['limit'])){
                $param['limit'] = 100;
            }
            if(empty($param['total'])) {
                $param['total'] = (new \app\common\model\Art())->countData($where);
                $param['page_count'] = ceil($param['total'] / $param['limit']);
            }

            if($param['page'] > $param['page_count']) {
                mac_echo(lang('multi_set_ok'));
                mac_jump( url('art/batch') ,3);
                exit;
            }
            mac_echo( "<font color=red>".lang('admin/batch_tip',[$param['total'],$param['limit'],$param['page_count'],$param['page']])."</font>");

            $page = $param['page_count'] - $param['page'] + 1;
            $order='art_id desc';
            $res = (new \app\common\model\Art())->listData($where,$order,$page,$param['limit']);

            foreach($res['list'] as  $k=>$v){
                $where2 = [];
                $where2['art_id'] = $v['art_id'];

                $update = [];
                $des = $v['art_id'].','.$v['art_name'];

                if(!empty($param['ck_level']) && !empty($param['val_level'])){
                    $update['art_level'] = $param['val_level'];
                    $des .= '&nbsp;'.lang('level').'：'.$param['val_level'].'；';
                }
                if(!empty($param['ck_status']) && isset($param['val_status'])){
                    $update['art_status'] = $param['val_status'];
                    $des .= '&nbsp;'.lang('status').'：'.($param['val_status'] ==1 ? '['.lang('reviewed').']':'['.lang('reviewed_not').']') .'；';
                }
                if(!empty($param['ck_lock']) && isset($param['val_lock'])){
                    $update['art_lock'] = $param['val_lock'];
                    $des .= '&nbsp;'.lang('lock').'：'.($param['val_lock']==1 ? '['.lang('lock').']':'['.lang('unlock').']').'；';
                }
                if(!empty($param['ck_hits']) && !empty($param['val_hits_min']) && !empty($param['val_hits_max']) ){
                    $update['art_hits'] = rand($param['val_hits_min'],$param['val_hits_max']);
                    $des .= '&nbsp;'.lang('hits').'：'.$update['art_hits'].'；';
                }

                if($batch_type_id > 0){
                    $update['type_id'] = $batch_type_id;
                    $update['type_id_1'] = $batch_type_pid;
                    $des .= '&nbsp;'.lang('type').'：'.$batch_type_id.'；';
                }

                if(!empty($param['ck_replace']) && !empty($param['replace_field']) && isset($param['replace_search'])){
                    $field = $param['replace_field'];
                    $replaceres = $this->batch_replace($field,$v,$param['replace_search'],$param['replace_with'],'art');
                    if(isset($replaceres[$field])) $update[$field] = $replaceres[$field];

                    if(!empty($replaceres['des'])) $des .= $replaceres['des'];
                }

                mac_echo($des);
                $res2 = (new \app\common\model\Art())->where($where2)->update($update);
            }
            $param['page']++;
            $url = url('art/batch') .'?'. http_build_query($param);
            mac_jump( $url ,3);
            exit;
        }

        $type_tree = (new \app\common\model\Type())->getCache('type_tree');
        $this->assign('type_tree',$type_tree);

        $this->assign('title',lang('admin/art/title'));
        return $this->fetch('admin@art/batch');
    }

    private function artBatchFilterWhere(&$param)
    {
        $where = [];
        if (!empty($param['type'])) {
            $where['type_id'] = ['eq', $param['type']];
        }
        if (!empty($param['level'])) {
            $where['art_level'] = ['eq', $param['level']];
        }
        if (in_array($param['status'] ?? '', ['0', '1'])) {
            $where['art_status'] = ['eq', $param['status']];
        }
        if (!empty($param['lock'])) {
            $where['art_lock'] = ['eq', $param['lock']];
        }
        if (!empty($param['pic'])) {
            if ($param['pic'] == '1') {
                $where['art_pic'] = ['eq', ''];
            } elseif ($param['pic'] == '2') {
                $where['art_pic'] = ['like', 'http%'];
            } elseif ($param['pic'] == '3') {
                $where['art_pic'] = ['like', '%#err%'];
            }
        }
        if (!empty($param['wd'])) {
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $like = mac_search_wd_like($param['wd']);
            if ($like) {
                $where['art_name'] = $like;
            }
        }
        if (!empty($param['recycle'])) {
            $where['art_recycle_time'] = ['>', 0];
        }
        return $where;
    }

    public function exportData()
    {
        $param = input();
        $where = $this->artBatchFilterWhere($param);
        return $this->base_export($param,'art',$where);
    }

    public function importData()
    {
        return $this->base_import('art');
    }

    public function info()
    {
        if (Request()->isPost()) {
            $param = input('post.');
            $contentLang = $param['content_lang'] ?? [];
            unset($param['content_lang']);
            $res = (new \app\common\model\Art())->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            $artId = (int)($res['art_id'] ?? 0);
            if ($artId > 0 && is_array($contentLang)) {
                $defaultLang = mac_content_lang_default();
                foreach ($contentLang as $langCode => $fields) {
                    if ((string)$langCode === $defaultLang || !is_array($fields)) {
                        continue;
                    }
                    (new \app\common\model\ContentLang())->saveFields('art', $artId, (string)$langCode, $fields, 'manual', 1);
                }
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['art_id'] = ['eq',$id];
        $where['_recycle'] = 'all';
        $res = (new \app\common\model\Art())->infoData($where);

        $info = $res['info'];
        $this->assign('info',$info);
        $this->assign('art_page_list',(array)$info['art_page_list']);
        $seoAiStatus = 0;
        if (!empty($info['art_id'])) {
            $seoAi = (new \app\common\model\SeoAiResult())->getByObject(2, intval($info['art_id']));
            if (!empty($seoAi)) {
                $seoAiStatus = intval($seoAi['seo_status']);
            }
        }
        $this->assign('seo_ai_status', $seoAiStatus);

        $type_tree = (new \app\common\model\Type())->getCache('type_tree');
        $this->assign('type_tree',$type_tree);

        $content_lang_default = mac_content_lang_default();
        $content_lang_enabled = array_values(array_unique(array_merge([$content_lang_default], mac_content_lang_allow_list())));
        $content_lang_data = [];
        if (!empty($info['art_id'])) {
            foreach ($content_lang_enabled as $langCode) {
                $content_lang_data[$langCode] = (new \app\common\model\ContentLang())->getFields('art', $info['art_id'], $langCode);
            }
        }
        $this->assign('content_lang_default', $content_lang_default);
        $this->assign('content_lang_enabled', $content_lang_enabled);
        $this->assign('content_lang_multi', count($content_lang_enabled) > 1);
        $this->assign('content_lang_data', $content_lang_data);
        $this->assign('content_translate_available', mac_content_translate_available());

        $this->assign('title',lang('admin/art/title'));
        return $this->fetch('admin@art/info');
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
        $result = mac_content_translate($text, $from, $to, ['content_type' => 'art']);
        if ($result === '') {
            return $this->error(lang('admin/art/content_lang_translate_err'));
        }
        return json(['code' => 1, 'msg' => 'ok', 'data' => $result]);
    }

    public function aiSeoGenerate()
    {
        if (!Request()->isPost()) {
            return json(['code' => 0, 'msg' => lang('illegal_request'), 'data' => []]);
        }
        $id = intval(input('post.id'));
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => lang('param_err'), 'data' => []]);
        }
        try {
            $res = \app\common\util\SeoAi::generateByMidObj(2, $id);
        } catch (\Exception $e) {
            \think\facade\Log::error('AI SEO generate failed (art_id=' . $id . '): ' . $e->getMessage());
            return json(['code' => 0, 'msg' => $e->getMessage(), 'data' => []]);
        }
        if (empty($res['code']) || intval($res['code']) !== 1) {
            return json(['code' => 0, 'msg' => isset($res['msg']) ? $res['msg'] : lang('save_err'), 'data' => []]);
        }
        return json(['code' => 1, 'msg' => lang('save_ok'), 'data' => isset($res['data']) ? $res['data'] : []]);
    }

    public function restore()
    {
        $param = input();
        $ids = $param['ids'];
        if (empty($ids)) {
            return $this->error(lang('param_err'));
        }
        $where = ['art_id' => ['in', $ids]];
        $res = (new \app\common\model\Art())->restoreData($where);
        if ($res['code'] > 1) {
            return $this->error($res['msg']);
        }
        return $this->success($res['msg']);
    }

    public function del()
    {
        $param = input();
        $ids = $param['ids'];
        $purge = !empty($param['purge']);

        if(!empty($ids)){
            $where=[];
            $where['art_id'] = ['in',$ids];
            if ($purge) {
                $res = (new \app\common\model\Art())->delData($where);
            } else {
                $res = (new \app\common\model\Art())->recycleData($where);
            }
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        elseif(!empty($param['repeat'])){
            $st = ' not in ';
            if($param['retain']=='max'){
                $st=' in ';
            }
            $sql = 'delete from '.config('database.connections.mysql.prefix').'art where art_name in(select name1 from '.config('database.connections.mysql.prefix').'tmpart) and art_id '.$st.'(select id1 from '.config('database.connections.mysql.prefix').'tmpart)';
            $res = (new \app\common\model\Art())->execute($sql);
            if($res===false){
                return $this->success(lang('del_err'));
            }
            return $this->success(lang('del_ok'));
        }
        return $this->error(lang('param_err'));
    }

    public function field()
    {
        $param = input();
        $ids = $param['ids'];
        $col = $param['col'];
        $val = $param['val'];
        $start = $param['start'];
        $end = $param['end'];
        if ($col == 'type_id' && $val==''){
            return $this->error(lang('select_type_must'));
        }
        $type_pid = 0;
        if($col == 'type_id'){
            $type_list = (new \app\common\model\Type())->getCache();
            $type_id = intval($val);
            if($type_id < 1 || !isset($type_list[$type_id]) || intval($type_list[$type_id]['type_mid']) != 2){
                return $this->error(lang('select_type_must'));
            }
            $type_pid = intval($type_list[$type_id]['type_pid']);
        }

        if(!empty($ids) && in_array($col,['art_status','art_lock','art_level','art_hits','type_id'])){
            $where=[];
            $where['art_id'] = ['in',$ids];
            $update = [];
            if(empty($start)) {
                $update[$col] = $val;
                if($col == 'type_id'){
                    $update['type_id_1'] = $type_pid;
                }
                $res = (new \app\common\model\Art())->fieldData($where, $update);
            }
            else{
                if(empty($end)){$end = 9999;}
                $ids = explode(',',$ids);
                foreach($ids as $k=>$v){
                    $val = rand($start,$end);
                    $where['art_id'] = ['eq',$v];
                    $update[$col] = $val;
                    $res = (new \app\common\model\Art())->fieldData($where, $update);
                }
            }
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function updateToday()
    {
        $param = input();
        $flag = $param['flag'];
        $res = (new \app\common\model\Art())->updateToday($flag);
        return json($res);
    }
}
