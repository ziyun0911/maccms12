<?php

namespace app\api\controller;

use think\facade\Db;
use think\Request;
use app\common\util\CsrfGuard;

class Cash extends Base
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

        $where = ['user_id' => $auth['user_id']];

        if (isset($param['status']) && $param['status'] !== '') {
            $where['cash_status'] = intval($param['status']);
        }

        $order = 'cash_id desc';
        $res   = (new \app\common\model\Cash())->listData($where, $order, $page, $limit);

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

        $cash_id = intval($param['cash_id'] ?? 0);

        $where = [
            'cash_id' => $cash_id,
            'user_id' => $auth['user_id'],
        ];

        $res = (new \app\common\model\Cash())->infoData($where);
        return json($res);
    }

    public function create(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $csrfErr = $this->checkCsrf();
        if ($csrfErr !== null) return json($csrfErr);

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => '参数错误: ' . $validate->getError()]);
        }

        $param['user_id'] = $auth['user_id'];
        $res = (new \app\common\model\Cash())->saveData($param);
        return json($res);
    }

    public function del(Request $request)
    {
        $auth = $this->_checkLogin();
        if (!$auth['ok']) return $auth['response'];

        $param = $request->param();

        $validate = validate($request->controller());
        if (!$validate->scene($request->action())->check($param)) {
            return json(['code' => 1001, 'msg' => '参数错误: ' . $validate->getError()]);
        }

        $ids   = htmlspecialchars(urldecode(trim($param['ids'] ?? '')));
        $all   = $param['all'] ?? '';

        if (empty($ids) && $all != '1') {
            return json(['code' => 1001, 'msg' => '参数错误: ids 或 all=1 必须']);
        }

        $where = ['user_id' => $auth['user_id']];

        if ($all != '1') {
            $arr = array_filter(array_map('intval', explode(',', $ids)));
            if (empty($arr)) {
                return json(['code' => 1001, 'msg' => '参数错误: ids 格式不正确']);
            }
            $where['cash_id'] = ['in', array_values($arr)];
        }

        $res = (new \app\common\model\Cash())->delData($where);
        return json($res);
    }

    public function get_config(Request $request)
    {
        $user_config = $GLOBALS['config']['user'] ?? [];

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'cash_status' => intval($user_config['cash_status'] ?? 0),
                'cash_min'    => floatval($user_config['cash_min'] ?? 0),
                'cash_ratio'  => intval($user_config['cash_ratio'] ?? 1),
            ],
        ]);
    }
}
