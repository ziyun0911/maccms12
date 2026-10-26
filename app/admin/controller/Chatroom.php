<?php
namespace app\admin\controller;
use think\facade\Db;

class Chatroom extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function data()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : $param['page'];
        $param['limit'] = intval($param['limit']) < 1 ? $this->_pagesize : $param['limit'];
        $param['limit'] = min(intval($param['limit']), 100);

        $where = [];
        if (isset($param['status']) && in_array($param['status'], ['0', '1'], true)) {
            $where['chat_status'] = ['eq', $param['status']];
        }
        if (!empty($param['vod_id'])) {
            $where['vod_id'] = ['eq', $param['vod_id']];
        }
        if (!empty($param['uid'])) {
            $where['user_id'] = ['eq', $param['uid']];
        }
        if (!empty($param['report'])) {
            if ($param['report'] == 1) {
                $where['chat_report'] = ['eq', 0];
            } else {
                $where['chat_report'] = ['gt', 0];
            }
        }
        if (!empty($param['wd'])) {
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $param['wd'] = mb_substr($param['wd'], 0, 30);
            if (empty($where['vod_id']) && empty($where['user_id'])) {
                $where['chat_content'] = ['like', '%' . $param['wd'] . '%'];
            } else {
                $where['user_name|chat_content'] = ['like', '%' . $param['wd'] . '%'];
            }
        }

        $order = 'chat_id desc';
        $res = (new \app\common\model\Chatroom())->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('list', $res['list']);
        $this->assign('total', $res['total']);
        $this->assign('page', $res['page']);
        $this->assign('limit', $res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param', $param);
        $this->assign('title', lang('chatroom/title'));
        return $this->fetch('admin@chatroom/index');
    }

    public function del()
    {
        $param = input();
        $ids = $param['ids'];
        $all = $param['all'];

        if (!empty($ids) || !empty($all)) {
            $where = [];
            $where['chat_id'] = ['in', $ids];
            if ($all == 1) {
                $where['chat_id'] = ['gt', 0];
            }
            $res = (new \app\common\model\Chatroom())->delData($where);
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
        $ids = $param['ids'];
        $col = $param['col'];
        $val = $param['val'];

        if (!empty($ids) && in_array($col, ['chat_status'])) {
            $where = [];
            $where['chat_id'] = ['in', $ids];

            $res = (new \app\common\model\Chatroom())->fieldData($where, $col, $val);
            if ($res['code'] > 1) {
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }
}
