<?php
namespace app\common\model;
use think\facade\Db;

class Coupon extends Base {
    protected $name = 'coupon';
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
        $validate = new \app\common\validate\Coupon();
        $scene = !empty($data['coupon_id']) ? 'edit' : 'add';
        if (!$validate->scene($scene)->check($data)) {
            return ['code' => 1001, 'msg' => lang('param_err') . '：' . $validate->getError()];
        }
        $data['coupon_time'] = time();
        if (!empty($data['coupon_id'])) {
            $where = [];
            $where['coupon_id'] = ['eq', $data['coupon_id']];
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

    public function receive($coupon_id, $user_id)
    {
        $coupon_id = intval($coupon_id);
        $user_id = intval($user_id);
        if ($coupon_id < 1 || $user_id < 1) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }

        Db::startTrans();
        try {
            $coupon = $this->where('coupon_id', $coupon_id)->lock(true)->find();
            if (empty($coupon)) {
                Db::rollback();
                return ['code' => 1002, 'msg' => lang('coupon/not_found')];
            }
            $coupon = $coupon->toArray();
            if (intval($coupon['coupon_status']) !== 1) {
                Db::rollback();
                return ['code' => 1003, 'msg' => lang('coupon/offline')];
            }
            $now = time();
            if (intval($coupon['coupon_start_time']) > 0 && $now < intval($coupon['coupon_start_time'])) {
                Db::rollback();
                return ['code' => 1004, 'msg' => lang('coupon/not_started')];
            }
            if (intval($coupon['coupon_end_time']) > 0 && $now > intval($coupon['coupon_end_time'])) {
                Db::rollback();
                return ['code' => 1005, 'msg' => lang('coupon/expired')];
            }
            $perUser = max(1, intval($coupon['coupon_per_user']));
            $myCount = Db::name('coupon_user')->where('coupon_id', $coupon_id)->where('user_id', $user_id)->count();
            if ($myCount >= $perUser) {
                Db::rollback();
                return ['code' => 1006, 'msg' => lang('coupon/per_user_limit')];
            }
            if (intval($coupon['coupon_received']) >= intval($coupon['coupon_total'])) {
                Db::rollback();
                return ['code' => 1007, 'msg' => lang('coupon/out_of_stock')];
            }
            $inc = $this->where('coupon_id', $coupon_id)->setInc('coupon_received', 1);
            if ($inc === false) {
                Db::rollback();
                return ['code' => 1008, 'msg' => lang('save_err')];
            }
            try {
                $cu_id = Db::name('coupon_user')->insertGetId([
                    'coupon_id'          => $coupon_id,
                    'user_id'            => $user_id,
                    'coupon_user_status' => 0,
                    'coupon_user_time'   => $now,
                    'coupon_user_use_time' => 0,
                    'order_id'           => 0,
                    'order_code'         => '',
                ]);
            } catch (\Exception $e) {
                Db::rollback();
                return ['code' => 1006, 'msg' => lang('coupon/per_user_limit')];
            }
            Db::commit();
            return ['code' => 1, 'msg' => lang('coupon/receive_ok'), 'info' => ['coupon_user_id' => intval($cu_id)]];
        } catch (\Exception $e) {
            Db::rollback();
            \think\facade\Log::error('Coupon receive failed coupon_id=' . $coupon_id . ' user_id=' . $user_id . ' err=' . $e->getMessage());
            return ['code' => 1009, 'msg' => lang('save_err')];
        }
    }

