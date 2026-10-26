<?php
namespace app\api\controller;

use think\Request;

class Danmaku extends Base
{
    use PublicApi;

    public function __construct()
    {
        parent::__construct();
        $this->check_config();
    }

    public function index()
    {
    }

    public function get_list(Request $request)
    {
        $param = $request->param();
        $validate = new \app\api\validate\Danmaku();
        if (!$validate->scene('get_list')->check($param)) {
            return json([
                'code' => 1001,
                'msg'  => lang('param_err') . ': ' . $validate->getError(),
            ]);
        }

        $vod_id = (int)$param['vod_id'];
        $sid = (int)$param['sid'];
        $nid = (int)$param['nid'];
        $limit = isset($param['limit']) ? min((int)$param['limit'], 2000) : 1000;

        $res = (new \app\common\model\Danmaku())->getEpisodeDanmaku($vod_id, $sid, $nid, $limit);

        return json($res);
    }

    public function dplayer(Request $request)
    {
        if ($request->isGet()) {
            $id = $request->param('id', '');
            $parts = explode('-', $id);
            if (count($parts) < 3) {
                return json(['code' => 1, 'data' => []]);
            }

            $vod_id = (int)$parts[0];
            $sid = (int)$parts[1];
            $nid = (int)$parts[2];

            $res = (new \app\common\model\Danmaku())->getDplayerDanmaku($vod_id, $sid, $nid);
            return json($res);
        }

        if ($request->isPost()) {
            $user = $this->_checkLoginForApi();
            if (!$user) {
                return json(['code' => 1, 'msg' => lang('danmaku/login_required')]);
            }

            $param = $request->param();
            $id = isset($param['id']) ? $param['id'] : '';
            $parts = explode('-', $id);
            if (count($parts) < 3) {
                return json(['code' => 1, 'msg' => lang('danmaku/param_err')]);
            }

            $vod_id = (int)$parts[0];
            $sid = (int)$parts[1];
            $nid = (int)$parts[2];

            $vod_check = $this->_checkVodAvailable($vod_id);
            if ($vod_check !== true) {
                return json(['code' => 1, 'msg' => $vod_check['msg']]);
            }

            $preCheck = $this->_preSendCheck($user);
            if ($preCheck !== true) {
                return json(['code' => 1, 'msg' => $preCheck['msg']]);
            }

            $data = [];
            $data['vod_id'] = $vod_id;
            $data['vod_sid'] = $sid;
            $data['vod_nid'] = $nid;
            $data['user_id'] = $user['user_id'];
            $data['user_name'] = !empty($user['user_nick_name']) ? $user['user_nick_name'] : $user['user_name'];
            $data['danmaku_time'] = isset($param['time']) ? (float)$param['time'] : 0;
            $data['danmaku_type'] = isset($param['type']) ? (int)$param['type'] : 0;
            $data['danmaku_color'] = isset($param['color']) ? trim($param['color']) : '#FFFFFF';
            $data['danmaku_text'] = isset($param['text']) ? trim($param['text']) : '';

            if (empty($data['danmaku_text'])) {
                return json(['code' => 1, 'msg' => lang('danmaku/content_empty')]);
            }

            $res = (new \app\common\model\Danmaku())->saveData($data);
            return json(['code' => ($res['code'] == 1) ? 0 : 1, 'msg' => $res['msg']]);
        }

        return json(['code' => 1, 'msg' => lang('param_err')]);
    }

