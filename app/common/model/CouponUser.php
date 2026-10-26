<?php
namespace app\common\model;
use think\facade\Db;

class CouponUser extends Base {
    protected $name = 'coupon_user';
    protected $createTime = '';
    protected $updateTime = '';
    protected $auto = [];
    protected $insert = [];
    protected $update = [];

    const RESERVATION_TTL = 1800;

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
        $list = Db::name('coupon_user')->alias('cu')
            ->field('cu.*, c.coupon_name, c.coupon_type, c.coupon_value, c.coupon_min_price, c.coupon_scene, c.coupon_target, c.coupon_end_time')
            ->join('coupon c', 'cu.coupon_id = c.coupon_id', 'left')
            ->where($where)->order($order)->limit($limit_str)->select();
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

    public function reserveForOrder($cu_id, $user_id, $order_id, $order_code)
    {
        $cu_id = intval($cu_id);
        $user_id = intval($user_id);
        $order_id = intval($order_id);
        $order_code = trim((string)$order_code);
        if ($cu_id < 1 || $user_id < 1 || $order_id < 1 || $order_code === '') {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        $cu = $this->where('coupon_user_id', $cu_id)->find();
        if (empty($cu) || intval($cu['user_id']) !== $user_id) {
            return ['code' => 1002, 'msg' => lang('coupon/not_yours')];
        }
        $cu = $cu->toArray();
        if (intval($cu['coupon_user_status']) !== 0) {
            return ['code' => 1003, 'msg' => lang('coupon/used')];
        }
        if ($cu['order_code'] === $order_code) {
            return ['code' => 1, 'msg' => lang('save_ok')];
        }
        if ($cu['order_code'] !== '') {
            return ['code' => 1004, 'msg' => lang('coupon/reserved')];
        }
        $aff = $this->where('coupon_user_id', $cu_id)
            ->where('coupon_user_status', 0)
            ->where('order_code', '')
            ->update(['order_id' => $order_id, 'order_code' => $order_code]);
        if ($aff === false || $aff < 1) {
            return ['code' => 1004, 'msg' => lang('coupon/reserved')];
        }
        return ['code' => 1, 'msg' => lang('save_ok')];
    }

    public function releaseExpiredReservation($cu_id)
    {
        $cu_id = intval($cu_id);
        if ($cu_id < 1) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        $cu = $this->where('coupon_user_id', $cu_id)->find();
        if (empty($cu)) {
            return ['code' => 1002, 'msg' => lang('obtain_err')];
        }
        $cu = $cu->toArray();
        if (intval($cu['coupon_user_status']) !== 0 || $cu['order_code'] === '') {
            return ['code' => 1, 'msg' => lang('save_ok')];
        }
        $bound = Db::name('order')->where('order_code', $cu['order_code'])->find();
        $expired = false;
        if (empty($bound)) {
            $expired = true;
        } elseif (intval($bound['order_status']) === 0 && intval($bound['order_time']) < (time() - self::RESERVATION_TTL)) {
            $expired = true;
        }
        if (!$expired) {
            return ['code' => 1003, 'msg' => lang('coupon/reserved')];
        }
        if (!empty($bound)) {
            Db::name('order')->where('order_code', $cu['order_code'])->where('order_status', 0)->delete();
        }
        $aff = $this->where('coupon_user_id', $cu_id)
            ->where('coupon_user_status', 0)
            ->update(['order_id' => 0, 'order_code' => '']);
        if ($aff === false || $aff < 1) {
            return ['code' => 1003, 'msg' => lang('coupon/reserved')];
        }
        return ['code' => 1, 'msg' => lang('save_ok')];
    }
}