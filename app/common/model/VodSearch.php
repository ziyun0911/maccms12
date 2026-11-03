<?php
namespace app\common\model;

use think\facade\Db;
use think\facade\Cache;

class VodSearch extends Base {
    protected $name = 'vod_search';
    public $maxIdCount = 1000;
    private $updateTopCount = 1000;

    private const UPDATE_BATCH_KEYS = 100;
    private const UPDATE_BATCH_BYTES = 524288;

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
        $matchedKeys = [];
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
                $matchedKeys[strtolower($searchKey)] = true;
            }
        }
        if (!empty($matchedKeys)) {
            $this->appendVodIdToSearchRows(array_keys($matchedKeys), $vid, $time_now);
        }
    }

    private function appendVodIdToSearchRows(array $searchKeys, int $vid, int $time_now)
    {
        if (empty($searchKeys) || $vid <= 0) {
            return;
        }
        $rows = Db::name('vod_search')
            ->field('search_key, search_result_ids')
            ->whereIn('search_key', $searchKeys)
            ->select();

        $idsCase = [];
        $countCase = [];
        $keys = [];
        foreach ($rows as $row) {
            $key = strtolower((string)$row['search_key']);
            if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
                continue;
            }
            $ids = [];
            foreach (explode(',', (string)$row['search_result_ids']) as $p) {
                $p = (int)trim($p);
                if ($p > 0) {
                    $ids[$p] = $p;
                }
            }
            $ids[$vid] = $vid;
            $ids = array_values($ids);
            $keys[] = $key;
            $idsCase[$key] = implode(',', $ids);
            $countCase[$key] = count($ids);
        }
        if (empty($keys)) {
            return;
        }

        $table = $this->getTable();
        $chunk = [];
        $chunk_bytes = 0;
        foreach ($keys as $key) {
            $cost = strlen($key) * 3 + strlen($idsCase[$key]) + 64;
            if (!empty($chunk) && ($chunk_bytes + $cost > self::UPDATE_BATCH_BYTES || count($chunk) >= self::UPDATE_BATCH_KEYS)) {
                $this->updateSearchRowChunk($table, $chunk, $idsCase, $countCase, $time_now);
                $chunk = [];
                $chunk_bytes = 0;
            }
            $chunk[] = $key;
            $chunk_bytes += $cost;
        }
        if (!empty($chunk)) {
            $this->updateSearchRowChunk($table, $chunk, $idsCase, $countCase, $time_now);
        }
    }

    private function updateSearchRowChunk(string $table, array $chunk, array $idsCase, array $countCase, int $time_now): void
    {
        $idsWhen = [];
        $countWhen = [];
        foreach ($chunk as $key) {
            $idsWhen[] = "WHEN '" . $key . "' THEN '" . $idsCase[$key] . "'";
            $countWhen[] = "WHEN '" . $key . "' THEN " . (int)$countCase[$key];
        }
        Db::execute('UPDATE `' . $table . '` SET `search_update_time` = ' . (int)$time_now
            . ', `search_result_ids` = CASE `search_key` ' . implode(' ', $idsWhen) . ' END'
            . ', `search_result_count` = CASE `search_key` ' . implode(' ', $countWhen) . ' END'
            . " WHERE `search_key` IN ('" . implode("','", $chunk) . "')");
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
