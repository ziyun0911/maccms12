<?php
namespace app\common\model;
use think\facade\Db;

class VodPlayFail extends Base {
    protected $name = 'vod_play_fail';

    protected $createTime = '';
    protected $updateTime = '';

    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function listData($where,$order,$page=1,$limit=20,$start=0)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;
        if(!is_array($where)){
            $where = json_decode($where,true);
        }
        if(empty($order)){
            $order = 'last_fail_time desc';
        }
        $limit_str = ($limit * ($page-1) + $start) .",".$limit;
        $total = $this->where($where)->count();
        $list = Db::name('VodPlayFail')->where($where)->order($order)->limit($limit_str)->select();

        foreach($list as $k=>&$v){
            if($v['vod_id'] > 0){
                $vod_info = (new \app\common\model\Vod())->infoData(['vod_id'=>['eq',$v['vod_id']]],'vod_id,vod_name,vod_play_from',1);
                if(!empty($vod_info['info'])){
                    $v['vod_name'] = $v['vod_name'] !== '' ? $v['vod_name'] : $vod_info['info']['vod_name'];
                    $v['vod_link'] = mac_url_vod_detail($vod_info['info']);
                    $froms = explode('$$$', (string)$vod_info['info']['vod_play_from']);
                    $v['from_name'] = isset($froms[$v['vod_sid']]) ? $froms[$v['vod_sid']] : ($v['play_from'] ?: ('线路'.($v['vod_sid']+1)));
                }
            }
        }

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$page,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
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

    public function reportFail($data)
    {
        $vod_id  = isset($data['vod_id'])  ? intval($data['vod_id'])  : 0;
        $vod_sid = isset($data['vod_sid']) ? intval($data['vod_sid']) : 0;
        $vod_nid = isset($data['vod_nid']) ? intval($data['vod_nid']) : 0;

        if($vod_id <= 0){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $play_from = isset($data['play_from']) ? mac_filter_xss((string)$data['play_from']) : '';
        $vod_name  = isset($data['vod_name'])  ? mac_filter_xss((string)$data['vod_name'])  : '';
        $switched  = !empty($data['switched']) ? 1 : 0;
        $ip        = isset($data['ip']) ? (string)$data['ip'] : '';
        $time      = time();

        $play_from = mb_substr($play_from, 0, 30);
        $vod_name  = mb_substr($vod_name, 0, 255);
        $ip        = mb_substr($ip, 0, 45);

        $where = [
            'vod_id'  => ['eq', $vod_id],
            'vod_sid' => ['eq', $vod_sid],
            'vod_nid' => ['eq', $vod_nid],
        ];
        $exist = $this->where($where)->find();

        if($exist){
            $update = [
                'fail_count'     => ['inc', 1],
                'last_fail_time' => $time,
                'last_fail_ip'   => $ip,
            ];
            if($switched){
                $update['switch_count'] = ['inc', 1];
            }
            if($play_from !== ''){
                $update['play_from'] = $play_from;
            }
            if($vod_name !== ''){
                $update['vod_name'] = $vod_name;
            }
            $res = $this->where($where)->update($update);
        }
        else{
            $insert = [
                'vod_id'          => $vod_id,
                'vod_sid'         => $vod_sid,
                'vod_nid'         => $vod_nid,
                'play_from'       => $play_from,
                'vod_name'        => $vod_name,
                'fail_count'      => 1,
                'switch_count'    => $switched,
                'first_fail_time' => $time,
                'last_fail_time'  => $time,
                'last_fail_ip'    => $ip,
            ];
            $res = $this->insert($insert);
        }

        if(false === $res){
            return ['code'=>1004,'msg'=>lang('save_err').'：'.$this->getError() ];
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
}