    public function calculateOrderPrice($scene, $base_price, $opts = [])
    {
        $scene = trim($scene);
        $base_price = floatval($base_price);
        $userId = intval($opts['user_id'] ?? 0);
        $cuId = intval($opts['coupon_user_id'] ?? 0);

        if ($cuId < 1 || $userId < 1) {
            return $this->priceOk(number_format($base_price, 2, '.', ''), '0.00', 0.0, number_format($base_price, 2, '.', ''), $scene, null);
        }

        $cu = Db::name('coupon_user')->where('coupon_user_id', $cuId)->find();
        if (empty($cu) || intval($cu['user_id']) !== $userId) {
            return ['code' => 1010, 'msg' => lang('coupon/not_yours')];
        }
        $coupon = $this->where('coupon_id', $cu['coupon_id'])->find();
        if (empty($coupon)) {
            return ['code' => 1010, 'msg' => lang('coupon/not_yours')];
        }
        $coupon = $coupon->toArray();

        if (intval($cu['coupon_user_status']) === 1) {
            return ['code' => 1010, 'msg' => lang('coupon/used')];
        }
        if ($cu['order_code'] !== '') {
            $reservedOrderCode = (string)($opts['order_code'] ?? '');
            if ($reservedOrderCode === '' || $cu['order_code'] !== $reservedOrderCode) {
                $bound = Db::name('order')->where('order_code', $cu['order_code'])->find();
                $released = false;
                if (empty($bound) || (intval($bound['order_status']) === 0 && intval($bound['order_time']) < (time() - \app\common\model\CouponUser::RESERVATION_TTL))) {
                    $rel = (new \app\common\model\CouponUser())->releaseExpiredReservation($cuId);
                    if ($rel['code'] === 1) {
                        $released = true;
                        $cu['order_code'] = '';
                    }
                }
                if (!$released) {
                    return ['code' => 1011, 'msg' => lang('coupon/reserved')];
                }
            }
        }
        $now = time();
        if (intval($coupon['coupon_start_time']) > 0 && $now < intval($coupon['coupon_start_time'])) {
            return ['code' => 1004, 'msg' => lang('coupon/not_started')];
        }
        if (intval($coupon['coupon_end_time']) > 0 && $now > intval($coupon['coupon_end_time'])) {
            return ['code' => 1005, 'msg' => lang('coupon/expired')];
        }
        if (intval($coupon['coupon_status']) !== 1) {
            return ['code' => 1003, 'msg' => lang('coupon/offline')];
        }
        if ($coupon['coupon_scene'] !== 'all' && $coupon['coupon_scene'] !== $scene) {
            return ['code' => 1013, 'msg' => lang('coupon/scene_mismatch')];
        }
        $target = $this->decodeTarget($coupon['coupon_target']);
        if ($scene === 'vip' && !empty($target)) {
            $gId = intval($opts['group_id'] ?? 0);
            $long = trim($opts['long'] ?? '');
            if (!empty($target['groups']) && !in_array($gId, $target['groups'], true)) {
                return ['code' => 1014, 'msg' => lang('coupon/target_mismatch')];
            }
            if (!empty($target['longs']) && !in_array($long, $target['longs'], true)) {
                return ['code' => 1014, 'msg' => lang('coupon/target_mismatch')];
            }
        }
        $minPrice = floatval($coupon['coupon_min_price']);
        if ($base_price < $minPrice) {
            return ['code' => 1012, 'msg' => lang('coupon/min_price_not_met')];
        }
        $original = round($base_price, 2);
        if ($coupon['coupon_type'] === 'amount') {
            $discount = round(floatval($coupon['coupon_value']), 2);
        } else {
            $pct = min(100, max(0, floatval($coupon['coupon_value'])));
            $discount = round($original * $pct / 100, 2);
        }
        $pay = round($original - $discount, 2);
        if ($pay <= 0) {
            return ['code' => 1015, 'msg' => lang('coupon/zero_not_allowed')];
        }
        $snapshot = [
            'biz'           => 'coupon',
            'coupon_user_id'=> $cuId,
            'coupon_id'     => intval($cu['coupon_id']),
            'coupon_type'   => $coupon['coupon_type'],
            'coupon_value'  => $coupon['coupon_value'],
            'coupon_min_price' => $coupon['coupon_min_price'],
            'coupon_scene'  => $coupon['coupon_scene'],
            'coupon_target' => $coupon['coupon_target'],
            'original_price'=> number_format($original, 2, '.', ''),
            'coupon_discount'=> number_format($discount, 2, '.', ''),
            'pay_price'     => number_format($pay, 2, '.', ''),
        ];
        return $this->priceOk(number_format($original, 2, '.', ''), number_format($discount, 2, '.', ''), $discount, number_format($pay, 2, '.', ''), $scene, $snapshot);
    }

    private function priceOk($original, $discount, $discountNum, $pay, $scene, $snapshot)
    {
        return ['code' => 1, 'msg' => 'ok', 'info' => [
            'scene'           => $scene,
            'original_price'   => $original,
            'coupon_discount' => $discount,
            'pay_price'        => $pay,
            'snapshot'         => $snapshot,
        ]];
    }

    private function decodeTarget($target)
    {
        if (empty($target)) {
            return [];
        }
        $arr = json_decode($target, true);
        return is_array($arr) ? $arr : [];
    }
}