<?php
namespace app\admin\controller;

use think\facade\Db;

class UserAccessLog extends Base
{
    private $actions = [
        'login', 'login_fail', 'register', 'play', 'down',
        'fav', 'want', 'buy', 'api_token', 'comment',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    private function checkAdminCsrf()
    {
        $post = request()->post();
        $bodyToken = isset($post['__token__']) && is_string($post['__token__'])
            ? $post['__token__'] : '';
        $headerToken = (string)request()->header('X-CSRF-Token');
        $stable = function_exists('mac_admin_csrf_token')
            ? (string)mac_admin_csrf_token() : (string)\think\facade\Session::get('admin_csrf');
        $legacy = \think\facade\Session::has('__token__') ? (string)\think\facade\Session::get('__token__') : '';

        $bodyOk = ($bodyToken !== '' && $stable !== '' && hash_equals($stable, $bodyToken))
            || ($bodyToken !== '' && $legacy !== '' && hash_equals($legacy, $bodyToken));
        $headerOk = ($headerToken !== '' && $stable !== '' && hash_equals($stable, $headerToken))
            || ($headerToken !== '' && $legacy !== '' && hash_equals($legacy, $headerToken));
        if (!$bodyOk && !$headerOk) {
            return false;
        }

        if ($legacy !== '' && (($bodyToken !== '' && hash_equals($legacy, $bodyToken))
            || ($headerToken !== '' && hash_equals($legacy, $headerToken)))) {
            \think\facade\Session::delete('__token__');
        }
        return true;
    }

    public function index()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 1 ? $this->_pagesize : intval($param['limit']);

        $where = [];
        if (isset($param['uid']) && $param['uid'] !== '') {
            $where['user_id'] = ['eq', intval($param['uid'])];
        }
        if (!empty($param['action']) && in_array($param['action'], $this->actions, true)) {
            $where['log_action'] = ['eq', $param['action']];
        }
        if (!empty($param['ip'])) {
            $ip = trim((string)$param['ip']);
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $ip) . '%';
            $where['log_ip'] = ['like', $like];
        }
        if (!empty($param['wd'])) {
            $wd = trim((string)$param['wd']);
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $wd) . '%';
            $where['user_name'] = ['like', $like];
        }
        if (isset($param['anonymized']) && in_array((string)$param['anonymized'], ['0', '1'], true)) {
            $where['log_anonymized'] = ['eq', intval($param['anonymized'])];
        }

        $order = 'log_id desc';
        $res = (new \app\common\model\UserAccessLog())->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('list', $res['list']);
        $this->assign('total', $res['total']);
        $this->assign('page', $res['page']);
        $this->assign('limit', $res['limit']);
        $this->assign('actions', $this->actions);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param', $param);
        $this->assign('title', lang('admin/useraccesslog/title'));

        return $this->fetch('admin@user_access_log/index');
    }

    public function info()
    {
        $param = input();
        $uid = intval(isset($param['uid']) ? $param['uid'] : 0);
        if ($uid < 1) {
            return $this->error(lang('param_err'));
        }

        $model = (new \app\common\model\UserAccessLog());
        $related = $model->relatedAccounts($uid, 7);
        $ips = $model->relatedIps($uid, 30);

        $banned = \app\common\util\IpBanRepository::listBanned();
        foreach ($ips as &$ipRow) {
            $ipRow['is_banned'] = in_array((string)$ipRow['log_ip'], $banned, true) ? 1 : 0;
        }
        unset($ipRow);

        $names = [];
        $ids = [];
        foreach ($related as $r) {
            $ids[] = $r['user_id'];
        }
        if (!empty($ids)) {
            $names = Db::name('user')->where('user_id', 'in', $ids)->column('user_name', 'user_id');
        }
        foreach ($related as &$r) {
            $r['user_name'] = isset($names[$r['user_id']]) ? $names[$r['user_id']] : '';
        }
        unset($r);

        $self = Db::name('user')->where('user_id', $uid)->value('user_name');

        $this->assign('uid', $uid);
        $this->assign('self_name', $self ? $self : '');
        $this->assign('related', $related);
        $this->assign('ips', $ips);
        $this->assign('title', lang('admin/useraccesslog/related_title'));

        return $this->fetch('admin@user_access_log/info');
    }

    public function del()
    {
        if (!request()->isPost()) {
            return $this->error(lang('illegal_request'));
        }
        if (!$this->checkAdminCsrf()) {
            return $this->error(lang('token_err'));
        }
        $param = input('post.');
        $all = intval(isset($param['all']) ? $param['all'] : 0);

        $where = [];
        if ($all === 1) {
            $where['log_id'] = ['gt', 0];
        } else {
            $raw = isset($param['ids']) ? $param['ids'] : '';
            if (is_array($raw)) {
                $raw = implode(',', $raw);
            }
            $ids = [];
            foreach (explode(',', (string)$raw) as $one) {
                $one = intval(trim($one));
                if ($one > 0) {
                    $ids[] = $one;
                }
            }
            if (empty($ids)) {
                return $this->error(lang('param_err'));
            }
            $where['log_id'] = ['in', $ids];
        }

        $res = (new \app\common\model\UserAccessLog())->delData($where);
        if ($res['code'] > 1) {
            return $this->error($res['msg']);
        }
        return $this->success($res['msg']);
    }

    public function ban()
    {
        if (!request()->isPost()) {
            return json(['code' => 0, 'msg' => lang('illegal_request')]);
        }
        if (!$this->checkAdminCsrf()) {
            return json(['code' => 0, 'msg' => lang('token_err')]);
        }
        $ip = trim((string)input('post.ip/s', ''));
        $res = \app\common\util\IpBanRepository::ban($ip);
        return json($res);
    }

    public function unban()
    {
        if (!request()->isPost()) {
            return json(['code' => 0, 'msg' => lang('illegal_request')]);
        }
        if (!$this->checkAdminCsrf()) {
            return json(['code' => 0, 'msg' => lang('token_err')]);
        }
        $ip = trim((string)input('post.ip/s', ''));
        $res = \app\common\util\IpBanRepository::unban($ip);
        return json($res);
    }
}
