<?php
namespace app\common\model;
use think\facade\Db;

class Follow extends Base {
    protected $name = 'user_follow';
    protected $createTime = '';
    protected $updateTime = '';
    protected $auto   = [];
    protected $insert = [];
    protected $update = [];

    public function countData($where)
    {
        $total = $this->where($where)->count();
        return $total;
    }

    public function listData($where, $order, $page = 1, $limit = 20, $start = 0, $field = '*', $name_field = 'follow_uid')
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;
        if (!is_array($where)) {
            $where = json_decode($where, true);
        }
        $limit_str = ($limit * ($page - 1) + $start) . "," . $limit;
        $total = $this->where($where)->count();
        $list = Db::name('user_follow')->field($field)->where($where)->order($order)->limit($limit_str)->select();

        if (!empty($list)) {
            $user_ids = [];
            foreach ($list as $k => $v) {
                if ($v['user_id'] > 0) {
                    $user_ids[$v['user_id']] = $v['user_id'];
                }
                if ($v['follow_uid'] > 0) {
                    $user_ids[$v['follow_uid']] = $v['follow_uid'];
                }
            }
            if (!empty($user_ids)) {
                $users = Db::name('user')
                    ->field('user_id,user_name,user_nick_name')
                    ->where('user_id', 'in', array_values($user_ids))
                    ->select();
                $user_map = [];
                foreach ($users as $u) {
                    $user_map[$u['user_id']] = $u;
                }
                $col = in_array($name_field, ['user_id', 'follow_uid'], true) ? $name_field : 'follow_uid';
                foreach ($list as $k => $v) {
                    $uid = (int)$v[$col];
                    $u = isset($user_map[$uid]) ? $user_map[$uid] : null;
                    $list[$k]['follow_user_name'] = $u ? ($u['user_nick_name'] ?: $u['user_name']) : '';
                    $list[$k]['follow_portrait'] = mac_get_user_portrait($uid);
                }
            }
        }

