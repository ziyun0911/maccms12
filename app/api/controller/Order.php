<?php

namespace app\api\controller;

use app\common\util\CsrfGuard;
use think\facade\Db;
use think\Request;

class Order extends Base
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
                    'response' => json(['code' => 1401, 'msg' => '未登录，请先登录'])];
        }
        $uid  = intval($check['info']['user_id']);
        $user = Db::name('User')->where('user_id', $uid)->find();
        if (!$user) {
            return ['ok' => false, 'user_id' => 0, 'user' => null,
                    'response' => json(['code' => 1002, 'msg' => '用户不存在'])];
        }
        return ['ok' => true, 'user_id' => $uid, 'user' => $user, 'response' => null];
    }

    public function create(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        if (!$request->isPost()) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $csrfErr = $this->checkCsrf();
        if ($csrfErr !== null) return json($csrfErr);

        $limited = $this->apiRateLimit('order_create', $auth['user_id'], 20, 60);
        if ($limited !== true) {
            return $limited;
        }

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => '参数错误: ' . $validate->getError()]);
        }

        $price = floatval($param['price'] ?? 0);

        $pay_config = config('maccms.pay');
        if (!empty($pay_config['min']) && $price < $pay_config['min']) {
            return json(['code' => 1002, 'msg' => '最小充值金额不能低于' . $pay_config['min'] . '元']);
        }

        $couponUserId = intval($param['coupon_user_id'] ?? 0);
        $priced = (new \app\common\model\Coupon())->calculateOrderPrice('recharge', $price, [
            'user_id'        => $auth['user_id'],
            'coupon_user_id' => $couponUserId,
        ]);
        if ($priced['code'] > 1) {
            return json($priced);
        }
        $payPrice = floatval($priced['info']['pay_price']);
        $originalPrice = floatval($priced['info']['original_price']);
        $scale = intval($pay_config['scale'] ?? 1);
        $orderPoints = intval($scale * $originalPrice);

        $data = [];
        $data['user_id']      = $auth['user_id'];
        $data['order_code']   = 'PAY' . mac_get_uniqid_code();
        $data['order_price']  = $payPrice;
        $data['order_time']   = time();
        $data['order_points'] = $orderPoints;
        $data['order_remarks'] = '';
        if (!empty($priced['info']['snapshot'])) {
            $data['order_remarks'] = json_encode($priced['info']['snapshot'], JSON_UNESCAPED_UNICODE);
        }

        $res = (new \app\common\model\Order())->saveData($data);
        if ($res['code'] > 1) {
            return json($res);
        }

        if ($couponUserId > 0) {
            $orderId = intval(\think\facade\Db::name('Order')->where('order_code', $data['order_code'])->value('order_id'));
            $resv = (new \app\common\model\CouponUser())->reserveForOrder($couponUserId, $auth['user_id'], $orderId, $data['order_code']);
            if ($resv['code'] > 1) {
                \think\facade\Db::name('Order')->where('order_code', $data['order_code'])->delete();
                return json($resv);
            }
        }

        return json([
            'code' => 1,
            'msg'  => '订单创建成功',
            'info' => [
                'order_code'   => $data['order_code'],
                'order_price'  => $data['order_price'],
                'order_points' => $data['order_points'],
                'order_time'   => $data['order_time'],
            ],
        ]);
    }

    public function get_list(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => '参数错误: ' . $validate->getError()]);
        }

        $page  = max(1, intval($param['page'] ?? 1));
        $limit = max(1, min(100, intval($param['limit'] ?? 20)));

        $where = [];
        $where['o.user_id'] = $auth['user_id'];

        if (isset($param['status']) && $param['status'] !== '') {
            $where['order_status'] = intval($param['status']);
        }

        $order = 'o.order_id desc';
        $res   = (new \app\common\model\Order())->listData($where, $order, $page, $limit);

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => $res,
        ]);
    }

    public function get_detail(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => '参数错误: ' . $validate->getError()]);
        }

        $where = [];
        $where['user_id'] = $auth['user_id'];

        if (!empty($param['order_id'])) {
            $where['order_id'] = intval($param['order_id']);
        } elseif (!empty($param['order_code'])) {
            $where['order_code'] = htmlspecialchars(urldecode(trim($param['order_code'])));
        } else {
            return json(['code' => 1001, 'msg' => '参数错误: order_id 或 order_code 必须']);
        }

        $res = (new \app\common\model\Order())->infoData($where);
        return json($res);
    }

    public function check_status(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => '参数错误: ' . $validate->getError()]);
        }

        $order_code = htmlspecialchars(urldecode(trim($param['order_code'] ?? '')));

        if (empty($order_code)) {
            return json(['code' => 1001, 'msg' => '参数错误: order_code 必须']);
        }

        $where = [];
        $where['order_code'] = $order_code;
        $where['user_id']    = $auth['user_id'];

        $res = (new \app\common\model\Order())->infoData($where);
        if ($res['code'] > 1) {
            return json($res);
        }

        $info = $res['info'];
        $status_text = $info['order_status'] == 1 ? '已支付' : '未支付';

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'order_code'        => $info['order_code'],
                'order_price'       => $info['order_price'],
                'order_points'      => $info['order_points'],
                'order_status'      => intval($info['order_status']),
                'order_status_text' => $status_text,
                'order_pay_type'    => $info['order_pay_type'] ?? '',
                'order_pay_time'    => $info['order_pay_time'] ?? 0,
                'order_time'        => $info['order_time'],
            ],
        ]);
    }
}
