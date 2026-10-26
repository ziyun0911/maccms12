<?php
namespace app\common\model;
use think\facade\Db;

class Chatroom extends Base {
    protected $name = 'chatroom';

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

    public function listData($where, $order, $page = 1, $limit = 50, $start = 0, $field = '*')
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 50;
        $start = $start ? (int)$start : 0;

        if (!is_array($where)) {
            $where = json_decode($where, true);
        }

        $limit_str = ($limit * ($page - 1) + $start) . "," . $limit;
        $total = $this->where($where)->count();
        $list = Db::name('Chatroom')->field($field)->where($where)->order($order)->limit($limit_str)->select();

        return ['code' => 1, 'msg' => lang('data_list'), 'page' => $page, 'pagecount' => ceil($total / $limit), 'limit' => $limit, 'total' => $total, 'list' => $list];
    }

    public function getNewMessages($vod_id, $after_id = 0, $limit = 50)
    {
        $vod_id = (int)$vod_id;
        $after_id = (int)$after_id;
        $limit = (int)$limit;

        $cache_flag = isset($GLOBALS['config']['app']['cache_flag']) ? $GLOBALS['config']['app']['cache_flag'] : 'mac';
        $use_cache = ($after_id == 0);
        $cache_key = $cache_flag . '_chatroom_msg_' . $vod_id . '_' . $after_id . '_' . $limit;
        if ($use_cache) {
            $cached = \think\facade\Cache::get($cache_key);
            if ($cached !== false && $cached !== null) {
                return $cached;
            }
        }

        $where = [];
        $where['vod_id'] = ['eq', $vod_id];
        $where['chat_status'] = ['eq', 1];

        if ($after_id > 0) {
            $where['chat_id'] = ['gt', $after_id];

            $list = Db::name('Chatroom')
                ->field('chat_id,vod_id,user_id,user_name,chat_content,chat_time')
                ->where($where)
                ->order('chat_id ASC')
                ->limit($limit)
                ->select()
                ->toArray();
        } else {
            $list = Db::name('Chatroom')
                ->field('chat_id,vod_id,user_id,user_name,chat_content,chat_time')
                ->where($where)
                ->order('chat_id DESC')
                ->limit($limit)
                ->select()
                ->toArray();
            if (!empty($list)) {
                $list = array_reverse($list);
            }
        }

        $last_id = 0;
        if (!empty($list)) {
            $last_id = $list[count($list) - 1]['chat_id'];
            foreach ($list as &$v) {
                $v['user_portrait'] = mac_get_user_portrait($v['user_id']);
                $v['chat_time_text'] = date('H:i:s', $v['chat_time']);
            }
            unset($v);
        }

        $result = ['code' => 1, 'msg' => 'ok', 'info' => [
            'rows'    => $list,
            'last_id' => $last_id,
            'total'   => count($list),
        ]];

        if ($use_cache) {
            \think\facade\Cache::set($cache_key, $result, 5);
        }

        return $result;
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
        if (isset($data['chat_content'])) {
            $data['chat_content'] = mac_filter_xss($data['chat_content']);
            if (mb_strlen($data['chat_content'], 'UTF-8') > 500) {
                $data['chat_content'] = mb_substr($data['chat_content'], 0, 500, 'UTF-8');
            }
        }

        $blacks = config('blacks');
        $content = isset($data['chat_content']) ? $data['chat_content'] : '';
        if (!empty($blacks['black_keyword_list']) && is_array($blacks['black_keyword_list'])) {
            foreach ($blacks['black_keyword_list'] as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword) && strpos($content, $keyword) !== false) {
                    return ['code' => 1003, 'msg' => lang('content_contain_sensitive')];
                }
            }
        }

        if (!empty($data['chat_id'])) {
            $where = [];
            $where['chat_id'] = ['eq', $data['chat_id']];
            $res = $this->allowField([])->where($where)->update($data);
        } else {
            $data['chat_time'] = time();
            $data['chat_ip'] = mac_get_ip_long();
            $res = $this->allowField([])->insertGetId($data);
            if (false !== $res && function_exists('hook')) {
                addon_hook('social_broadcast', [
                    'kind'   => 'chat',
                    'vod_id' => (int)$data['vod_id'],
                    'data'   => [
                        'chat_id'        => (int)$res,
                        'vod_id'         => (int)$data['vod_id'],
                        'user_id'        => (int)$data['user_id'],
                        'user_name'      => $data['user_name'],
                        'user_portrait'  => mac_get_user_portrait($data['user_id']),
                        'chat_content'   => $data['chat_content'],
                        'chat_time'      => (int)$data['chat_time'],
                        'chat_time_text' => date('H:i:s', $data['chat_time']),
                    ],
                ]);

                if (!empty($data['user_id'])) {
                    try {
                        (new \app\common\model\Dynamics())->saveData([
                            'user_id'       => intval($data['user_id']),
                            'dynamics_type' => 'chat',
                            'dyn_mid'       => 1,
                            'dyn_rid'       => intval($data['vod_id']),
                            'dyn_text'      => mb_substr($data['chat_content'], 0, 200, 'UTF-8'),
                        ]);
                    } catch (\Exception $e) {
                        \think\facade\Log::error('Chatroom dynamics mirror failed user=' . intval($data['user_id'])
                            . ' vod=' . intval($data['vod_id']) . ' err=' . $e->getMessage());
                    }
                }
            }
        }

        if (false === $res) {
            return ['code' => 1002, 'msg' => lang('save_err') . '：' . $this->getError()];
        }
        return ['code' => 1, 'msg' => lang('save_ok')];
    }

    public function delData($where)
    {
        $this->_clearChatroomCache($where);
        $res = $this->where($where)->delete();
        if ($res === false) {
            return ['code' => 1001, 'msg' => lang('del_err') . '：' . $this->getError()];
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
            return ['code' => 1001, 'msg' => lang('set_err') . '：' . $this->getError()];
        }
        if ($col === 'chat_status') {
            $this->_clearChatroomCache($where);
        }
        return ['code' => 1, 'msg' => lang('set_ok')];
    }

    private function _clearChatroomCache($where)
    {
        $flag = isset($GLOBALS['config']['app']['cache_flag']) ? $GLOBALS['config']['app']['cache_flag'] : 'mac';
        $rows = Db::name('chatroom')->where($where)->field('DISTINCT vod_id')->select();
        if (!empty($rows)) {
            foreach ($rows as $r) {
                $cache_key = $flag . '_chatroom_msg_' . $r['vod_id'] . '_0_50';
                \think\facade\Cache::delete($cache_key);
            }
        }
    }

    public function checkReadRate($user_id = null, $interval = 2)
    {
        if ($user_id) {
            $cache_key = 'chatroom_read_rate_u_' . $user_id;
        } else {
            $cache_key = 'chatroom_read_rate_ip_' . mac_get_client_ip();
        }
        return $this->_atomicRateCheck($cache_key, $interval);
    }

    public function checkSendRate($user_id, $vod_id, $interval = 3)
    {
        $cache_key = 'chatroom_rate_' . $user_id . '_' . $vod_id;
        return $this->_atomicRateCheck($cache_key, $interval);
    }

    private function _atomicRateCheck($cache_key, $interval)
    {
        try {
            $handler = \think\facade\Cache::store()->handler();
            if ($handler instanceof \Redis) {
                $ok = $handler->set($cache_key, 1, ['NX', 'EX' => $interval]);
                return (bool)$ok;
            }
        } catch (\Exception $e) {
        }
        if (\think\facade\Cache::has($cache_key)) {
            return false;
        }
        \think\facade\Cache::set($cache_key, 1, (int)$interval);
        return true;
    }
}
