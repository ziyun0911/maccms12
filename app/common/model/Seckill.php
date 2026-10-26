<?php
namespace app\common\model;
use think\facade\Db;

class Seckill extends Base {
    protected $name = 'seckill';
    protected $createTime = '';
    protected $updateTime = '';
    protected $auto = [];
    protected $insert = [];
    protected $update = [];

    public function listData($where, $order, $page = 1, $limit = 20, $start = 0)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;
        if (!is_array($where)) {
            $where = json_decode($where, true);
        }
        $limit_str = ($limit * ($page - 1) + $start) . "," . $limit;
        $total = $this->where($where)->count();
        $list = $this->where($where)->order($order)->limit($limit_str)->select();
        return ['code' => 1, 'msg' => lang('data_list'), 'page' => $page, 'pagecount' => ceil($total / $limit), 'limit' => $limit, 'total' => $total, 'list' => $list];
    }

    public function infoData($where, $field = '*')
    {
        if (empty($where) || !is_array($where)) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        $info = $this->field($field)->where($where)->find();
        if (empty($info)) {
            return ['code' => 1002, 'msg' => lang('obtain_err')];
        }
        $info = $info->toArray();
        return ['code' => 1, 'msg' => lang('obtain_ok'), 'info' => $info];
    }

    public function saveData($data)
    {
        $validate = new \app\common\validate\Seckill();
        $scene = !empty($data['seckill_id']) ? 'edit' : 'add';
        if (!$validate->scene($scene)->check($data)) {
            return ['code' => 1001, 'msg' => lang('param_err') . '：' . $validate->getError()];
        }
        $data['seckill_time'] = time();
        if (!empty($data['seckill_id'])) {
            $where = [];
            $where['seckill_id'] = ['eq', $data['seckill_id']];
            $res = $this->allowField([])->where($where)->update($data);
        } else {
            $res = $this->allowField([])->insert($data);
        }
        if (false === $res) {
            return ['code' => 1002, 'msg' => lang('save_err') . '：' . $this->getError()];
        }
        return ['code' => 1, 'msg' => lang('save_ok')];
    }

    public function delData($where)
    {
        $res = $this->where($where)->delete();
        if ($res === false) {
            return ['code' => 1001, 'msg' => lang('del_err') . '：' . $this->getError()];
        }
        return ['code' => 1, 'msg' => lang('del_ok')];
    }

    public function fieldData($where, $col, $val)
    {
        if (!isset($col) || !isset($val)) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        $data = [];
        $data[$col] = $val;
        $res = $this->allowField([])->where($where)->update($data);
        if ($res === false) {
            return ['code' => 1001, 'msg' => lang('set_err') . '：' . $this->getError()];
        }
        return ['code' => 1, 'msg' => lang('set_ok')];
    }

    public function buy($seckill_id, $user_id)
    {
        $seckill_id = intval($seckill_id);
        $user_id = intval($user_id);
        if ($seckill_id < 1 || $user_id < 1) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }

        Db::startTrans();
        try {
            $seckill = $this->where('seckill_id', $seckill_id)->lock(true)->find();
            if (empty($seckill)) {
                Db::rollback();
                return ['code' => 1002, 'msg' => lang('seckill/not_found')];
            }
            $seckill = $seckill->toArray();
            if (intval($seckill['seckill_status']) !== 1) {
                Db::rollback();
                return ['code' => 1003, 'msg' => lang('seckill/offline')];
            }
            $now = time();
            if (intval($seckill['seckill_start_time']) > 0 && $now < intval($seckill['seckill_start_time'])) {
                Db::rollback();
                return ['code' => 1004, 'msg' => lang('seckill/not_started')];
            }
            if (intval($seckill['seckill_end_time']) > 0 && $now > intval($seckill['seckill_end_time'])) {
                Db::rollback();
                return ['code' => 1005, 'msg' => lang('seckill/ended')];
            }
            $perUser = max(1, intval($seckill['seckill_per_user']));
            $myCount = Db::name('seckill_user')->where('seckill_id', $seckill_id)->where('user_id', $user_id)->count();
            if ($myCount >= $perUser) {
                Db::rollback();
                return ['code' => 1006, 'msg' => lang('seckill/per_user_limit')];
            }
            if (intval($seckill['seckill_sold']) >= intval($seckill['seckill_total'])) {
                Db::rollback();
                return ['code' => 1007, 'msg' => lang('seckill/sold_out')];
            }
            $pricePoints = intval($seckill['seckill_price_points']);
            if ($pricePoints < 1) {
                Db::rollback();
                return ['code' => 1008, 'msg' => lang('seckill/price_invalid')];
            }

            $user = Db::name('user')->where('user_id', $user_id)->lock(true)->find();
            if (empty($user)) {
                Db::rollback();
                return ['code' => 1009, 'msg' => lang('model/user/not_found')];
            }
            if (intval($user['user_points']) < $pricePoints) {
                Db::rollback();
                return ['code' => 1008, 'msg' => lang('model/user/potins_not_enough')];
            }

            $aff = Db::name('user')->where('user_id', $user_id)
                ->where('user_points', '>=', $pricePoints)
                ->setDec('user_points', $pricePoints);
            if ($aff === false || $aff < 1) {
                Db::rollback();
                return ['code' => 1008, 'msg' => lang('model/user/potins_not_enough')];
            }

            $affStock = $this->where('seckill_id', $seckill_id)
                ->where('seckill_sold', '<', Db::raw('seckill_total'))
                ->setInc('seckill_sold', 1);
            if ($affStock === false || $affStock < 1) {
                Db::rollback();
                return ['code' => 1007, 'msg' => lang('seckill/sold_out')];
            }

            $orderCode = 'SK' . mac_get_uniqid_code();
            try {
                Db::name('seckill_user')->insertGetId([
                    'seckill_id'      => $seckill_id,
                    'user_id'         => $user_id,
                    'order_code'      => $orderCode,
                    'seckill_user_time' => $now,
                ]);
            } catch (\Exception $e) {
                Db::rollback();
                return ['code' => 1006, 'msg' => lang('seckill/per_user_limit')];
            }

            $plog = [
                'user_id'  => $user_id,
                'plog_type' => 13,
                'plog_points' => $pricePoints,
                'plog_remarks' => $orderCode,
            ];
            $plogRes = (new \app\common\model\Plog())->saveData($plog);
            if ($plogRes['code'] > 1) {
                Db::rollback();
                return $plogRes;
            }

            $grantRes = $this->grantBenefit($seckill, $user, $orderCode);
            if ($grantRes['code'] > 1) {
                Db::rollback();
                return $grantRes;
            }

            Db::commit();
            return ['code' => 1, 'msg' => lang('seckill/buy_ok'), 'info' => ['order_code' => $orderCode, 'seckill_price_points' => $pricePoints]];
        } catch (\Exception $e) {
            Db::rollback();
            \think\facade\Log::error('Seckill buy failed seckill_id=' . $seckill_id . ' user_id=' . $user_id . ' err=' . $e->getMessage());
            return ['code' => 1010, 'msg' => lang('save_err')];
        }
    }

    private function grantBenefit($seckill, $user, $orderCode)
    {
        $targetType = trim($seckill['seckill_target_type']);
        $targetId = intval($seckill['seckill_target_id']);
        if ($targetType !== 'vip_group' || $targetId < 3) {
            return ['code' => 1011, 'msg' => lang('seckill/target_type_invalid')];
        }
        $long = trim($seckill['seckill_target_long'] ?? 'month');
        $points_long = ['day' => 86400, 'week' => 86400 * 7, 'month' => 86400 * 30, 'year' => 86400 * 365];
        if (!isset($points_long[$long])) {
            $long = 'month';
        }
        $days = intval($points_long[$long] / 86400);
        $res = (new \app\common\model\User())->grantVipDays($user['user_id'], $targetId, $days);
        if ($res['code'] > 1) {
            return $res;
        }
        return ['code' => 1, 'msg' => 'ok'];
    }
}