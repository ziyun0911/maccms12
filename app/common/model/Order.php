<?php
namespace app\common\model;
use think\facade\Db;

class Order extends Base {
    protected $name = 'order';

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
        $limit_str = ($limit * ($page-1) + $start) .",".$limit;
        $total = $this->alias('o')->where($where)->count();
        $list = Db::name('Order')->alias('o')
            ->field('o.*,u.user_name')
            ->join('user u','o.user_id = u.user_id','left')
            ->where($where)
            ->order($order)
            ->limit($limit_str)
            ->select();

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

    public function saveData($data)
    {
        $validate = validate('app\\common\\validate\\Order', [], false, false);
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').'：'.$validate->getError() ];
        }

        $data['order_time'] = time();
        if(!empty($data['order_id'])){
            $where=[];
            $where['order_id'] = ['eq',$data['order_id']];
            $res = $this->allowField([])->where($where)->update($data);
        }
        else{
            $res = $this->allowField([])->insert($data);
        }
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err').'：'.$this->getError() ];
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

    public function notify($order_code,$pay_type)
    {
        if(empty($order_code) || empty($pay_type)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $where = [];
        $where['order_code'] = $order_code;
        $order = (new \app\common\model\Order())->infoData($where);
        if($order['code']>1){
            return $order;
        }
        if($order['info']['order_status'] == 1){
            return ['code'=>1,'msg'=>lang('model/order/pay_over')];
        }

        $where2=[];
        $where2['user_id'] = $order['info']['user_id'];
        $user = (new \app\common\model\User())->infoData($where2);
        if($user['code']>1){
            return $user;
        }

        Db::startTrans();
        try{
            $update = [];
            $update['order_status'] = 1;
            $update['order_pay_time'] = time();
            $update['order_pay_type'] = $pay_type;
            $res = $this->where($where)->where('order_status', 0)->update($update);
            if($res===false){
                Db::rollback();
                return ['code'=>2002,'msg'=>lang('model/order/update_status_err')];
            }
            if(intval($res) < 1){
                Db::rollback();
                return ['code'=>1,'msg'=>lang('model/order/pay_over')];
            }

            $remarks = json_decode($order['info']['order_remarks'], true);
            $writeOff = $this->writeOffFromOrder($order['info'], $order_code);
            if ($writeOff['code'] > 1) {
                \think\facade\Log::error('COUPON ANOMALY order_code=' . $order_code
                    . ' coupon_user_id=' . intval($writeOff['info']['coupon_user_id'])
                    . ' code=' . $writeOff['code'] . ' msg=' . $writeOff['msg']);
            }

            $where2 = [];
            $where2['user_id'] = $user['info']['user_id'];
            $res = (new \app\common\model\User())->where($where2)->setInc('user_points',$order['info']['order_points']);
            if($res===false){
                Db::rollback();
                return ['code'=>2003,'msg'=>lang('model/order/update_user_points_err')];
            }

            $data = [];
            $data['user_id'] = $user['info']['user_id'];
            $data['plog_type'] = 1;
            $data['plog_points'] = $order['info']['order_points'];
            $data['plog_remarks'] = (string)$order_code;
            (new \app\common\model\Plog())->saveData($data);

            if(!empty($remarks) && is_array($remarks) && ($remarks['biz'] ?? '') === 'member_upgrade'){
                $user_latest = (new \app\common\model\User())->infoData(['user_id' => $user['info']['user_id']]);
                if($user_latest['code'] > 1){
                    Db::rollback();
                    return $user_latest;
                }
                $upgrade_res = (new \app\common\model\User())->upgradeByPaidOrder($order['info'], $user_latest['info']);
                if($upgrade_res['code'] > 1){
                    Db::rollback();
                    return $upgrade_res;
                }
            }

            Db::commit();

            try {
                (new \app\common\model\Notify())->send($user['info']['user_id'], 'order', lang('notify/order_pay_ok_title'), lang('notify/order_pay_ok_content', [$order['info']['order_code']]), '/user/orders');
            } catch (\Exception $e) {
                \think\facade\Log::error('Order pay notify uid=' . $user['info']['user_id'] . ' err=' . $e->getMessage());
            }

            return ['code'=>1,'msg'=>lang('model/order/pay_ok')];
        }catch (\Exception $e){
            Db::rollback();
            \think\facade\Log::error('Order notify failed order_code=' . $order_code . ' err=' . $e->getMessage());
            return ['code'=>2004,'msg'=>lang('save_err')];
        }
    }

    public function writeOffFromOrder($order, $order_code = '')
    {
        if (empty($order) || !is_array($order)) {
            return ['code' => 1001, 'msg' => lang('param_err'), 'info' => ['coupon_user_id' => 0]];
        }
        $remarks = json_decode((string)$order['order_remarks'], true);
        $cuId = 0;
        if (is_array($remarks)) {
            if (!empty($remarks['coupon']['coupon_user_id'])) {
                $cuId = intval($remarks['coupon']['coupon_user_id']);
            } elseif (!empty($remarks['coupon_user_id'])) {
                $cuId = intval($remarks['coupon_user_id']);
            }
        }
        if ($cuId < 1) {
            return ['code' => 1, 'msg' => lang('save_ok'), 'info' => ['coupon_user_id' => 0]];
        }
        $cu = (new \app\common\model\CouponUser())->where('coupon_user_id', $cuId)->find();
        if (empty($cu)) {
            return ['code' => 2010, 'msg' => lang('coupon/not_yours'), 'info' => ['coupon_user_id' => $cuId]];
        }
        $cu = $cu->toArray();
        if (intval($cu['coupon_user_status']) === 1) {
            if ((string)$cu['order_code'] !== '' && (string)$order_code !== '' && (string)$cu['order_code'] !== (string)$order_code) {
                return ['code' => 2011, 'msg' => lang('coupon/used'), 'info' => ['coupon_user_id' => $cuId]];
            }
            return ['code' => 1, 'msg' => lang('save_ok'), 'info' => ['coupon_user_id' => $cuId]];
        }
        $now = time();
        $aff = (new \app\common\model\CouponUser())->where('coupon_user_id', $cuId)
            ->where('coupon_user_status', 0)
            ->update([
                'coupon_user_status' => 1,
                'coupon_user_use_time' => $now,
                'order_code' => (string)$order_code,
                'order_id' => intval($order['order_id']),
            ]);
        if ($aff === false || $aff < 1) {
            return ['code' => 2010, 'msg' => lang('coupon/used'), 'info' => ['coupon_user_id' => $cuId]];
        }
        Db::name('coupon')->where('coupon_id', $cu['coupon_id'])->setInc('coupon_used', 1);
        return ['code' => 1, 'msg' => lang('save_ok'), 'info' => ['coupon_user_id' => $cuId]];
    }
}