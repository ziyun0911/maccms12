<?php
namespace app\common\model;
use think\facade\Db;

class Comment extends Base {
    protected $name = 'comment';

    protected $createTime = '';
    protected $updateTime = '';

    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function getCommentStatusTextAttr($val,$data)
    {
        $arr = [0=>lang('disable'),1=>lang('enable')];
        return $arr[$data['comment_status']];
    }

    public function countData($where)
    {
        $total = $this->where($where)->count();
        return $total;
    }

    public function listData($where,$order,$page=1,$limit=20,$start=0,$field='*',$addition=1,$totalshow=1)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;
        if(!is_array($where)){
            $where = json_decode($where,true);
        }
        $limit_str = ($limit * ($page-1) + $start) .",".$limit;
        if($totalshow==1) {
            $total = $this->where($where)->count();
        }
        $list = Db::name('Comment')->field($field)->where($where)->order($order)->limit($limit_str)->select()->toArray();

        $subjectTableMap = [
            1 => ['Vod', 'vod_id', 'vod_recycle_time'],
            2 => ['Art', 'art_id', 'art_recycle_time'],
            3 => ['Topic', 'topic_id', ''],
            8 => ['Actor', 'actor_id', ''],
            9 => ['Role', 'role_id', ''],
            11 => ['Website', 'website_id', ''],
            12 => ['Manga', 'manga_id', 'manga_recycle_time'],
        ];

        $parentIds = [];
        $subjectIds = [];
        foreach ($list as $v) {
            $parentIds[] = (int)$v['comment_id'];
            $mid = (int)$v['comment_mid'];
            $rid = (int)$v['comment_rid'];
            if (isset($subjectTableMap[$mid]) && $rid > 0) {
                $subjectIds[$mid][$rid] = true;
            }
        }

        $subMap = [];
        if (!empty($parentIds)) {
            $subs = Db::name('Comment')
                ->where('comment_pid', 'in', $parentIds)
                ->where('comment_status', 1)
                ->order($order)
                ->select();
            foreach ($subs as $s) {
                $subMap[(int)$s['comment_pid']][] = $s;
            }
        }

        $dataMap = [];
        foreach ($subjectIds as $mid => $rids) {
            [$tbl, $pk, $recycle] = $subjectTableMap[$mid];
            $rows = Db::name($tbl)->whereIn($pk, array_keys($rids))->select();
            foreach ($rows as $r) {
                $row = is_array($r) ? $r : $r->toArray();
                if ($recycle !== '' && (int)($row[$recycle] ?? 0) > 0) {
                    continue;
                }
                $dataMap[$mid . '-' . (int)$row[$pk]] = $row;
            }
        }