        return ['code' => 1, 'msg' => lang('data_list'), 'page' => $page, 'pagecount' => ceil($total / $limit), 'limit' => $limit, 'total' => $total, 'list' => $list];
    }

    public function infoData($where, $field = '*')
    {
        if (empty($where) || !is_array($where)) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        $info = $this->field($field)->where($where)->find();
        if (empty($info)) {
            return ['code' => 1002, 'msg' => lang('obtain_err')];
        }
        $info = $info->toArray();
        return ['code' => 1, 'msg' => lang('obtain_ok'), 'info' => $info];
    }

    public function saveData($data)
    {
        $user_id = intval($data['user_id']);
        $follow_uid = intval($data['follow_uid']);
        if ($user_id < 1 || $follow_uid < 1) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        if ($user_id == $follow_uid) {
            return ['code' => 1002, 'msg' => lang('follow/cannot_self')];
        }
        $validate = new \app\common\validate\Follow();
        if (!$validate->scene('save')->check(['user_id' => $user_id, 'follow_uid' => $follow_uid])) {
            return ['code' => 1001, 'msg' => $validate->getError()];
        }
        $target = Db::name('user')->where('user_id', $follow_uid)->field('user_id')->find();
        if (empty($target)) {
            return ['code' => 1003, 'msg' => lang('follow/user_not_found')];
        }
        $now = time();
        $mutual = 0;
        $is_new_follow = false;
        Db::startTrans();
        try {
            $exist = $this->where(['user_id' => $user_id, 'follow_uid' => $follow_uid])->lock(true)->find();
            $is_new_follow = empty($exist) || (int)$exist['follow_status'] !== 1;
            $reverse = $this->where(['user_id' => $follow_uid, 'follow_uid' => $user_id, 'follow_status' => 1])->lock(true)->find();
            $mutual = $reverse ? 1 : 0;
            if ($exist) {
                $this->where(['follow_id' => $exist['follow_id']])->update([
                    'follow_status' => 1,
                    'follow_mutual' => $mutual,
                    'follow_time'   => $now,
                ]);
            } else {
                $this->insert([
                    'user_id'       => $user_id,
                    'follow_uid'    => $follow_uid,
                    'follow_status' => 1,
                    'follow_mutual' => $mutual,
                    'follow_time'   => $now,
                ]);
            }
            if ($reverse) {
                $this->where(['follow_id' => $reverse['follow_id']])->update(['follow_mutual' => 1]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return ['code' => 1004, 'msg' => lang('save_err')];
        }
        $this->_fireBroadcast($user_id, $follow_uid, $mutual);

        if ($is_new_follow) {
            $this->_notifyFollow($user_id, $follow_uid);

            try {
                $from_user = Db::name('user')->where('user_id', $user_id)->field('user_name,user_nick_name')->find();
                $nick = !empty($from_user['user_nick_name']) ? $from_user['user_nick_name'] : $from_user['user_name'];
                $to_user = Db::name('user')->where('user_id', $follow_uid)->field('user_name,user_nick_name')->find();
                $to_nick = !empty($to_user['user_nick_name']) ? $to_user['user_nick_name'] : $to_user['user_name'];
                (new \app\common\model\Dynamics())->saveData([
                    'user_id'       => $user_id,
                    'dynamics_type' => 'follow',
                    'dyn_mid'       => 0,
                    'dyn_rid'       => $follow_uid,
                    'dyn_text'      => $nick . ' → ' . $to_nick,
                ]);
            } catch (\Exception $e) {
            }
        }

        return ['code' => 1, 'msg' => lang('follow/ok'), 'info' => ['mutual' => $mutual]];
    }

    public function delData($where)
    {
        $res = $this->where($where)->delete();
        if ($res === false) {
            \think\facade\Log::error('[social] Follow::delData ' . $this->getError());
            return ['code' => 1001, 'msg' => lang('del_err')];
        }
        return ['code' => 1, 'msg' => lang('del_ok')];
    }

    public function fieldData($where, $col, $val)
    {
        if (!isset($col) || !isset($val)) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        $data = [];
        $data[$col] = $val;
        $res = $this->allowField([])->where($where)->update($data);
        if ($res === false) {
            \think\facade\Log::error('[social] Follow::fieldData ' . $this->getError());
            return ['code' => 1001, 'msg' => lang('set_err')];
        }
        return ['code' => 1, 'msg' => lang('set_ok')];
    }

    public function unfollow($user_id, $follow_uid)
    {
        $user_id = intval($user_id);
        $follow_uid = intval($follow_uid);
        if ($user_id < 1 || $follow_uid < 1) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }
        $row = $this->where(['user_id' => $user_id, 'follow_uid' => $follow_uid, 'follow_status' => 1])->find();
        if (empty($row)) {
            return ['code' => 1002, 'msg' => lang('follow/not_following')];
        }
        Db::startTrans();
        try {
            $this->where(['follow_id' => $row['follow_id']])->update(['follow_status' => 0, 'follow_mutual' => 0]);
            $reverse = $this->where(['user_id' => $follow_uid, 'follow_uid' => $user_id, 'follow_status' => 1])->find();
            if ($reverse) {
                $this->where(['follow_id' => $reverse['follow_id']])->update(['follow_mutual' => 0]);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return ['code' => 1003, 'msg' => lang('del_err')];
        }
        return ['code' => 1, 'msg' => lang('follow/unfollow_ok')];
    }

    public function isFollowing($user_id, $follow_uid)
    {
        $user_id = intval($user_id);
        $follow_uid = intval($follow_uid);
        if ($user_id < 1 || $follow_uid < 1) {
            return false;
        }
        $row = $this->where(['user_id' => $user_id, 'follow_uid' => $follow_uid, 'follow_status' => 1])->find();
        return !empty($row);
    }

    public function isMutual($user_id, $follow_uid)
    {
        $user_id = intval($user_id);
        $follow_uid = intval($follow_uid);
        if ($user_id < 1 || $follow_uid < 1) {
            return false;
        }
        $row = $this->where(['user_id' => $user_id, 'follow_uid' => $follow_uid, 'follow_status' => 1, 'follow_mutual' => 1])->find();
        return !empty($row);
    }

    public function getFollowingIds($user_id)
    {
        $user_id = intval($user_id);
        if ($user_id < 1) {
            return [];
        }
        $rows = $this->where(['user_id' => $user_id, 'follow_status' => 1])->column('follow_uid');
        return $rows ? array_map('intval', $rows) : [];
    }

    public function followingCount($user_id)
    {
        return (int)$this->where(['user_id' => intval($user_id), 'follow_status' => 1])->count();
    }

    public function getFansIds($user_id)
    {
        $user_id = intval($user_id);
        if ($user_id < 1) {
            return [];
        }
        $rows = $this->where(['follow_uid' => $user_id, 'follow_status' => 1])->column('user_id');
        return $rows ? array_map('intval', $rows) : [];
    }

    public function fansCount($user_id)
    {
        return (int)$this->where(['follow_uid' => intval($user_id), 'follow_status' => 1])->count();
    }

    public function profileData($uid, $viewer_id = 0)
    {
        $uid = intval($uid);
        $viewer_id = intval($viewer_id);
        if ($uid < 1) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }

        $user = Db::name('user')
            ->field('user_id,user_name,user_nick_name,user_reg_time,user_status')
            ->where('user_id', $uid)
            ->find();
        if (empty($user)) {
            return ['code' => 1003, 'msg' => lang('follow/user_not_found')];
        }
        if (intval($user['user_status']) !== 1) {
            return ['code' => 1007, 'msg' => lang('follow/user_disabled')];
        }

        $is_self = ($viewer_id > 0 && $viewer_id === $uid) ? 1 : 0;
        $following = 0;
        $mutual = 0;
        if ($viewer_id > 0 && !$is_self) {
            $following = $this->isFollowing($viewer_id, $uid) ? 1 : 0;
            $mutual = $this->isMutual($viewer_id, $uid) ? 1 : 0;
        }

        $recent = (new \app\common\model\Dynamics())->userData($uid, 1, 3);
        $dynamics_count = isset($recent['total']) ? (int)$recent['total'] : 0;

        return [
            'code' => 1,
            'msg' => 'ok',
            'info' => [
                'user_id' => (int)$user['user_id'],
                'display_name' => $user['user_nick_name'] ? $user['user_nick_name'] : $user['user_name'],
                'user_portrait' => mac_get_user_portrait($uid),
                'user_reg_time' => (int)$user['user_reg_time'],
                'following_count' => $this->followingCount($uid),
                'fans_count' => $this->fansCount($uid),
                'dynamics_count' => $dynamics_count,
                'following' => $following,
                'mutual' => $mutual,
                'is_self' => $is_self,
                'is_login' => $viewer_id > 0 ? 1 : 0,
                'recent_dynamics' => isset($recent['list']) ? $recent['list'] : [],
            ],
        ];
    }

    private function _notifyFollow($from_uid, $to_uid)
    {
        try {
            $from_user = Db::name('user')->where('user_id', $from_uid)->field('user_name,user_nick_name')->find();
            $nick = !empty($from_user['user_nick_name']) ? $from_user['user_nick_name'] : $from_user['user_name'];
            (new \app\common\model\Notify())->send($to_uid, 'follow', lang('follow/notify_title'), lang('follow/notify_content', [$nick]), '');
        } catch (\Exception $e) {
        }
    }

    private function _fireBroadcast($user_id, $follow_uid, $mutual)
    {
        if (function_exists('hook')) {
            addon_hook('social_broadcast', [
                'kind'      => 'follow',
                'follow_uid' => $follow_uid,
                'data'      => [
                    'from_uid' => (int)$user_id,
                    'to_uid'   => (int)$follow_uid,
                    'mutual'   => (int)$mutual,
                ],
            ]);
        }
    }
}