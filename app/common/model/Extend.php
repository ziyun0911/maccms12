<?php
namespace app\common\model;
use think\facade\Db;
use think\facade\Cache;

class Extend extends Base {
    private function typeExtendValue($type_info, string $key)
    {
        if (!is_array($type_info)) {
            return null;
        }
        $raw = $type_info['type_extend'] ?? '';
        if (is_string($raw)) {
            $raw = (array)json_decode($raw, true);
        }
        if (!is_array($raw) || empty($raw[$key])) {
            return null;
        }
        return (string)$raw[$key];
    }

    private function tagParams($lp): array
    {
        if (is_string($lp)) {
            $lp = json_decode($lp, true);
        }
        if (!is_array($lp)) {
            $lp = [];
        }
        return [
            (string)($lp['order'] ?? ''),
            abs(intval($lp['start'] ?? 0)),
            abs(intval($lp['num'] ?? 0)),
            intval($lp['tid'] ?? 0),
        ];
    }

    public function dataCount()
    {
        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'data_count';
        $data = Cache::get($key);
        if(empty($data)){
            $totay = strtotime(date('Y-m-d'));
            $data = [
                'vod_all' => 0, 'vod_today' => 0,
                'art_all' => 0, 'art_today' => 0,
                'topic_all' => 0, 'topic_today' => 0,
                'actor_all' => 0, 'actor_today' => 0,
                'role_all' => 0, 'role_today' => 0,
                'website_all' => 0, 'website_today' => 0,
            ];
            $list = [];

            $where = [];
            $where['vod_status'] = ['eq',1];
            $tmp = (new \app\common\model\Vod())->field('type_id_1,type_id,count(vod_id) as cc')->where($where)->group('type_id_1,type_id')->select();
            foreach($tmp as $k=>$v){
                $data['vod_all'] += intval($v['cc']);
                $list['type_all_'.$v['type_id']] = $v->toArray();
            }

            $where['vod_time'] = ['egt',$totay];
            $tmp = (new \app\common\model\Vod())->field('type_id_1,type_id,count(vod_id) as cc')->where($where)->group('type_id_1,type_id')->select();
            foreach($tmp as $k=>$v){
                $data['vod_today'] += intval($v['cc']);
                $list['type_today_'.$v['type_id']] = $v->toArray();
            }
            $data['vod_min'] = (new \app\common\model\Vod())->min('vod_id');

            $where = [];
            $where['art_status'] = ['eq',1];
            $tmp = (new \app\common\model\Art())->field('type_id_1,type_id,count(art_id) as cc')->where($where)->group('type_id_1,type_id')->select();
            foreach($tmp as $k=>$v){
                $data['art_all'] += intval($v['cc']);
                $list['type_all_'.$v['type_id']] = $v->toArray();
            }
            $where['art_time'] = ['egt',$totay];
            $tmp = (new \app\common\model\Art())->field('type_id_1,type_id,count(art_id) as cc')->where($where)->group('type_id_1,type_id')->select();
            foreach($tmp as $k=>$v){
                $data['art_today'] += intval($v['cc']);
                $list['type_today_'.$v['type_id']] = $v->toArray();
            }
            $data['art_min'] = (new \app\common\model\Art())->min('art_id');

            foreach($list as $k=>$v) {
                $data[$k]=$v['cc'];

                if(strpos($k,'type_all')!==false){
                    $pk = 'type_all_' . $v['type_id_1'];
                    $data[$pk] = intval($data[$pk] ?? 0) + intval($v['cc']);
                }
                if(strpos($k,'type_today')!==false){
                    $pk = 'type_today_' . $v['type_id_1'];
                    $data[$pk] = intval($data[$pk] ?? 0) + intval($v['cc']);
                }
            }

            $where = [];
            $where['topic_status'] = ['eq',1];
            $tmp = (new \app\common\model\Topic())->where($where)->count();
            $data['topic_all'] = $tmp;
            $where['topic_time'] = ['egt',$totay];
            $tmp = (new \app\common\model\Topic())->where($where)->count();
            $data['topic_today'] = $tmp;
            $data['tpoic_min'] = (new \app\common\model\Topic())->min('topic_id');

            $where = [];
            $where['actor_status'] = ['eq',1];
            $tmp = (new \app\common\model\Actor())->where($where)->count();
            $data['actor_all'] = $tmp;
            $where['actor_time'] = ['egt',$totay];
            $tmp = (new \app\common\model\Actor())->where($where)->count();
            $data['actor_today'] = $tmp;
            $data['actor_min'] = (new \app\common\model\Actor())->min('actor_id');

            $where = [];
            $where['role_status'] = ['eq',1];
            $tmp = (new \app\common\model\Role())->where($where)->count();
            $data['role_all'] = $tmp;
            $where['role_time'] = ['egt',$totay];
            $tmp = (new \app\common\model\Role())->where($where)->count();
            $data['role_today'] = $tmp;
            $data['role_min'] = (new \app\common\model\Role())->min('role_id');

            $where = [];
            $where['website_status'] = ['eq',1];
            $tmp = (new \app\common\model\Website())->where($where)->count();
            $data['website_all'] = $tmp;
            $where['website_time'] = ['egt',$totay];
            $tmp = (new \app\common\model\Website())->where($where)->count();
            $data['website_today'] = $tmp;
            $data['website_min'] = (new \app\common\model\Website())->min('website_id');

            Cache::set($key,$data,(int)$GLOBALS['config']['app']['cache_time']);
        }
        return $data;
    }

