<?php
namespace app\common\model;
use think\facade\Db;

class SignLog extends Base {
    protected $name = 'sign_log';
    protected $createTime = '';
    protected $updateTime = '';
    protected $auto   = [];
    protected $insert = [];
    protected $update = [];

    public function doSign($user_id)
    {
        $today = date('Y-m-d');
        $exists = Db::name('SignLog')->where(['user_id' => $user_id, 'sign_date' => $today])->find();
        if ($exists) {
            return ['code' => 1001, 'msg' => lang('task/already_signed')];
        }

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $yesterday_log = Db::name('SignLog')->where(['user_id' => $user_id, 'sign_date' => $yesterday])->find();
        $serial_days = $yesterday_log ? (int)$yesterday_log['sign_serial_days'] + 1 : 1;

        $task = Db::name('Task')->where(['task_action' => 'daily_sign', 'task_status' => 1])->find();
        $base_points = $task ? (int)$task['task_points'] : 5;

        // ===== 全新签到加成体系：读取启用的加成规则（配置表驱动）=====
        $bonus_rules = Db::name('SignBonus')->where(['bonus_status' => 1])
            ->order('bonus_type asc, bonus_days asc')->select();
        $serial_rules = [];   // 连续签到加成：bonus_days => 规则
        $month_rules  = [];   // 月度累计奖：bonus_days => 规则
        $full_rule    = null; // 月度满勤奖
        foreach ($bonus_rules as $br) {
            if ((int)$br['bonus_type'] === 1) {
                $serial_rules[(int)$br['bonus_days']] = $br;
            } elseif ((int)$br['bonus_type'] === 2) {
                $month_rules[(int)$br['bonus_days']] = $br;
            } elseif ((int)$br['bonus_type'] === 3) {
                $full_rule = $br;
            }
        }

        // 当月签到次数（含今日，今日尚未插入故 +1）
        $month_start = date('Y-m-01');
        $month_end = date('Y-m-t');
        $month_signs = (int)Db::name('SignLog')->where([
            'user_id' => $user_id,
            'sign_date' => ['between', [$month_start, $month_end]],
        ])->count() + 1;
        $month_days = (int)date('t'); // 当月总天数
        $month_period = date('Y-m');  // 月度发放周期
        $day_period = $today;         // 连续加成的发放周期（按天，天然唯一）

        // 计算本次应发放的加成：[规则, 周期, 金币, 说明] 列表
        $bonus_grants = [];
        // 1) 连续签到加成：连续天数恰好命中档位时发放
        if (isset($serial_rules[$serial_days])) {
            $r = $serial_rules[$serial_days];
            $bonus_grants[] = ['rule' => $r, 'period' => $day_period,
                'points' => (int)$r['bonus_points'], 'name' => (string)$r['bonus_name']];
        }
        // 2) 月度累计奖：当月签到次数达到的所有档位均发放（<= 补发，唯一键防重复）
        foreach ($month_rules as $days => $r) {
            if ($month_signs >= $days) {
                $bonus_grants[] = ['rule' => $r, 'period' => $month_period,
                    'points' => (int)$r['bonus_points'], 'name' => (string)$r['bonus_name']];
            }
        }
        // 3) 月度满勤奖：当月签到次数 = 当月天数时发放
        if ($full_rule && $month_signs >= $month_days) {
            $bonus_grants[] = ['rule' => $full_rule, 'period' => $month_period,
                'points' => (int)$full_rule['bonus_points'], 'name' => (string)$full_rule['bonus_name']];
        }

        // 过滤掉已发放过的（唯一键防重复的兜底，避免事务内 insert 冲突）
        $bonus_total = 0;
        $grant_points = 0;
        $bonus_names = [];
        if (!empty($bonus_grants)) {
            foreach ($bonus_grants as $g) {
                $exists = Db::name('SignBonusLog')->where([
                    'user_id' => $user_id,
                    'bonus_id' => (int)$g['rule']['bonus_id'],
                    'bonus_period' => $g['period'],
                ])->find();
                if (!$exists) {
                    $bonus_total += $g['points'];
                    $bonus_names[] = $g['name'] . '+' . $g['points'];
                }
            }
        }

        $total_points = $base_points + $bonus_total;

        Db::startTrans();
        try {
            Db::name('SignLog')->insert([
                'user_id' => $user_id,
                'sign_date' => $today,
                'sign_time' => time(),
                'sign_points' => $total_points,
                'sign_serial_days' => $serial_days,
            ]);

            $inc = Db::name('User')->where('user_id', $user_id)->setInc('user_points', $total_points);
            if ($inc === false) {
                throw new \Exception('user_points');
            }

            $taskName = $task ? (string)$task['task_name'] : lang('task/daily_sign_task');
            $plog = [
                'user_id' => $user_id,
                'plog_type' => 11,
                'plog_points' => $total_points,
                'plog_remarks' => lang('task/reward_log', [$taskName, $total_points]),
            ];
            $plogRes = (new \app\common\model\Plog())->saveData($plog);
            if (empty($plogRes['code']) || (int)$plogRes['code'] !== 1) {
                throw new \Exception('plog');
            }

            // 写入本次加成发放记录（唯一键保证同一周期不重复发放）
            if (!empty($bonus_grants)) {
                foreach ($bonus_grants as $g) {
                    $exists = Db::name('SignBonusLog')->where([
                        'user_id' => $user_id,
                        'bonus_id' => (int)$g['rule']['bonus_id'],
                        'bonus_period' => $g['period'],
                    ])->find();
                    if ($exists) {
                        continue;
                    }
                    Db::name('SignBonusLog')->insert([
                        'user_id' => $user_id,
                        'bonus_id' => (int)$g['rule']['bonus_id'],
                        'bonus_type' => (int)$g['rule']['bonus_type'],
                        'bonus_period' => $g['period'],
                        'log_points' => $g['points'],
                        'log_time' => time(),
                    ]);
                }
            }

            if ($task) {
                (new \app\common\model\TaskLog())->addProgress($user_id, 'daily_sign', 1);
                $log = Db::name('TaskLog')->where([
                    'user_id' => $user_id,
                    'task_id' => $task['task_id'],
                    'log_date' => $today,
                ])->find();
                if ($log && (int)$log['log_status'] === 1) {
                    Db::name('TaskLog')->where('log_id', $log['log_id'])->update([
                        'log_status' => 2,
                        'log_points' => (int)$task['task_points'],
                        'log_claim_time' => time(),
                    ]);
                }
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return ['code' => 1002, 'msg' => lang('save_err')];
        }

        $milestone_info = (new \app\common\model\SignMilestone())->getMilestonesWithStatus($user_id, $serial_days);
        $claimable_count = 0;
        foreach ($milestone_info['milestones'] as $m) {
            if ($m['status'] == 1) {
                $claimable_count++;
            }
        }

        return [
            'code' => 1,
            'msg' => lang('task/sign_ok'),
            'info' => [
                'points' => $total_points,
                'base_points' => $base_points,
                'bonus_points' => $bonus_total,
                'bonus_names' => $bonus_names,
                'serial_days' => $serial_days,
                'claimable_milestones' => $claimable_count,
            ]
        ];
    }

    public function getSignInfo($user_id)
    {
        $today = date('Y-m-d');
        $today_log = Db::name('SignLog')->where(['user_id' => $user_id, 'sign_date' => $today])->find();
        $is_signed = !empty($today_log);

        $serial_days = 0;
        if ($is_signed) {
            $serial_days = (int)$today_log['sign_serial_days'];
        } else {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $yesterday_log = Db::name('SignLog')->where(['user_id' => $user_id, 'sign_date' => $yesterday])->find();
            $serial_days = $yesterday_log ? (int)$yesterday_log['sign_serial_days'] : 0;
        }

        $month_start = date('Y-m-01');
        $month_end = date('Y-m-t');
        $month_logs = Db::name('SignLog')->where([
            'user_id' => $user_id,
            'sign_date' => ['between', [$month_start, $month_end]],
        ])->order('sign_date asc')->select();

        $month_dates = [];
        $month_total_points = 0;
        foreach ($month_logs as $l) {
            $month_dates[] = $l['sign_date'];
            $month_total_points += (int)$l['sign_points'];
        }

        $total_signs = (int)Db::name('SignLog')->where('user_id', $user_id)->count();

        // 里程碑按「累计签到次数」判定（原为连续天数 serial_days）
        $milestone_info = (new \app\common\model\SignMilestone())->getMilestonesWithStatus($user_id, $total_signs);

        // ===== 加成体系进度（供前端展示）=====
        $bonus_rules = Db::name('SignBonus')->where(['bonus_status' => 1])
            ->order('bonus_type asc, bonus_days asc')->select();
        $serial_rules = $month_rules = [];
        $full_rule = null;
        $rule_list = [];
        foreach ($bonus_rules as $br) {
            $item = [
                'bonus_id' => (int)$br['bonus_id'],
                'bonus_type' => (int)$br['bonus_type'],
                'bonus_days' => (int)$br['bonus_days'],
                'bonus_points' => (int)$br['bonus_points'],
                'bonus_name' => (string)$br['bonus_name'],
            ];
            $rule_list[] = $item;
            if ((int)$br['bonus_type'] === 1) $serial_rules[(int)$br['bonus_days']] = $br;
            elseif ((int)$br['bonus_type'] === 2) $month_rules[(int)$br['bonus_days']] = $br;
            elseif ((int)$br['bonus_type'] === 3) $full_rule = $br;
        }
        // 已发放的加成（本月 + 最近连续），用于前端标记
        $granted_ids = Db::name('SignBonusLog')->where('user_id', $user_id)
            ->where('bonus_period', 'like', date('Y-m') . '%')
            ->column('bonus_id');
        $granted_map = array_flip($granted_ids);

        // 下一档连续加成（连续天数之后最近的一档）
        $next_serial = null;
        foreach ($serial_rules as $days => $r) {
            if ($days > $serial_days) {
                $next_serial = ['days' => $days, 'points' => (int)$r['bonus_points']];
                break;
            }
        }

        return [
            'is_signed_today' => $is_signed,
            'serial_days' => $serial_days,
            'total_signs' => $total_signs,
            'month_signs' => count($month_dates),
            'month_dates' => $month_dates,
            'month_total_points' => $month_total_points,
            'milestones' => $milestone_info['milestones'],
            'next_milestone' => $milestone_info['next_milestone'],
            'bonus' => [
                'rules' => $rule_list,
                'granted' => array_keys($granted_map),
                'next_serial' => $next_serial,
                'month_days' => (int)date('t'),
            ],
        ];
    }

    public function listData($where, $order, $page = 1, $limit = 20, $start = 0)
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;
        if (!is_array($where)) {
            $where = json_decode($where, true);
        }
        $limit_str = ($limit * ($page - 1) + $start) . "," . $limit;
        $total = $this->where($where)->count();
        $list = Db::name('SignLog')->where($where)->order($order)->limit($limit_str)->select();
        return ['code' => 1, 'msg' => lang('data_list'), 'page' => $page, 'pagecount' => ceil($total / $limit), 'limit' => $limit, 'total' => $total, 'list' => $list];
    }

    public function delData($where)
    {
        $res = $this->where($where)->delete();
        if ($res === false) {
            return ['code' => 1001, 'msg' => lang('del_err') . '：' . $this->getError()];
        }
        return ['code' => 1, 'msg' => lang('del_ok')];
    }
}