    public function send(Request $request)
    {
        $user = $this->_checkLoginForApi();
        if (!$user) {
            return json(['code' => 1010, 'msg' => lang('danmaku/login_required')]);
        }

        $param = $request->param();
        $validate = new \app\api\validate\Danmaku();
        if (!$validate->scene('send')->check($param)) {
            return json([
                'code' => 1001,
                'msg'  => lang('param_err') . ': ' . $validate->getError(),
            ]);
        }

        $vod_check = $this->_checkVodAvailable((int)$param['vod_id']);
        if ($vod_check !== true) {
            return json($vod_check);
        }

        $preCheck = $this->_preSendCheck($user);
        if ($preCheck !== true) {
            return json($preCheck);
        }

        $data = [];
        $data['vod_id'] = (int)$param['vod_id'];
        $data['vod_sid'] = (int)$param['sid'];
        $data['vod_nid'] = (int)$param['nid'];
        $data['user_id'] = $user['user_id'];
        $data['user_name'] = !empty($user['user_nick_name']) ? $user['user_nick_name'] : $user['user_name'];
        $data['danmaku_time'] = (float)$param['time'];
        $data['danmaku_type'] = isset($param['type']) ? (int)$param['type'] : 0;
        $data['danmaku_color'] = isset($param['color']) ? trim($param['color']) : '#FFFFFF';
        $data['danmaku_text'] = trim($param['text']);

        if (empty($data['danmaku_text'])) {
            return json(['code' => 1002, 'msg' => lang('danmaku/content_empty')]);
        }

        if (!in_array($data['danmaku_type'], [0, 1, 2])) {
            $data['danmaku_type'] = 0;
        }

        $res = (new \app\common\model\Danmaku())->saveData($data);

        return json($res);
    }

    public function report(Request $request)
    {
        $user = $this->_checkLoginForApi();
        if (!$user) {
            return json(['code' => 1010, 'msg' => lang('danmaku/login_required')]);
        }

        $param = $request->param();
        $validate = new \app\api\validate\Danmaku();
        if (!$validate->scene('report')->check($param)) {
            return json([
                'code' => 1001,
                'msg'  => lang('param_err') . ': ' . $validate->getError(),
            ]);
        }

        $danmaku_id = (int)$param['danmaku_id'];

        $where = [];
        $where['danmaku_id'] = ['eq', $danmaku_id];
        $info = (new \app\common\model\Danmaku())->infoData($where);
        if ($info['code'] > 1) {
            return json(['code' => 1002, 'msg' => lang('danmaku/not_found')]);
        }

        if (isset($info['info']['danmaku_status']) && $info['info']['danmaku_status'] != 1) {
            return json(['code' => 1003, 'msg' => lang('danmaku/disabled')]);
        }

        $cache_key = 'danmaku_report_' . $user['user_id'] . '_' . $danmaku_id;
        if (\think\facade\Cache::get($cache_key)) {
            return json(['code' => 1, 'msg' => lang('danmaku/already_reported')]);
        }
        \think\facade\Cache::set($cache_key, 1, 86400);

        \think\facade\Db::name('danmaku')->where('danmaku_id', $danmaku_id)->setInc('danmaku_report', 1);

        return json(['code' => 1, 'msg' => lang('danmaku/report_ok')]);
    }

    private function _preSendCheck($user)
    {
        $blacks = config('blacks');
        if (!empty($blacks['black_ip_list']) && is_array($blacks['black_ip_list'])) {
            $ip = mac_get_client_ip();
            if (in_array($ip, $blacks['black_ip_list'])) {
                return ['code' => 1005, 'msg' => lang('danmaku/ip_banned')];
            }
        }

        if (!(new \app\common\model\Danmaku())->checkSendRate($user['user_id'], 5)) {
            return ['code' => 1003, 'msg' => lang('danmaku/rate_limit')];
        }

        return true;
    }

    private function _checkVodAvailable($vod_id)
    {
        $vod = \think\facade\Db::name('vod')->field('vod_id,vod_status')->where('vod_id', (int)$vod_id)->find();
        if (empty($vod)) {
            return ['code' => 1004, 'msg' => lang('danmaku/vod_not_found')];
        }
        if ($vod['vod_status'] != 1) {
            return ['code' => 1006, 'msg' => lang('danmaku/vod_not_available')];
        }
        return true;
    }

    private function _checkLoginForApi()
    {
        $res = (new \app\common\model\User())->checkLogin();
        if ($res['code'] == 1) {
            return $res['info'];
        }
        return false;
    }
}
