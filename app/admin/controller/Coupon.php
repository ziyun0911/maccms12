<?php
namespace app\admin\controller;
use think\facade\Db;

class Coupon extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
    }

    public function index()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 1 ? $this->_pagesize : intval($param['limit']);

        $where = [];
        if (in_array($param['status'] ?? '', ['0', '1'], true)) {
            $where['coupon_status'] = ['eq', $param['status']];
        }
        if (in_array($param['scene'] ?? '', ['all', 'recharge', 'vip'], true)) {
            $where['coupon_scene'] = ['eq', $param['scene']];
        }
        if (in_array($param['type'] ?? '', ['amount', 'discount'], true)) {
            $where['coupon_type'] = ['eq', $param['type']];
        }
        $now = time();
        if (($param['validity'] ?? '') === 'active') {
            $where['coupon_end_time'] = [['eq', 0], ['egt', $now], 'or'];
        } elseif (($param['validity'] ?? '') === 'expired') {
            $where['coupon_end_time'] = [['gt', 0], ['lt', $now]];
        }
        if (!empty($param['wd'])) {
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['coupon_name'] = ['like', '%' . $param['wd'] . '%'];
        }

        $order = 'coupon_id desc';
        $res = (new \app\common\model\Coupon())->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('list', $res['list']);
        $this->assign('total', $res['total']);
        $this->assign('page', $res['page']);
        $this->assign('limit', $res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param', $param);

        $this->assign('title', lang('admin/coupon/title'));
        return $this->fetch('coupon/index');
    }

    public function info()
    {
        $param = input();
        $info = [];
        if (!empty($param['id'])) {
            $where = [];
            $where['coupon_id'] = ['eq', intval($param['id'])];
            $res = (new \app\common\model\Coupon())->infoData($where);
            if ($res['code'] > 1) {
                return $this->error($res['msg']);
            }
            $info = $res['info'];
        }
        $info['coupon_start_time_input'] = !empty($info['coupon_start_time']) ? date('Y-m-d\TH:i:s', intval($info['coupon_start_time'])) : '';
        $info['coupon_end_time_input'] = !empty($info['coupon_end_time']) ? date('Y-m-d\TH:i:s', intval($info['coupon_end_time'])) : '';

        $group_list = (new \app\common\model\Group())->getCache('group_list');
        $vip_groups = [];
        if (is_array($group_list)) {
            foreach ($group_list as $gid => $vo) {
                if (is_array($vo) && intval($vo['group_id']) >= 3) {
                    $vip_groups[] = ['group_id' => intval($vo['group_id']), 'group_name' => (string)$vo['group_name']];
                }
            }
        }
        $target = [];
        if (!empty($info['coupon_target'])) {
            $decoded = json_decode($info['coupon_target'], true);
            $target = is_array($decoded) ? $decoded : [];
        }
        $info['coupon_target_groups'] = isset($target['groups']) && is_array($target['groups']) ? array_map('intval', $target['groups']) : [];
        $info['coupon_target_longs'] = isset($target['longs']) && is_array($target['longs']) ? $target['longs'] : [];

        $this->assign('group_list', $vip_groups);
        $this->assign('info', $info);
        $this->assign('title', lang('admin/coupon/info_title'));
        return $this->fetch('coupon/info');
    }

    public function save()
    {
        $param = input('post.');
        $param['coupon_name'] = htmlspecialchars(urldecode(trim($param['coupon_name'] ?? '')));
        if (!empty($param['coupon_start_time_input'])) {
            $param['coupon_start_time'] = strtotime($param['coupon_start_time_input']);
            unset($param['coupon_start_time_input']);
        }
        if (!empty($param['coupon_end_time_input'])) {
            $param['coupon_end_time'] = strtotime($param['coupon_end_time_input']);
            unset($param['coupon_end_time_input']);
        }
        $target = [];
        if (!empty($param['coupon_target_groups'])) {
            $target['groups'] = array_map('intval', (array)$param['coupon_target_groups']);
        }
        if (!empty($param['coupon_target_longs'])) {
            $longs = array_intersect((array)$param['coupon_target_longs'], ['day', 'week', 'month', 'year']);
            if (!empty($longs)) {
                $target['longs'] = array_values($longs);
            }
        }
        $param['coupon_target'] = empty($target) ? '' : json_encode($target, JSON_UNESCAPED_UNICODE);
        unset($param['coupon_target_groups'], $param['coupon_target_longs']);
        $param['coupon_per_user'] = 1;

        $res = (new \app\common\model\Coupon())->saveData($param);
        if ($res['code'] > 1) {
            return $this->error($res['msg']);
        }
        return $this->success($res['msg'], url('coupon/index'));
    }

    public function del()
    {
        $param = input();
        $ids = isset($param['ids']) ? $param['ids'] : '';
        if (!empty($ids)) {
            $where = [];
            $where['coupon_id'] = ['in', $ids];
            $res = (new \app\common\model\Coupon())->delData($where);
            if ($res['code'] > 1) {
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function field()
    {
        $param = input();
        if (empty($param['ids']) || !isset($param['col']) || !isset($param['val'])) {
            return $this->error(lang('param_err'));
        }
        if (!in_array($param['col'], ['coupon_status'], true) || !in_array((string)$param['val'], ['0', '1'], true)) {
            return $this->error(lang('param_err'));
        }
        $where = [];
        $where['coupon_id'] = ['in', $param['ids']];
        $res = (new \app\common\model\Coupon())->fieldData($where, $param['col'], $param['val']);
        if ($res['code'] > 1) {
            return $this->error($res['msg']);
        }
        return $this->success($res['msg']);
    }
}