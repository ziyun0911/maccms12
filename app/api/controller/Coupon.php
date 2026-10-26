<?php

namespace app\api\controller;

use app\common\util\CsrfGuard;
use think\facade\Db;
use think\Request;

class Coupon extends Base
{
    use PublicApi;
    use CsrfGuard;

    public function __construct()
    {
        parent::__construct();
        $this->check_config();
    }

    private function _checkLogin()
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return ['ok' => false, 'user_id' => 0, 'user' => null,
                    'response' => json(['code' => 1401, 'msg' => lang('model/user/not_login')])];
        }
        $uid  = intval($check['info']['user_id']);
        $user = Db::name('User')->where('user_id', $uid)->find();
        if (!$user) {
            return ['ok' => false, 'user_id' => 0, 'user' => null,
                    'response' => json(['code' => 1002, 'msg' => lang('model/user/not_found')])];
        }
        return ['ok' => true, 'user_id' => $uid, 'user' => $user, 'response' => null];
    }

    public function get_list(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $param = $request->param();
        $scene = trim((string)($param['scene'] ?? 'all'));
        if (!in_array($scene, ['all', 'recharge', 'vip'], true)) {
            $scene = 'all';
        }
        $price = floatval($param['price'] ?? 0);
        $groupId = intval($param['group_id'] ?? 0);
        $long = trim((string)($param['long'] ?? ''));
        $now = time();

        $where = [];
        $where['coupon_status'] = 1;
        $where['coupon_start_time'] = [['eq', 0], ['elt', $now], 'or'];
        $where['coupon_end_time'] = [['eq', 0], ['egt', $now], 'or'];

        $list = Db::name('coupon')->where($where)->order('coupon_time desc')->select();

        $available = [];
        $mine = [];
        $myReceivedMap = [];
        $myRows = Db::name('coupon_user')->where('user_id', $auth['user_id'])->select();
        foreach ($myRows as $cu) {
            $myReceivedMap[intval($cu['coupon_id'])] = $cu;
        }

        foreach ($list as $row) {
            if ($row['coupon_scene'] !== 'all' && $row['coupon_scene'] !== $scene) {
                continue;
            }
            if (isset($myReceivedMap[intval($row['coupon_id'])])) {
                $cu = $myReceivedMap[intval($row['coupon_id'])];
                $row['coupon_user_id'] = intval($cu['coupon_user_id']);
                $row['coupon_user_status'] = intval($cu['coupon_user_status']);
                $row['order_code'] = (string)$cu['order_code'];
                $row['usable'] = (intval($cu['coupon_user_status']) === 0 && (string)$cu['order_code'] === '') ? 1 : 0;
                $row['coupon_target_arr'] = json_decode($row['coupon_target'], true);
                $mine[] = $row;
                continue;
            }
            if (intval($row['coupon_received']) >= intval($row['coupon_total'])) {
                continue;
            }
            if ($price > 0 && floatval($row['coupon_min_price']) > $price) {
                continue;
            }
            if ($scene === 'vip' && !empty($row['coupon_target'])) {
                $target = json_decode($row['coupon_target'], true);
                if (is_array($target)) {
                    if (!empty($target['groups']) && !in_array($groupId, $target['groups'], true)) {
                        continue;
                    }
                    if (!empty($target['longs']) && $long !== '' && !in_array($long, $target['longs'], true)) {
                        continue;
                    }
                }
            }
            $row['coupon_target_arr'] = json_decode($row['coupon_target'], true);
            $available[] = $row;
        }

        return json([
            'code' => 1,
            'msg'  => lang('obtain_ok'),
            'info' => [
                'available' => $available,
                'mine'       => $mine,
            ],
        ]);
    }

    public function receive(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        if (!$request->isPost()) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $csrfErr = $this->checkCsrf();
        if ($csrfErr !== null) return json($csrfErr);

        $limited = $this->apiRateLimit('coupon_receive', $auth['user_id'], 10, 60);
        if ($limited !== true) {
            return $limited;
        }

        $couponId = intval($request->param('coupon_id', 0));
        if ($couponId < 1) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }
        $res = (new \app\common\model\Coupon())->receive($couponId, $auth['user_id']);
        return json($res);
    }
}
