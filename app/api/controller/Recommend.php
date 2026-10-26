<?php

namespace app\api\controller;

use think\facade\Db;
use think\facade\Cache;
use think\Request;
use app\common\util\SlidingWindowIpLimiter;

class Recommend extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function list(Request $request)
    {
        $ip = mac_get_client_ip();
        $rl = SlidingWindowIpLimiter::checkHit($ip, 'recommend', 60, 30, 'recommend_rl');
        if (empty($rl['allowed'])) {
            return json(['code' => 1429, 'msg' => sprintf(lang('anti_scrape/rate_limited'), intval($rl['retry_after']))]);
        }

        $param = $request->param();
        $limit = intval($param['limit'] ?? 12);
        if ($limit < 1) {
            $limit = 12;
        }
        if ($limit > 30) {
            $limit = 30;
        }

        $check = (new \app\common\model\User())->checkLogin();
        $loggedIn = !($check['code'] > 1);
        $uid = $loggedIn ? intval($check['info']['user_id']) : 0;

        $reqUserId = intval($param['user_id'] ?? 0);
        if ($reqUserId > 0 && $loggedIn && $reqUserId !== $uid) {
            return json(['code' => 1403, 'msg' => lang('permission_denied')]);
        }

        $profile = null;
        if ($loggedIn) {
            $profile = (new \app\common\model\UserProfile())->getByUser($uid);
        }

        $preferTypeIds = [];
        if (!empty($profile['prefer_types'])) {
            $arr = json_decode($profile['prefer_types'], true);
            if (is_array($arr)) {
                foreach ($arr as $it) {
                    if (is_array($it) && isset($it['type_id'])) {
                        $tid = intval($it['type_id']);
                        if ($tid > 0) {
                            $preferTypeIds[] = $tid;
                        }
                    }
                }
            }
            $preferTypeIds = array_values(array_unique($preferTypeIds));
        }

        $items = [];
        if (!empty($preferTypeIds)) {
            $items = $this->fetchByTypeIds($preferTypeIds, $limit);
        }
        if (empty($items)) {
            $items = $this->fetchPopular($limit);
        }

        return json(['code' => 1, 'msg' => lang('obtain_ok'), 'info' => $items]);
    }

    private function fetchByTypeIds(array $typeIds, $limit)
    {
        $keyIds = $typeIds;
        sort($keyIds);
        $cacheKey = 'rec_types_' . md5(implode(',', $keyIds)) . '_' . intval($limit);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $pre = Db::getConfig('connections.mysql.prefix');
        $rows = Db::name('Vod')
            ->alias('c')
            ->join($pre . 'content_quality q', 'q.mid = 1 and q.content_id = c.vod_id', 'inner')
            ->field('c.vod_id,c.vod_name,c.vod_pic,c.vod_score,c.vod_year,c.vod_area,c.vod_remarks,c.type_id')
            ->where('c.vod_status', 1)
            ->whereIn('c.type_id', $typeIds)
            ->order('q.score_total desc')
            ->limit($limit)
            ->select();

        $items = $this->formatVodRows($rows);
        Cache::set($cacheKey, $items, 300);
        return $items;
    }

    private function fetchPopular($limit)
    {
        $cacheKey = 'rec_popular_' . intval($limit);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        $pre = Db::getConfig('connections.mysql.prefix');
        $rows = Db::name('Vod')
            ->alias('c')
            ->join($pre . 'content_quality q', 'q.mid = 1 and q.content_id = c.vod_id', 'inner')
            ->field('c.vod_id,c.vod_name,c.vod_pic,c.vod_score,c.vod_year,c.vod_area,c.vod_remarks,c.type_id')
            ->where('c.vod_status', 1)
            ->order('q.score_total desc')
            ->limit($limit)
            ->select();

        $items = $this->formatVodRows($rows);
        Cache::set($cacheKey, $items, 300);
        return $items;
    }

    private function formatVodRows($rows)
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'vod_id'      => intval($row['vod_id']),
                'vod_name'    => (string)$row['vod_name'],
                'vod_pic'     => mac_url_img($row['vod_pic']),
                'vod_score'   => (string)$row['vod_score'],
                'vod_year'    => (string)$row['vod_year'],
                'vod_area'    => (string)$row['vod_area'],
                'vod_remarks' => (string)$row['vod_remarks'],
                'type_id'     => intval($row['type_id']),
            ];
        }
        return $items;
    }
}