        $portraitMap = [];
        foreach ($list as $k => $v) {
            $uid = (int)$v['user_id'];
            if (!isset($portraitMap[$uid])) {
                $portraitMap[$uid] = mac_get_user_portrait($uid);
            }
            $list[$k]['user_portrait'] = $portraitMap[$uid];
            $list[$k]['comment_content'] = mac_restore_htmlfilter($list[$k]['comment_content']);

            $sub = $subMap[(int)$v['comment_id']] ?? [];
            $list[$k]['sub'] = $sub;
            foreach ($sub as $k2 => $v2) {
                $su = (int)$v2['user_id'];
                if (!isset($portraitMap[$su])) {
                    $portraitMap[$su] = mac_get_user_portrait($su);
                }
                $list[$k]['sub'][$k2]['user_portrait'] = $portraitMap[$su];
                $list[$k]['sub'][$k2]['comment_content'] = mac_restore_htmlfilter($v2['comment_content']);
            }

            $list[$k]['data'] = $dataMap[(int)$v['comment_mid'] . '-' . (int)$v['comment_rid']] ?? false;
        }

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$page,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
    }

    public function listCacheData($lp)
    {
        if (!is_array($lp)) {
            $lp = json_decode($lp, true);
        }

        $order = $lp['order'];
        $by = $lp['by'];
        $paging = $lp['paging'];
        $start = abs(intval($lp['start']));
        $num = abs(intval($lp['num']));
        $rid = abs(intval($lp['rid']));
        $pid = abs(intval($lp['pid']));
        $mid = abs(intval($lp['mid']));
        $uid = abs(intval($lp['uid']));
        $half = abs(intval($lp['half']));
        $pageurl = $lp['pageurl'];
        $page = 1;
        $where = [];

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }
        if (!in_array($mid, ['1','2','3','8','9'])) {
        }

        if(!in_array($paging, ['yes', 'no'])) {
            $paging = 'no';
        }

        if($paging=='yes') {
            $param = mac_param_url();
            if(!empty($param['mid'])){
                $mid = $param['mid'];
            }
            if(!empty($param['rid'])){
                $rid = $param['rid'];
            }
            if(!empty($param['pid'])){
                $pid = $param['pid'];
            }
            if(!empty($param['by'])){
                $by = $param['by'];
            }
            if(!empty($param['order'])){
                $order = $param['order'];
            }
            if(!empty($param['page'])){
                $page = intval($param['page']);
            }

            foreach($param as $k=>$v){
                if(empty($v)){
                    unset($param[$k]);
                }
            }
            if(empty($pageurl)){
                $pageurl = 'comment/index';
            }
            $param['page'] = 'PAGELINK';
            $pageurl = mac_url($pageurl,$param);
        }

        $where['comment_status'] = ['eq',1];
        $where['comment_pid'] = ['eq',0];

        if(!empty($rid)){
            $where['comment_rid'] = ['eq',$rid];
        }
        if(!empty($pid)){
            $where['comment_pid'] = ['eq',$pid];
        }
        if(!empty($uid)){
            $where['user_id'] = ['eq',$uid];
        }
        if(!empty($mid)){
            $where['comment_mid'] = ['eq',$mid];
        }

        if(!in_array($by, ['id', 'time','up','down'])) {
            $by = 'time';
        }
        if(!in_array($order, ['asc', 'desc'])) {
            $order = 'desc';
        }
        $order= 'comment_'.$by .' ' . $order;

        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' .md5('comment_listcache_'.join('&',$where).'_'.$order.'_'.$page.'_'.$num.'_'.$start);

        $res = $this->listData($where,$order,$page,$num,$start);
        $res['pageurl'] = $pageurl;
        $res['half'] = $half;
        return $res;
    }

    public function infoData($where,$field='*')
    {
        if(empty($where) || !is_array($where)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $info = $this->field($field)->where($where)->find();

        if(empty($info)){
            return ['code'=>1002,'msg'=>lang('obtain_err')];
        }
        $info = $info->toArray();

        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function saveData($data)
    {
        $validate = validate('app\\common\\validate\\Comment', [], false, false);
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').'：'.$validate->getError() ];
        }

        $filter_fields = [
            'comment_name',
            'comment_content',
        ];
        foreach ($filter_fields as $filter_field) {
            if (!isset($data[$filter_field])) {
                continue;
            }
            $data[$filter_field] = mac_filter_xss($data[$filter_field]);
        }

        if(!empty($data['comment_id'])){
            $where=[];
            $where['comment_id'] = ['eq',$data['comment_id']];
            $res = $this->allowField([])->where($where)->update($data);
        }
        else{
            $data['comment_time'] = time();
            $res = $this->allowField([])->insert($data);
        }
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err').'：'.$this->getError() ];
        }

        if (empty($data['comment_id'])) {
            try {
                $is_reply = intval($data['comment_pid']) > 0;
                $dyn_type = $is_reply ? 'reply' : 'comment';
                $dyn_pid = $is_reply ? intval($data['comment_pid']) : 0;
                (new \app\common\model\Dynamics())->saveData([
                    'user_id'       => intval($data['user_id']),
                    'dynamics_type' => $dyn_type,
                    'dyn_mid'       => intval($data['comment_mid']),
                    'dyn_rid'       => intval($data['comment_rid']),
                    'dyn_pid'       => $dyn_pid,
                    'dyn_text'      => mb_substr($data['comment_content'], 0, 200, 'UTF-8'),
                ]);
            } catch (\Exception $e) {
                \think\facade\Log::error('Comment dynamics mirror failed user=' . intval($data['user_id'])
                    . ' mid=' . intval($data['comment_mid']) . ' rid=' . intval($data['comment_rid'])
                    . ' err=' . $e->getMessage());
            }
        }

        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    public function delData($where)
    {
        $res = $this->where($where)->delete();
        if($res===false){
            return ['code'=>1001,'msg'=>lang('del_err').'：'.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('del_ok')];
    }

    public function fieldData($where,$col,$val)
    {
        if(!isset($col) || !isset($val)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $data = [];
        $data[$col] = $val;
        $res = $this->allowField([])->where($where)->update($data);
        if($res===false){
            return ['code'=>1001,'msg'=>lang('set_err').'：'.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('set_ok')];
    }
}