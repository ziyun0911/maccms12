<?php
namespace app\common\model;
use think\facade\Db;

class Danmaku extends Base {
    protected $name = 'danmaku';

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

    public function listData($where, $order, $page = 1, $limit = 20, $start = 0, $field = '*')
    {
        $page = $page > 0 ? (int)$page : 1;
        $limit = $limit ? (int)$limit : 20;
        $start = $start ? (int)$start : 0;

        if (!is_array($where)) {
            $where = json_decode($where, true);
        }

        $limit_str = ($limit * ($page - 1) + $start) . "," . $limit;
        $total = $this->where($where)->count();
        $list = Db::name('Danmaku')->field($field)->where($where)->order($order)->limit($limit_str)->select();

        return ['code' => 1, 'msg' => lang('data_list'), 'page' => $page, 'pagecount' => ceil($total / $limit), 'limit' => $limit, 'total' => $total, 'list' => $list];
    }

    public function getEpisodeDanmaku($vod_id, $sid, $nid, $limit = 1000)
    {
        $cache_key = $GLOBALS['config']['app']['cache_flag'] . '_danmaku_' . $vod_id . '_' . $sid . '_' . $nid;
        $cached = \think\facade\Cache::get($cache_key);
        if ($cached) {
            return $cached;
        }

        $where = [];
        $where['vod_id'] = ['eq', (int)$vod_id];
        $where['vod_sid'] = ['eq', (int)$sid];
        $where['vod_nid'] = ['eq', (int)$nid];
        $where['danmaku_status'] = ['eq', 1];

        $list = Db::name('Danmaku')
            ->field('danmaku_id,danmaku_time,danmaku_type,danmaku_color,danmaku_text,user_name,danmaku_send_time')
            ->where($where)
            ->order('danmaku_time ASC')
            ->limit($limit)
            ->select();

        $result = ['code' => 1, 'msg' => 'ok', 'info' => [
            'total' => count($list),
            'rows'  => $list,
        ]];

        \think\facade\Cache::set($cache_key, $result, 300);

        return $result;
    }

    public function getDplayerDanmaku($vod_id, $sid, $nid)
    {
        $res = $this->getEpisodeDanmaku($vod_id, $sid, $nid, 1000);
        $rows = isset($res['info']['rows']) ? $res['info']['rows'] : [];

        $data = [];
        foreach ($rows as $v) {
            $data[] = [
                (float)$v['danmaku_time'],
                (int)$v['danmaku_type'],
                $v['danmaku_color'],
                $v['user_name'],
                $v['danmaku_text'],
            ];
        }

        return ['code' => 0, 'data' => $data];
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
        if (isset($data['danmaku_text'])) {
            $data['danmaku_text'] = mac_filter_xss($data['danmaku_text']);
            if (mb_strlen($data['danmaku_text'], 'UTF-8') > 200) {
                $data['danmaku_text'] = mb_substr($data['danmaku_text'], 0, 200, 'UTF-8');
            }
        }

        if (isset($data['danmaku_color'])) {
            $color = trim($data['danmaku_color']);
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
                $color = '#FFFFFF';
            }
            $data['danmaku_color'] = $color;
        }

        $blacks = config('blacks');
        $content = isset($data['danmaku_text']) ? $data['danmaku_text'] : '';
        if (!empty($blacks['black_keyword_list']) && is_array($blacks['black_keyword_list'])) {
            foreach ($blacks['black_keyword_list'] as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword) && strpos($content, $keyword) !== false) {
                    return ['code' => 1003, 'msg' => lang('content_contain_sensitive')];
                }
            }
        }

        if (!empty($data['danmaku_id'])) {
            $where = [];
            $where['danmaku_id'] = ['eq', $data['danmaku_id']];
            $res = $this->allowField([])->where($where)->update($data);
            $this->_clearEpisodeCache($where);
        } else {
            $data['danmaku_send_time'] = time();
            $data['danmaku_ip'] = mac_get_ip_long();
            $res = $this->allowField([])->insertGetId($data);

            $cache_key = $GLOBALS['config']['app']['cache_flag'] . '_danmaku_' . $data['vod_id'] . '_' . $data['vod_sid'] . '_' . $data['vod_nid'];
            \think\facade\Cache::delete($cache_key);

            if (false !== $res && function_exists('hook')) {
                addon_hook('social_broadcast', [
                    'kind'   => 'danmaku',
                    'vod_id' => (int)$data['vod_id'],
                    'sid'    => (int)$data['vod_sid'],
                    'nid'    => (int)$data['vod_nid'],
                    'data'   => [
                        'danmaku_id' => (int)$res,
                        'time'       => (float)$data['danmaku_time'],
                        'type'       => (int)$data['danmaku_type'],
                        'color'      => $data['danmaku_color'],
                        'author'     => $data['user_name'],
                        'text'       => $data['danmaku_text'],
                    ],
                ]);

                if (!empty($data['user_id'])) {
                    try {
                        (new \app\common\model\Dynamics())->saveData([
                            'user_id'       => intval($data['user_id']),
                            'dynamics_type' => 'danmaku',
                            'dyn_mid'       => 1,
                            'dyn_rid'       => intval($data['vod_id']),
                            'dyn_text'      => mb_substr($data['danmaku_text'], 0, 200, 'UTF-8'),
                        ]);
                    } catch (\Exception $e) {
                        \think\facade\Log::error('Danmaku dynamics mirror failed user=' . intval($data['user_id'])
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
        $this->_clearEpisodeCache($where);
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
        if ($col === 'danmaku_status') {
            $this->_clearEpisodeCache($where);
        }
        return ['code' => 1, 'msg' => lang('set_ok')];
    }

    private function _clearEpisodeCache($where)
    {
        $flag = isset($GLOBALS['config']['app']['cache_flag']) ? $GLOBALS['config']['app']['cache_flag'] : 'mac';
        $rows = Db::name('danmaku')->where($where)->field('DISTINCT vod_id, vod_sid, vod_nid')->select();
        if (!empty($rows)) {
            foreach ($rows as $r) {
                $cache_key = $flag . '_danmaku_' . $r['vod_id'] . '_' . $r['vod_sid'] . '_' . $r['vod_nid'];
                \think\facade\Cache::delete($cache_key);
            }
        }
    }

    public function checkSendRate($user_id, $interval = 5)
    {
        $cache_key = 'danmaku_rate_' . $user_id;
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
