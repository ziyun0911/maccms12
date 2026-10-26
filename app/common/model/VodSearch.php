<?php
namespace app\common\model;

use think\facade\Db;
use think\facade\Cache;

class VodSearch extends Base {
    protected $name = 'vod_search';
    public $maxIdCount = 1000;
    private $updateTopCount = 50000;

    private static $getResultIdListMemo = [];

    public function getResultIdList($search_word, $search_field, $word_multiple = false)
    {
        $search_word = trim($search_word);
        $search_word = str_replace(',,', '', $search_word);
        if (strlen($search_word) == 0 || strlen($search_field) == 0) {
            return [];
        }
        if ($word_multiple === true) {
            $id_list = [];
            $search_word_exploded = explode(',', $search_word);
            foreach ($search_word_exploded as $search_word) {
                $search_word = trim((string)$search_word);
                if ($search_word === '') {
                    continue;
                }
                $chunk = $this->getResultIdList($search_word, $search_field);
                $id_list = array_merge($id_list, is_array($chunk) ? $chunk : []);
            }
            $id_list = array_values(array_unique(array_map('intval', $id_list)));

            return $id_list;
        }
        $search_key = md5($search_word . '@' . $search_field);
        if (isset(self::$getResultIdListMemo[$search_key])) {
            return self::$getResultIdListMemo[$search_key];
        }
        $where = ['search_key' => $search_key];
        $search_row = $this->where($where)->field("search_result_ids, search_hit_count")->find();
        if (empty($search_row)) {
            $where_vod = [];
            $where_vod[$search_field] = ['LIKE', '%' . $search_word . '%'];
            try {
                $id_list = Db::name('Vod')
                    ->where('vod_status', 1)
                    ->where('vod_recycle_time', 0)
                    ->where($where_vod)
                    ->order('vod_id ASC')
                    ->column('vod_id');
            } catch (\Throwable $e) {
                $id_list = Db::name('Vod')
                    ->where('vod_status', 1)
                    ->where($where_vod)
                    ->order('vod_id ASC')
                    ->column('vod_id');
            }
            $id_list = is_array($id_list) ? $id_list : [];
            $this->insert([
                'search_key'           => $search_key,
                'search_word'          => mb_substr($search_word, 0, 128),
                'search_field'         => mb_substr($search_field, 0, 64),
                'search_hit_count'     => 1,
                'search_last_hit_time' => time(),
                'search_update_time'   => time(),
                'search_result_count'  => count($id_list),
                'search_result_ids'    => join(',', $id_list),
            ]);
        } else {
            $id_list = explode(',', (string)$search_row['search_result_ids']);
            $id_list = array_filter($id_list);
            $this->where($where)->update([
                'search_hit_count'     => $search_row['search_hit_count'] + 1,
                'search_last_hit_time' => time(),
            ]);
        }
        $id_list = array_map('intval', $id_list);
        $id_list = empty($id_list) ? [0] : $id_list;
        self::$getResultIdListMemo[$search_key] = $id_list;

        return $id_list;
    }

    public function isFrontendEnabled()
    {
        $config = config('maccms');
        if (!isset($config['app']['vod_search_optimise'])) {
            return false;
        }
        $list = explode('|', $config['app']['vod_search_optimise']);
        return in_array('frontend', $list);
    }

    public function isCollectEnabled()
    {
        $config = config('maccms');
        if (!isset($config['app']['vod_search_optimise'])) {
            return false;
        }
        $list = explode('|', $config['app']['vod_search_optimise']);
        return in_array('collect', $list);
    }

    public function checkAndUpdateTopResults($vod, $force = false)
    {
        static $list;
        if (empty($vod['vod_id'])) {
            return;
        }
        if (is_null($list)) {
            $cach_name = 'vod_search_top_result_v2_' . $this->updateTopCount;
            $list = $force ? [] : Cache::get($cach_name);
            if (empty($list)) {
                $list = $this->field("search_key, search_word, search_field")->order("search_hit_count DESC, search_last_hit_time DESC")->limit(0, (int)$this->updateTopCount)->select();
                $force === false && Cache::set($cach_name, $list, count($list) < ($this->updateTopCount / 10) ? 3600 : 86400);
                $this->clearOldResult();
            }
        }
        $time_now = time();
        $vid = isset($vod['vod_id']) ? (int)$vod['vod_id'] : 0;
        if ($vid <= 0) {
            return;
        }
        foreach ($list as $row) {
            $searchKey = isset($row['search_key']) ? (string)$row['search_key'] : '';
            if ($searchKey === '' || !preg_match('/^[a-f0-9]{32}$/i', $searchKey)) {
                continue;
            }
            foreach (explode('|', (string)$row['search_field']) as $field) {
                $field = trim($field);
                if ($field === '' || !isset($vod[$field]) || $vod[$field] === '' || $vod[$field] === null) {
                    continue;
                }
                if (stripos((string)$vod[$field], (string)$row['search_word']) === false) {
                    continue;
                }
                $this->appendVodIdToSearchCacheRow($searchKey, $vid, $time_now);
            }
        }
    }

    private function appendVodIdToSearchCacheRow($searchKey, $vid, $time_now)
    {
        $searchKey = (string)$searchKey;
        $vid = (int)$vid;
        $time_now = (int)$time_now;
        if ($searchKey === '' || $vid <= 0) {
            return;
        }
        $raw = Db::name('vod_search')->where('search_key', $searchKey)->value('search_result_ids');
        $ids = [];
        foreach (explode(',', (string)$raw) as $p) {
            $p = (int)trim($p);
            if ($p > 0) {
                $ids[$p] = $p;
            }
        }
        if (isset($ids[$vid])) {
            Db::name('vod_search')->where('search_key', $searchKey)->update([
                'search_update_time' => $time_now,
            ]);

            return;
        }
        $ids[$vid] = $vid;
        $ids = array_values($ids);
        Db::name('vod_search')->where('search_key', $searchKey)->update([
            'search_update_time'  => $time_now,
            'search_result_count' => count($ids),
            'search_result_ids'   => implode(',', $ids),
        ]);
    }

    public function getResultCacheMinutes($config = []) {
        $minutes = 20160;
        $config = $config ?: config('maccms');
        if (isset($config['app']['vod_search_optimise_cache_minutes']) && (int)$config['app']['vod_search_optimise_cache_minutes'] > 0) {
            $minutes = (int)$config['app']['vod_search_optimise_cache_minutes'];
        }
        return $minutes;
    }

    public function clearOldResult($force = false) 
    {
        $clear_seconds = $this->getResultCacheMinutes() * 60;
        $cach_name = 'interval_vs_clear_old_v1_' . $clear_seconds;
        $cache_data = Cache::get($cach_name);
        if ($force === false && !empty($cache_data)) {
            return;
        }
        Cache::set($cach_name, 1, min((int)$clear_seconds, 86400));
        $where = [
            'search_field'       => ['neq', 'vod_actor'],
            'search_update_time' => ['lt', time() - $clear_seconds],
        ];
        if ($force === true) {
            unset($where['search_field']);
        }
        $this->where($where)->delete();
    }
}
