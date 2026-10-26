<?php
namespace app\common\model;
use think\facade\Cache;
use think\facade\Db;

class Group extends Base {
    protected $name = 'group';

    protected $createTime = '';
    protected $updateTime = '';

    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function getGroupStatusTextAttr($val,$data)
    {
        $arr = [0=>lang('disable'),1=>lang('enable')];
        return $arr[$data['group_status']];
    }

    public function listData($where,$order)
    {
        $total = $this->where($where)->count();
        $tmp = Db::name('Group')->where($where)->order($order)->select();

        $list = [];
        foreach($tmp as $k=>$v){
            $v['group_popedom'] = json_decode($v['group_popedom'],true);
            $list[$v['group_id']] = $v;
        }

        return ['code'=>1,'msg'=>lang('data_list'),'total'=>$total,'list'=>$list];
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
        $info['group_popedom'] = json_decode($info['group_popedom'],true);
        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function saveData($data)
    {
        if(!empty($data['group_type'])){
            $data['group_type'] = ','.join(',',$data['group_type']) .',';
        }else{
            $data['group_type'] = '';
        }

        if(!empty($data['group_popedom'])){
            $data['group_popedom'] = json_encode($data['group_popedom']);
        }
        else{
            $data['group_popedom'] ='';
        }

        $validate = validate('app\\common\\validate\\Group', [], false, false);
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').'：'.$validate->getError() ];
        }
        if(!empty($data['group_id'])){
            $where=[];
            $where['group_id'] = ['eq',$data['group_id']];
            $res = $this->allowField([])->where($where)->update($data);
        }
        else{
            $res = $this->allowField([])->insert($data);
        }
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err').'：'.$this->getError() ];
        }
        $this->setCache();
        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    public function delData($where)
    {
        $cc = (new \app\common\model\User())->countData($where);
        if($cc>0){
            return ['code'=>1002,'msg'=>lang('del_err').'：'.lang('model/group/have_user') ];
        }
        $res = $this->where($where)->delete();
        if($res===false){
            return ['code'=>1001,'msg'=>lang('del_err').'：'.$this->getError() ];
        }
        $this->setCache();
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
        $this->setCache();
        return ['code'=>1,'msg'=>lang('set_ok')];
    }

    public function setCache()
    {
        $res = $this->listData([],'group_id asc');
        $list = $res['list'];

        $type_list = (new Type())->getCache('type_list');
        if (!empty($type_list)) {
            foreach ($list as &$group) {
                $group_types = array_values(array_filter(array_map('intval', explode(',', (string)($group['group_type'] ?? '')))));
                if (empty($group_types)) {
                    continue;
                }
                $popedom = isset($group['group_popedom']) && is_array($group['group_popedom']) ? $group['group_popedom'] : [];
                foreach ($type_list as $type_id => $type) {
                    $pid = intval($type['type_pid'] ?? 0);
                    if ($pid <= 0 || !in_array($pid, $group_types, true)) {
                        continue;
                    }
                    if (!in_array((int)$type_id, $group_types, true)) {
                        $group_types[] = (int)$type_id;
                    }
                    if (!isset($popedom[$type_id])) {
                        $popedom[$type_id] = $popedom[$pid] ?? [];
                    }
                }
                $group['group_type'] = ',' . implode(',', $group_types) . ',';
                $group['group_popedom'] = $popedom;
            }
            unset($group);
        }

        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'group_list';
        Cache::set($key,$list);

        $vip_key = ($GLOBALS['config']['app']['cache_flag'] ?? 'maccms') . '_vip_exclusive_type_ids';
        Cache::delete($vip_key);
    }

    public function getCache($flag='group_list')
    {
        $key = $GLOBALS['config']['app']['cache_flag']. '_'.$flag;
        $cache = Cache::get($key);
        if(empty($cache)){
            $this->setCache();
            $cache = Cache::get($key);
        }
        return $cache;
    }

    public function vipPlanPrice($group_info, $long)
    {
        $long = trim($long);
        if (empty($group_info) || !is_array($group_info) || !in_array($long, ['day', 'week', 'month', 'year'], true)) {
            return ['effective_points' => 0, 'is_activity' => 0];
        }
        $normal = intval($group_info['group_points_' . $long] ?? 0);
        $actPoints = intval($group_info['group_activity_points_' . $long] ?? 0);
        $start = intval($group_info['group_activity_start_time_' . $long] ?? 0);
        $end = intval($group_info['group_activity_end_time_' . $long] ?? 0);
        $now = time();
        if ($actPoints > 0 && $start > 0 && $end > 0 && $now >= $start && $now <= $end) {
            return ['effective_points' => $actPoints, 'is_activity' => 1];
        }
        return ['effective_points' => $normal, 'is_activity' => 0];
    }
}