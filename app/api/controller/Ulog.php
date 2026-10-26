<?php

namespace app\api\controller;

use think\facade\Db;
use think\Request;

class Ulog extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
    }

    public function progress(Request $request)
    {
        if (!$request->isPost()) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('api/please_login_first')]);
        }
        $uid = intval($check['info']['user_id']);

        $param    = $request->post();
        $vodId    = intval($param['vod_id'] ?? 0);
        $sid      = intval($param['sid'] ?? 0);
        $nid      = intval($param['nid'] ?? 0);
        $point    = intval($param['point'] ?? 0);
        $duration = intval($param['duration'] ?? 0);

        if ($vodId < 1) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        if ($point < 0) {
            $point = 0;
        }
        if ($duration < 0) {
            $duration = 0;
        }
        if ($duration > 0 && $point > $duration) {
            $point = $duration;
        }

        $res = $this->saveProgress($uid, $vodId, $sid, $nid, $point, $duration, false);
        if ($res === false) {
            return json(['code' => 1004, 'msg' => lang('save_err')]);
        }

        return json([
            'code' => 1,
            'msg'  => lang('save_ok'),
            'info' => [
                'vod_id'   => $vodId,
                'sid'      => $sid,
                'nid'      => $nid,
                'point'    => $point,
                'duration' => $duration,
            ],
        ]);
    }

    public function get_progress(Request $request)
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('api/please_login_first')]);
        }
        $uid = intval($check['info']['user_id']);

        $param = $request->param();
        $vodId = intval($param['vod_id'] ?? 0);
        if ($vodId < 1) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $where = [
            'user_id'   => $uid,
            'ulog_mid'  => 1,
            'ulog_type' => 4,
            'ulog_rid'  => $vodId,
        ];
        if (isset($param['nid']) && $param['nid'] !== '') {
            $where['ulog_nid'] = intval($param['nid']);
        }

        $row = Db::name('ulog')
            ->field('ulog_id,ulog_rid,ulog_sid,ulog_nid,ulog_point,ulog_duration,ulog_time')
            ->where($where)
            ->order('ulog_time desc')
            ->find();

        if (empty($row)) {
            return json([
                'code' => 1,
                'msg'  => lang('obtain_ok'),
                'info' => null,
            ]);
        }

        return json([
            'code' => 1,
            'msg'  => lang('obtain_ok'),
            'info' => [
                'vod_id'   => intval($row['ulog_rid']),
                'sid'      => intval($row['ulog_sid']),
                'nid'      => intval($row['ulog_nid']),
                'point'    => intval($row['ulog_point']),
                'duration' => intval($row['ulog_duration']),
                'time'     => intval($row['ulog_time']),
            ],
        ]);
    }

    public function merge(Request $request)
    {
        if (!$request->isPost()) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('api/please_login_first')]);
        }
        $uid = intval($check['info']['user_id']);

        $param = $request->post();
        $list  = $param['list'] ?? '';
        if (is_string($list)) {
            $list = json_decode($list, true);
        }
        if (empty($list) || !is_array($list)) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $list = array_slice($list, 0, 50);

        $success = 0;
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $vodId    = intval($item['vod_id'] ?? 0);
            $sid      = intval($item['sid'] ?? 0);
            $nid      = intval($item['nid'] ?? 0);
            $point    = intval($item['point'] ?? 0);
            $duration = intval($item['duration'] ?? 0);

            if ($vodId < 1) {
                continue;
            }
            if ($point < 0) {
                $point = 0;
            }
            if ($duration < 0) {
                $duration = 0;
            }
            if ($duration > 0 && $point > $duration) {
                $point = $duration;
            }

            $res = $this->saveProgress($uid, $vodId, $sid, $nid, $point, $duration, true);
            if ($res !== false) {
                $success++;
            }
        }

        return json([
            'code' => 1,
            'msg'  => lang('save_ok'),
            'info' => [
                'total'   => count($list),
                'success' => $success,
            ],
        ]);
    }

    public function continue_list(Request $request)
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('api/please_login_first')]);
        }
        $uid = intval($check['info']['user_id']);

        $param = $request->param();
        $page = intval($param['page'] ?? 1);
        $limit = intval($param['limit'] ?? 12);
        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 12;
        }
        if ($limit > 30) {
            $limit = 30;
        }
        $options = [
            'hide_finished' => intval($param['hide_finished'] ?? 0) === 1,
        ];

        $res = (new \app\common\model\Ulog())->continueWatchData($uid, $page, $limit, $options);
        return json($res);
    }

    public function delete_progress(Request $request)
    {
        if (!$request->isPost()) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('api/please_login_first')]);
        }
        $uid = intval($check['info']['user_id']);

        $param = $request->post();
        $vodId = intval($param['vod_id'] ?? 0);
        if ($vodId < 1) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $where = [
            'user_id'   => $uid,
            'ulog_mid'  => 1,
            'ulog_type' => 4,
            'ulog_rid'  => $vodId,
        ];
        if (isset($param['nid']) && $param['nid'] !== '') {
            $where['ulog_nid'] = intval($param['nid']);
        }

        $count = Db::name('ulog')->where($where)->delete();
        if ($count === false) {
            return json(['code' => 1004, 'msg' => lang('del_err')]);
        }

        return json([
            'code' => 1,
            'msg'  => lang('del_ok'),
            'info' => ['deleted' => intval($count)],
        ]);
    }

    public function report_fail(Request $request)
    {
        if (!$request->isPost()) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $param = $request->post();
        $vodId = intval($param['vod_id'] ?? 0);
        $sid   = intval($param['sid'] ?? 0);
        $nid   = intval($param['nid'] ?? 0);

        if ($vodId < 1) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $ckey = 'pf_' . $vodId . '_' . $sid . '_' . $nid;
        if (cookie($ckey)) {
            return json(['code' => 1, 'msg' => lang('save_ok'), 'info' => ['throttled' => 1]]);
        }
        cookie($ckey, 1, 30);

        $data = [
            'vod_id'    => $vodId,
            'vod_sid'   => $sid,
            'vod_nid'   => $nid,
            'play_from' => (string)($param['play_from'] ?? ''),
            'vod_name'  => (string)($param['vod_name'] ?? ''),
            'switched'  => intval($param['switched'] ?? 0),
            'ip'        => $request->ip(),
        ];

        $res = (new \app\common\model\VodPlayFail())->reportFail($data);
        return json($res);
    }

    private function saveProgress($uid, $vodId, $sid, $nid, $point, $duration, $onlyAhead = false)
    {
        $now = time();

        $where = [
            'user_id'   => $uid,
            'ulog_mid'  => 1,
            'ulog_type' => 4,
            'ulog_rid'  => $vodId,
            'ulog_nid'  => $nid,
        ];

        $exist = Db::name('ulog')
            ->field('ulog_id,ulog_point')
            ->where($where)
            ->find();

        if (!empty($exist)) {
            if ($onlyAhead && $point <= intval($exist['ulog_point'])) {
                return 0;
            }
            $update = [
                'ulog_sid'      => $sid,
                'ulog_point'    => $point,
                'ulog_duration' => $duration > 0 ? $duration : Db::raw('ulog_duration'),
                'ulog_time'     => $now,
            ];
            return Db::name('ulog')->where('ulog_id', intval($exist['ulog_id']))->update($update);
        }

        $data = [
            'user_id'       => $uid,
            'ulog_mid'      => 1,
            'ulog_type'     => 4,
            'ulog_rid'      => $vodId,
            'ulog_sid'      => $sid,
            'ulog_nid'      => $nid,
            'ulog_points'   => 0,
            'ulog_point'    => $point,
            'ulog_duration' => $duration,
            'ulog_time'     => $now,
        ];
        return Db::name('ulog')->insert($data);
    }
}
