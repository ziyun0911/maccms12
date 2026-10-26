<?php
namespace app\api\controller;
use think\Request;

class Task extends Base
{
    use PublicApi;

    public function __construct()
    {
        parent::__construct();
        $this->check_config();
    }

    public function get_task_list(Request $request)
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('task/login_required')]);
        }
        $user_id = intval($check['info']['user_id']);
        $user_info = $check['info'];

        $task_status = (new \app\common\model\TaskLog())->getUserTaskStatus($user_id, $user_info);
        $sign_info = (new \app\common\model\SignLog())->getSignInfo($user_id);

        return json([
            'code' => 1,
            'msg' => lang('obtain_ok'),
            'info' => [
                'daily_tasks' => $task_status['daily_tasks'],
                'newbie_tasks' => $task_status['newbie_tasks'],
                'sign_info' => $sign_info,
                'today_earned' => $task_status['today_earned'],
                'user_points' => intval($user_info['user_points']),
            ],
        ]);
    }

    public function daily_sign(Request $request)
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('task/login_required')]);
        }
        $user_id = intval($check['info']['user_id']);
        $res = (new \app\common\model\SignLog())->doSign($user_id);
        return json($res);
    }

    public function get_sign_info(Request $request)
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('task/login_required')]);
        }
        $user_id = intval($check['info']['user_id']);
        $sign_info = (new \app\common\model\SignLog())->getSignInfo($user_id);

        return json([
            'code' => 1,
            'msg' => lang('obtain_ok'),
            'info' => $sign_info,
        ]);
    }

    public function claim_sign_milestone(Request $request)
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('task/login_required')]);
        }
        $user_id = intval($check['info']['user_id']);
        $param = $request->param();

        $milestone_id = intval($param['milestone_id']);
        if ($milestone_id <= 0) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $sign_info = (new \app\common\model\SignLog())->getSignInfo($user_id);
        $serial_days = $sign_info['serial_days'];

        $res = (new \app\common\model\SignMilestone())->claimMilestone($user_id, $milestone_id, $serial_days);
        return json($res);
    }

    public function claim_reward(Request $request)
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('task/login_required')]);
        }
        $user_id = intval($check['info']['user_id']);
        $param = $request->param();

        $task_id = intval($param['task_id']);
        if ($task_id <= 0) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $res = (new \app\common\model\TaskLog())->claimReward($user_id, $task_id);
        return json($res);
    }

    public function report_progress(Request $request)
    {
        $check = (new \app\common\model\User())->checkLogin();
        if ($check['code'] > 1) {
            return json(['code' => 1401, 'msg' => lang('task/login_required')]);
        }
        $user_id = intval($check['info']['user_id']);
        $param = $request->param();

        $task_action = trim($param['task_action']);
        $allowed = ['watch_vod', 'share_vod', 'post_comment'];
        if (!in_array($task_action, $allowed)) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $res = (new \app\common\model\TaskLog())->addProgress($user_id, $task_action, 1);
        return json($res);
    }
}
