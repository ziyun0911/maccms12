<?php

namespace app\api\controller;

use think\facade\Db;
use think\Request;
use app\common\util\CsrfGuard;

class Payment extends Base
{
    use PublicApi;
    use CsrfGuard;

    public function __construct()
    {
        parent::__construct();
        $ac = request()->action();
        if ($ac !== 'notify') {
            $this->check_config();
        }
    }

    public static function isPayChannelReady($key, array $cfg)
    {
        $key = strtolower((string) $key);
        $t = function ($v) {
            return trim((string) ($v ?? ''));
        };
        switch ($key) {
            case 'alipay':
                return $t($cfg['appid'] ?? '') !== '' && $t($cfg['account'] ?? '') !== '';
            case 'weixin':
                return $t($cfg['appid'] ?? '') !== '' && $t($cfg['mchid'] ?? '') !== '' && $t($cfg['appkey'] ?? '') !== '';
            case 'epay':
                return $t($cfg['api_url'] ?? '') !== '' && $t($cfg['appid'] ?? '') !== '' && $t($cfg['appkey'] ?? '') !== '';
            case 'codepay':
            case 'zhapay':
                return $t($cfg['appid'] ?? '') !== '' && $t($cfg['appkey'] ?? '') !== '';
            case 'jeepay':
                return $t($cfg['api_url'] ?? '') !== '' && $t($cfg['mch_no'] ?? '') !== '' && $t($cfg['appid'] ?? '') !== '' && $t($cfg['appkey'] ?? '') !== '';
            default:
                return $t($cfg['appid'] ?? '') !== '';
        }
    }

    public static function payMethodsForConfig(array $payConfig)
    {
        $extends = mac_extends_list('pay');
        $split = function ($s) {
            $p = preg_split('/\s*,\s*/', (string) $s, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($p)) {
                return [];
            }

            return array_values(array_unique(array_map('trim', $p)));
        };
        $methods = [];
        foreach ($extends['ext_list'] ?? [] as $classKey => $displayName) {
            $key = strtolower((string) $classKey);
            $cfg = (isset($payConfig[$key]) && is_array($payConfig[$key])) ? $payConfig[$key] : [];
            $enabled = self::isPayChannelReady($key, $cfg) ? 1 : 0;
            $row = [
                'key'     => $key,
                'name'    => is_string($displayName) ? $displayName : $key,
                'enabled' => $enabled,
            ];
            if ($key === 'codepay') {
                $map = [
                    '1' => ['支付宝', 'Alipay'],
                    '2' => ['QQ钱包', 'QQ Wallet'],
                    '3' => ['微信', 'WeChat'],
                ];
                $pts = [];
                foreach ($split($cfg['type'] ?? '') as $v) {
                    $lab = isset($map[$v]) ? $map[$v] : [$v, $v];
                    $pts[] = ['value' => $v, 'label' => $lab[0], 'label_en' => $lab[1]];
                }
                $row['paytypes'] = $pts;
            } elseif ($key === 'zhapay') {
                $map = [
                    '1' => ['微信', 'WeChat'],
                    '2' => ['支付宝', 'Alipay'],
                ];
                $pts = [];
                foreach ($split($cfg['type'] ?? '') as $v) {
                    $lab = isset($map[$v]) ? $map[$v] : [$v, $v];
                    $pts[] = ['value' => $v, 'label' => $lab[0], 'label_en' => $lab[1]];
                }
                $row['paytypes'] = $pts;
            } elseif ($key === 'jeepay') {
                $map = [
                    'ALI_WAP'    => ['支付宝H5', 'Alipay H5'],
                    'ALI_QR'     => ['支付宝扫码', 'Alipay QR'],
                    'WX_H5'      => ['微信H5', 'WeChat H5'],
                    'WX_NATIVE'  => ['微信扫码', 'WeChat Native'],
                    'USDT_TRC20' => ['USDT-TRC20', 'USDT-TRC20'],
                ];
                $pts = [];
                foreach ($split($cfg['way_codes'] ?? '') as $v) {
                    $lab = isset($map[$v]) ? $map[$v] : [$v, $v];
                    $pts[] = ['value' => $v, 'label' => $lab[0], 'label_en' => $lab[1]];
                }
                $row['paytypes'] = $pts;
            }
            $methods[] = $row;
        }
        usort($methods, function ($a, $b) {
            return strcmp($a['key'], $b['key']);
        });

        return $methods;
    }

