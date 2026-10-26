<?php

namespace app\api\controller;

use app\common\util\CsrfGuard;
use think\facade\Db;
use think\Request;

class Seckill extends Base
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

        $now = time();
        $where = [];
        $where['seckill_status'] = 1;
        $where['seckill_start_time'] = [['eq', 0], ['elt', $now], 'or'];
        $where['seckill_end_time'] = [['eq', 0], ['egt', $now], 'or'];

        $order = 'seckill_time desc';
        $res = (new \app\common\model\Seckill())->listData($where, $order);

        $list = [];
        foreach ($res['list'] as $row) {
            $end = intval($row['seckill_end_time']);
            $row['countdown'] = ($end > 0 && $end > $now) ? ($end - $now) : 0;
            $row['stock_remaining'] = max(0, intval($row['seckill_total']) - intval($row['seckill_sold']));
            $list[] = $row;
        }

        return json([
            'code' => 1,
            'msg'  => lang('obtain_ok'),
            'info' => [
                'list'  => $list,
                'total' => $res['total'],
            ],
        ]);
    }

    public function get_detail(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $seckillId = intval($request->param('seckill_id', 0));
        if ($seckillId < 1) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }
        $res = (new \app\common\model\Seckill())->infoData(['seckill_id' => $seckillId]);
        return json($res);
    }

    public function buy(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        if (!$request->isPost()) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $csrfErr = $this->checkCsrf();
        if ($csrfErr !== null) return json($csrfErr);

        $limited = $this->apiRateLimit('seckill_buy', $auth['user_id'], 20, 60);
        if ($limited !== true) {
            return $limited;
        }

        $seckillId = intval($request->param('seckill_id', 0));
        if ($seckillId < 1) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }
        $res = (new \app\common\model\Seckill())->buy($seckillId, $auth['user_id']);
        if (intval($res['code']) === 1) {
            $freshUser = Db::name('User')->where('user_id', $auth['user_id'])->find();
            if ($freshUser) {
                $groupList = (new \app\common\model\Group())->getCache('group_list');
                $gid = intval($freshUser['group_id']);
                if (isset($groupList[$gid])) {
                    cookie('group_id', $gid, ['expire' => 2592000]);
                    cookie('group_name', $groupList[$gid]['group_name'], ['expire' => 2592000]);
                }
            }
        }
        return json($res);
    }
}
