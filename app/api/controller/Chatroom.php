<?php
namespace app\api\controller;

use think\Request;

class Chatroom extends Base
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
        $validate = new \app\api\validate\Chatroom();
        if (!$validate->scene('get_list')->check($param)) {
            return json([
                'code' => 1001,
                'msg'  => lang('param_err') . ': ' . $validate->getError(),
            ]);
        }

        $user = $this->_checkLoginForApi();
        $user_id = $user ? $user['user_id'] : null;
        $interval = $user_id ? 2 : 3;
        if (!(new \app\common\model\Chatroom())->checkReadRate($user_id, $interval)) {
            return json(['code' => 1004, 'msg' => lang('chatroom/rate_limit')]);
        }

        $vod_id = (int)$param['vod_id'];
        $after_id = isset($param['after_id']) ? (int)$param['after_id'] : 0;
        $limit = isset($param['limit']) ? min((int)$param['limit'], 100) : 50;

        $res = (new \app\common\model\Chatroom())->getNewMessages($vod_id, $after_id, $limit);

        return json($res);
    }

    public function rooms(Request $request)
    {
        $vod_id = (int)$request->param('vod_id');
        $limit = max(1, min((int)$request->param('limit', 12), 30));

        $rows = \think\facade\Db::name('chatroom')
            ->field('vod_id, COUNT(*) AS cnt, MAX(chat_time) AS last_time')
            ->where('chat_status', 1)
            ->where('vod_id', '>', 0)
            ->group('vod_id')
            ->order('last_time DESC')
            ->limit($limit)
            ->select()->toArray();

        $ids = array_map('intval', array_column($rows, 'vod_id'));
        if ($vod_id > 0) {
            $ids[] = $vod_id;
        }
        $ids = array_values(array_unique(array_filter($ids)));

        $names = [];
        if (!empty($ids)) {
            $names = \think\facade\Db::name('vod')->whereIn('vod_id', $ids)->column('vod_name', 'vod_id');
        }

        $rooms = [];
        foreach ($rows as $r) {
            $id = (int)$r['vod_id'];
            $rooms[] = [
                'vod_id'   => $id,
                'vod_name' => isset($names[$id]) ? $names[$id] : ('影片 #' . $id),
                'cnt'      => (int)$r['cnt'],
            ];
        }

        $room = null;
        if ($vod_id > 0) {
            $room = [
                'vod_id'   => $vod_id,
                'vod_name' => isset($names[$vod_id]) ? $names[$vod_id] : ('影片 #' . $vod_id),
                'cnt'      => (int)\think\facade\Db::name('chatroom')
                    ->where('chat_status', 1)
                    ->where('vod_id', $vod_id)
                    ->count(),
            ];
        }

        return json(['code' => 1, 'msg' => 'ok', 'info' => ['rooms' => $rooms, 'room' => $room]]);
    }

    public function send(Request $request)
    {
        $user = $this->_checkLoginForApi();
        if (!$user) {
            return json(['code' => 1010, 'msg' => lang('chatroom/login_required')]);
        }

        $param = $request->param();
        $validate = new \app\api\validate\Chatroom();
        if (!$validate->scene('send')->check($param)) {
            return json([
                'code' => 1001,
                'msg'  => lang('param_err') . ': ' . $validate->getError(),
            ]);
        }

        $vod_id = (int)$param['vod_id'];
        $content = trim($param['content']);

        if (empty($content)) {
            return json(['code' => 1002, 'msg' => lang('chatroom/content_empty')]);
        }

        $blacks = config('blacks');
        if (!empty($blacks['black_ip_list']) && is_array($blacks['black_ip_list'])) {
            $ip = mac_get_client_ip();
            if (in_array($ip, $blacks['black_ip_list'])) {
                return json(['code' => 1005, 'msg' => lang('chatroom/ip_banned')]);
            }
        }

        if (!(new \app\common\model\Chatroom())->checkSendRate($user['user_id'], $vod_id, 3)) {
            return json(['code' => 1003, 'msg' => lang('chatroom/rate_limit')]);
        }

        $data = [];
        $data['vod_id'] = $vod_id;
        $data['user_id'] = $user['user_id'];
        $data['user_name'] = !empty($user['user_nick_name']) ? $user['user_nick_name'] : $user['user_name'];
        $data['chat_content'] = $content;

        $res = (new \app\common\model\Chatroom())->saveData($data);

        return json($res);
    }

    public function report(Request $request)
    {
        $user = $this->_checkLoginForApi();
        if (!$user) {
            return json(['code' => 1010, 'msg' => lang('chatroom/login_required')]);
        }

        $param = $request->param();
        $validate = new \app\api\validate\Chatroom();
        if (!$validate->scene('report')->check($param)) {
            return json([
                'code' => 1001,
                'msg'  => lang('param_err') . ': ' . $validate->getError(),
            ]);
        }

        $chat_id = (int)$param['chat_id'];

        $where = [];
        $where['chat_id'] = ['eq', $chat_id];
        $info = (new \app\common\model\Chatroom())->infoData($where);
        if ($info['code'] > 1) {
            return json(['code' => 1002, 'msg' => lang('chatroom/msg_not_found')]);
        }

        if (isset($info['info']['chat_status']) && $info['info']['chat_status'] != 1) {
            return json(['code' => 1003, 'msg' => lang('chatroom/disabled')]);
        }

        $cache_key = 'chatroom_report_' . $user['user_id'] . '_' . $chat_id;
        if (\think\facade\Cache::get($cache_key)) {
            return json(['code' => 1, 'msg' => lang('chatroom/already_reported')]);
        }
        \think\facade\Cache::set($cache_key, 1, 86400);

        \think\facade\Db::name('chatroom')->where('chat_id', $chat_id)->setInc('chat_report', 1);

        return json(['code' => 1, 'msg' => lang('chatroom/report_ok')]);
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