    private function _checkLogin()
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return ['ok' => false, 'user_id' => 0, 'user' => null,
                    'response' => json(['code' => 1401, 'msg' => lang('api/please_login_first')])];
        }
        $uid  = intval($check['info']['user_id']);
        $user = Db::name('User')->where('user_id', $uid)->find();
        if (!$user) {
            return ['ok' => false, 'user_id' => 0, 'user' => null,
                    'response' => json(['code' => 1002, 'msg' => lang('api/user_not_found')])];
        }
        return ['ok' => true, 'user_id' => $uid, 'user' => $user, 'response' => null];
    }

    public function get_config(Request $request)
    {
        $pay_config = config('maccms.pay');

        $loginCheck = (new \app\common\model\User())->checkLogin();
        $isLogin    = (intval($loginCheck['code']) === 1) && intval($loginCheck['info']['user_id'] ?? 0) > 0;
        $userPoints = $isLogin ? intval($loginCheck['info']['user_points'] ?? 0) : 0;

        $methods = self::payMethodsForConfig($pay_config);

        $card_config = [
            'enabled'  => !empty($pay_config['card']['url']) ? 1 : 0,
            'card_url' => $pay_config['card']['url'] ?? '',
        ];

        return json([
            'code' => 1,
            'msg'  => lang('obtain_ok'),
            'info' => [
                'min'          => floatval($pay_config['min'] ?? 1),
                'scale'        => intval($pay_config['scale'] ?? 1),
                'methods'      => $methods,
                'card_config'  => $card_config,
                'is_login'     => $isLogin ? 1 : 0,
                'user_points'  => $userPoints,
            ],
        ]);
    }
    public function gopay(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $csrfErr = $this->checkCsrf();
        if ($csrfErr !== null) return json($csrfErr);

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => lang('api/param_validate', [$validate->getError()])]);
        }

        $order_code = htmlspecialchars(urldecode(trim($param['order_code'] ?? '')));
        $order_id   = intval($param['order_id'] ?? 0);
        $payment    = strtolower(htmlspecialchars(urldecode(trim($param['payment'] ?? ''))));

        if (empty($order_code) || empty($order_id) || empty($payment)) {
            return json(['code' => 1001, 'msg' => lang('api/param_order_fields')]);
        }

        $pay_config = config('maccms.pay');
        if (empty($pay_config[$payment]['appid'])) {
            return json(['code' => 1002, 'msg' => lang('api/payment/payment_disabled')]);
        }

        $where = [];
        $where['order_id']   = $order_id;
        $where['order_code'] = $order_code;
        $where['user_id']    = $auth['user_id'];
        $res = (new \app\common\model\Order())->infoData($where);
        if ($res['code'] > 1) {
            return json(['code' => 1003, 'msg' => lang('api/payment/order_not_found')]);
        }
        if ($res['info']['order_status'] == 1) {
            return json(['code' => 1004, 'msg' => lang('api/payment/order_paid')]);
        }

        $order_info = $res['info'];

        $cp = 'app\\common\\extend\\pay\\' . ucfirst($payment);
        if (!class_exists($cp)) {
            return json(['code' => 1005, 'msg' => lang('api/payment/payment_missing', [$payment])]);
        }

        $c = new $cp;
        $payment_res = $c->submit($auth['user'], $order_info, $param, true);

        $payment_data = [];

        if ($payment === 'weixin' && is_array($payment_res) && !empty($payment_res['code_url'])) {
            $payment_data = [
                'type'     => 'qrcode',
                'code_url' => $payment_res['code_url'],
                'total_fee' => $payment_res['total_fee'] ?? $order_info['order_price'],
                'out_trade_no' => $payment_res['out_trade_no'] ?? $order_code,
            ];
        } elseif ($payment === 'weixin' && $payment_res === false) {
            return json(['code' => 1006, 'msg' => lang('api/payment/weixin_qr_fail')]);
        } elseif (is_string($payment_res) && !empty($payment_res)) {
            $payment_data = [
                'type' => 'html',
                'html' => $payment_res,
            ];
        } elseif (is_array($payment_res)) {
            $payment_data = [
                'type' => 'data',
                'data' => $payment_res,
            ];
        } else {
            $payment_data = [
                'type' => 'unknown',
                'data' => $payment_res,
            ];
        }

        return json([
            'code' => 1,
            'msg'  => lang('api/payment/pay_started_ok'),
            'info' => [
                'payment'      => $payment,
                'order_code'   => $order_code,
                'order_price'  => $order_info['order_price'],
                'payment_data' => $payment_data,
            ],
        ]);
    }

    public function notify()
    {
        $param = input();
        $pay_type = $param['pay_type'] ?? '';

        if (empty($pay_type)) {
            echo 'pay_type is required';
            exit;
        }

        $pay_config = config('maccms.pay');
        $cfg = (isset($pay_config[$pay_type]) && is_array($pay_config[$pay_type])) ? $pay_config[$pay_type] : [];

        if (!self::isPayChannelReady($pay_type, $cfg)) {
            echo lang('index/payment_status');
            exit;
        }

        $cp = 'app\\common\\extend\\pay\\' . ucfirst($pay_type);
        if (class_exists($cp)) {
            $c = new $cp;
            $c->notify();
        } else {
            echo lang('index/payment_not');
            exit;
        }
    }

    public function use_card(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => lang('api/param_validate', [$validate->getError()])]);
        }

        $card_no  = htmlspecialchars(urldecode(trim($param['card_no'] ?? '')));
        $card_pwd = htmlspecialchars(urldecode(trim($param['card_pwd'] ?? '')));

        $res = (new \app\common\model\Card())->useData($card_no, $card_pwd, $auth['user']);
        return json($res);
    }

    public function buy_popedom(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => lang('api/param_validate', [$validate->getError()])]);
        }

        $data  = [];
        $data['ulog_mid'] = intval($param['mid'] ?? 1) <= 0 ? 1 : intval($param['mid']);
        $data['ulog_rid'] = intval($param['id'] ?? 0);
        $data['ulog_sid'] = intval($param['sid'] ?? 0);
        $data['ulog_nid'] = intval($param['nid'] ?? 0);

        $data['ulog_type'] = intval($param['type']);
        $data['user_id']   = $auth['user_id'];

        if ($param['type'] == '1') {
            $where = ['art_id' => $data['ulog_rid']];
            $res = (new \app\common\model\Art())->infoData($where);
            if ($res['code'] > 1) {
                return json($res);
            }
            $col = 'art_points_detail';
            if ($GLOBALS['config']['user']['art_points_type'] == '1') {
                $col = 'art_points';
                $data['ulog_sid'] = 0;
                $data['ulog_nid'] = 0;
            }
        } else {
            $where = ['vod_id' => $data['ulog_rid']];
            $res = (new \app\common\model\Vod())->infoData($where);
            if ($res['code'] > 1) {
                return json($res);
            }
            $col = 'vod_points_' . ($param['type'] == '4' ? 'play' : 'down');
            if ($GLOBALS['config']['user']['vod_points_type'] == '1') {
                $col = 'vod_points';
                $data['ulog_sid'] = 0;
                $data['ulog_nid'] = 0;
            }
        }
        $data['ulog_points'] = intval($res['info'][$col]);

        $isDownload = (intval($param['mid']) == 1 && intval($param['type']) == 5);
        $owned = $isDownload
            ? (new \app\common\model\Ulog())->hasBought($data)
            : ((new \app\common\model\Ulog())->infoData($data)['code'] == 1);
        if ($owned) {
            return json(['code' => 1, 'msg' => lang('api/payment/already_owned')]);
        }

        if (intval($param['mid']) == 1 && intval($param['type']) == 5 && $data['ulog_points'] > 0 && intval($auth['user']['user_down_quota'] ?? 0) > 0) {
            $quotaRes = (new \app\common\model\User())->consumeDownQuota($auth['user_id'], $data);
            if (isset($quotaRes['code']) && intval($quotaRes['code']) === 1) {
                \app\common\model\UserAccessLog::record('buy', [
                    'user_id'   => intval($auth['user_id']),
                    'user_name' => isset($auth['user']['user_name']) ? (string)$auth['user']['user_name'] : '',
                    'mid'       => intval($data['ulog_mid']),
                    'rid'       => intval($data['ulog_rid']),
                ]);
            }
            return json($quotaRes);
        }

        if ($data['ulog_points'] > $auth['user']['user_points']) {
            return json([
                'code' => 1005,
                'msg'  => lang('api/payment/points_need_remain', [(string) $data['ulog_points'], (string) $auth['user']['user_points']]),
                'info' => [
                    'need_points'    => $data['ulog_points'],
                    'current_points' => intval($auth['user']['user_points']),
                ],
            ]);
        }

        Db::startTrans();
        try {
            $affected = Db::name('user')
                ->where('user_id', $auth['user_id'])
                ->where('user_points', '>=', $data['ulog_points'])
                ->setDec('user_points', $data['ulog_points']);

            if ($affected === 0 || $affected === false) {
                Db::rollback();
                return json(['code' => 1005, 'msg' => lang('api/payment/points_insufficient')]);
            }

            $plog = [];
            $plog['user_id']     = $auth['user_id'];
            $plog['plog_type']   = 8;
            $plog['plog_points'] = $data['ulog_points'];
            $plogRes = (new \app\common\model\Plog())->saveData($plog);
            if (!is_array($plogRes) || intval(isset($plogRes['code']) ? $plogRes['code'] : 0) !== 1) {
                Db::rollback();
                return json(['code' => 1006, 'msg' => lang('api/payment/operation_retry')]);
            }

            $prevUser = isset($GLOBALS['user']) ? $GLOBALS['user'] : null;
            $GLOBALS['user'] = $auth['user'];
            try {
                $rewardRes = (new \app\common\model\User())->reward($data['ulog_points'], true);
            } finally {
                $GLOBALS['user'] = $prevUser;
            }
            if (!is_array($rewardRes) || intval(isset($rewardRes['code']) ? $rewardRes['code'] : 0) !== 1) {
                Db::rollback();
                return json(['code' => 1006, 'msg' => lang('api/payment/operation_retry')]);
            }

            $save_res = (new \app\common\model\Ulog())->saveData($data);
            if (!is_array($save_res) || intval(isset($save_res['code']) ? $save_res['code'] : 0) !== 1) {
                Db::rollback();
                return json(['code' => 1006, 'msg' => lang('api/payment/operation_retry')]);
            }

            Db::commit();
            \app\common\model\UserAccessLog::record('buy', [
                'user_id'   => intval($auth['user_id']),
                'user_name' => isset($auth['user']['user_name']) ? (string)$auth['user']['user_name'] : '',
                'mid'       => intval($data['ulog_mid']),
                'rid'       => intval($data['ulog_rid']),
            ]);
            return json($save_res);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1006, 'msg' => lang('api/payment/operation_retry')]);
        }
    }

    public function upgrade(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => lang('api/param_validate', [$validate->getError()])]);
        }

        $res = (new \app\common\model\User())->upgrade($param);
        return json($res);
    }

    public function get_groups(Request $request)
    {
        $group_list = (new \app\common\model\Group())->getCache();

        $result = [];
        foreach ($group_list as $g) {
            if ($g['group_id'] < 3) continue;
            if ($g['group_status'] == 0) continue;

            $result[] = [
                'group_id'           => $g['group_id'],
                'group_name'         => $g['group_name'],
                'group_status'       => $g['group_status'],
                'group_points_day'   => $g['group_points_day'] ?? 0,
                'group_points_week'  => $g['group_points_week'] ?? 0,
                'group_points_month' => $g['group_points_month'] ?? 0,
                'group_points_year'  => $g['group_points_year'] ?? 0,
            ];
        }

        return json([
            'code' => 1,
            'msg'  => lang('obtain_ok'),
            'info' => $result,
        ]);
    }

    public function get_cards(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => lang('api/param_validate', [$validate->getError()])]);
        }

        $page  = max(1, intval($param['page'] ?? 1));
        $limit = max(1, min(100, intval($param['limit'] ?? 20)));

        $where = [];
        $where['user_id']         = $auth['user_id'];
        $where['card_use_status'] = 1;

        $order = 'card_id desc';
        $res   = (new \app\common\model\Card())->listData($where, $order, $page, $limit);

        return json([
            'code' => 1,
            'msg'  => lang('obtain_ok'),
            'info' => $res,
        ]);
    }
}
