<?php
namespace app\common\model;
use think\facade\Db;
use think\facade\Cache;

class Extend extends Base {


    /**
     * 取分类自己的扩展串（地区/语言/分类/年份/版本/状态）。
     * Type::listData 已经把 type_extend json_decode 成数组再入缓存，所以这里拿到的通常是数组；
     * v10 又对数组 json_decode 一次，PHP7 只是 notice 并退回全局配置，导致分类级扩展一直没生效，
     * PHP8 下同样的写法会抛异常。两种形态都兼容，取不到就返回 null 让调用方沿用全局值。
     */
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

    /**
     * 标签编译时把属性数组 json_encode 成字符串传进来（见 taglib/Maccms.php 的 $__TAG__），
     * 和各模型 listCacheData 的入参口径一致，必须先还原成数组：
     * PHP7 对字符串用 'order' 取下标只报 notice 并退回首字符，PHP8 直接抛 TypeError。
     * 标签声明里只有 order/start/num，tid 属于直接调用方的扩展入参，缺失时一律按 v10 的默认值处理。
     */
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
            // 空站/新装的库计数全为 0，累加前必须先把键建好：PHP8 下对不存在的键用 += 会逐条报
            // Undefined array key，后台首页每个统计卡片都要跑一遍这里。
            $data = [
                'vod_all' => 0, 'vod_today' => 0,
                'art_all' => 0, 'art_today' => 0,
                'topic_all' => 0, 'topic_today' => 0,
                'actor_all' => 0, 'actor_today' => 0,
                'role_all' => 0, 'role_today' => 0,
                'website_all' => 0, 'website_today' => 0,
            ];
            $list = [];

            //视频
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

            //文章
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

            //分类
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

            //专题
            $where = [];
            $where['topic_status'] = ['eq',1];
            $tmp = (new \app\common\model\Topic())->where($where)->count();
            $data['topic_all'] = $tmp;
            $where['topic_time'] = ['egt',$totay];
            $tmp = (new \app\common\model\Topic())->where($where)->count();
            $data['topic_today'] = $tmp;
            $data['tpoic_min'] = (new \app\common\model\Topic())->min('topic_id');


            //演员库
            $where = [];
            $where['actor_status'] = ['eq',1];
            $tmp = (new \app\common\model\Actor())->where($where)->count();
            $data['actor_all'] = $tmp;
            $where['actor_time'] = ['egt',$totay];
            $tmp = (new \app\common\model\Actor())->where($where)->count();
            $data['actor_today'] = $tmp;
            $data['actor_min'] = (new \app\common\model\Actor())->min('actor_id');

            //角色库
            $where = [];
            $where['role_status'] = ['eq',1];
            $tmp = (new \app\common\model\Role())->where($where)->count();
            $data['role_all'] = $tmp;
            $where['role_time'] = ['egt',$totay];
            $tmp = (new \app\common\model\Role())->where($where)->count();
            $data['role_today'] = $tmp;
            $data['role_min'] = (new \app\common\model\Role())->min('role_id');

            //网址库
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
            // 'tree_list' 这个缓存键从来没被写过（Type::setCache 只产 type_list/type_tree，v10 亦然）：
            // PHP7 下取空只是 notice、退回全局配置，PHP8 会抛异常，而且每次都白跑一遍全量缓存重建。
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
            // 'tree_list' 这个缓存键从来没被写过（Type::setCache 只产 type_list/type_tree，v10 亦然）：
            // PHP7 下取空只是 notice、退回全局配置，PHP8 会抛异常，而且每次都白跑一遍全量缓存重建。
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
            // 'tree_list' 这个缓存键从来没被写过（Type::setCache 只产 type_list/type_tree，v10 亦然）：
            // PHP7 下取空只是 notice、退回全局配置，PHP8 会抛异常，而且每次都白跑一遍全量缓存重建。
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
            // 'tree_list' 这个缓存键从来没被写过（Type::setCache 只产 type_list/type_tree，v10 亦然）：
            // PHP7 下取空只是 notice、退回全局配置，PHP8 会抛异常，而且每次都白跑一遍全量缓存重建。
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
            // 'tree_list' 这个缓存键从来没被写过（Type::setCache 只产 type_list/type_tree，v10 亦然）：
            // PHP7 下取空只是 notice、退回全局配置，PHP8 会抛异常，而且每次都白跑一遍全量缓存重建。
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
            // 'tree_list' 这个缓存键从来没被写过（Type::setCache 只产 type_list/type_tree，v10 亦然）：
            // PHP7 下取空只是 notice、退回全局配置，PHP8 会抛异常，而且每次都白跑一遍全量缓存重建。
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