    public function areaData($lp)
    {
        [$order, $start, $num, $tid] = $this->tagParams($lp);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_area'];
        if($tid>0){
            $type_list = (new \app\common\model\Type())->getCache('type_list');
            $extend = $this->typeExtendValue($type_list[$tid] ?? [], 'area');
            if ($extend !== null) {
                $data_str = $extend;
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$start+$num){
                $list[] = ['area_name' => $v];
            }
        }

        $list = array_filter($list);
        $total = count($list);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function langData($lp)
    {
        [$order, $start, $num, $tid] = $this->tagParams($lp);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_lang'];
        if($tid>0){
            $type_list = (new \app\common\model\Type())->getCache('type_list');
            $extend = $this->typeExtendValue($type_list[$tid] ?? [], 'lang');
            if ($extend !== null) {
                $data_str = $extend;
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$start+$num){
                $list[] = ['lang_name' => $v];
            }
        }
        $list = array_filter($list);
        $total = count($list);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function classData($lp)
    {
        [$order, $start, $num, $tid] = $this->tagParams($lp);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_class'];
        if($tid>0){
            $type_list = (new \app\common\model\Type())->getCache('type_list');
            $extend = $this->typeExtendValue($type_list[$tid] ?? [], 'class');
            if ($extend !== null) {
                $data_str = $extend;
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$start+$num){
                $list[] = ['class_name' => $v];
            }
        }
        $list = array_filter($list);
        $total = count($list);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function yearData($lp)
    {
        [$order, $start, $num, $tid] = $this->tagParams($lp);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_year'];
        if($tid>0){
            $type_list = (new \app\common\model\Type())->getCache('type_list');
            $extend = $this->typeExtendValue($type_list[$tid] ?? [], 'year');
            if ($extend !== null) {
                $data_str = $extend;
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$start+$num){
                $list[] = ['year_name' => $v];
            }
        }
        $list = array_filter($list);
        $total = count($list);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function versionData($lp)
    {
        [$order, $start, $num, $tid] = $this->tagParams($lp);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_version'];
        if($tid>0){
            $type_list = (new \app\common\model\Type())->getCache('type_list');
            $extend = $this->typeExtendValue($type_list[$tid] ?? [], 'version');
            if ($extend !== null) {
                $data_str = $extend;
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$start+$num){
                $list[] = ['version_name' => $v];
            }
        }

        $list = array_filter($list);
        $total = count($list);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function stateData($lp)
    {
        [$order, $start, $num, $tid] = $this->tagParams($lp);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_state'];
        if($tid>0){
            $type_list = (new \app\common\model\Type())->getCache('type_list');
            $extend = $this->typeExtendValue($type_list[$tid] ?? [], 'state');
            if ($extend !== null) {
                $data_str = $extend;
            }
        }

        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'asc';
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$start+$num){
                $list[] = ['state_name' => $v];
            }
        }
        $list = array_filter($list);
        $total = count($list);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function letterData($lp)
    {
        $data_str = 'A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z,0-9';
        $tmp = explode(',',$data_str);

        [$order, $start, $num, $tid] = $this->tagParams($lp);

        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'asc';
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$start+$num){
                $list[] = ['letter_name' => $v];
            }
        }

        $list = array_filter($list);
        $total = count($list);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }
}