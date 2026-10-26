<?php

namespace app\api\controller;

use think\Request;
use think\facade\Db;
use think\facade\Cache;
use app\common\util\ApiMeilisearchSuggest;
use app\common\util\MeilisearchService;
use app\common\util\MeilisearchListBridge;

class Search extends Base
{
    use PublicApi;

    private static $kindRichMeta = [
        'vod' => [
            'table'       => 'vod',
            'pk'          => 'vod_id',
            'status'      => 'vod_status',
            'recycle'     => 'vod_recycle_time',
            'like'        => 'vod_name|vod_en|vod_actor|vod_director',
            'order'       => 'vod_time desc',
            'field'       => 'vod_id,vod_name,vod_en,vod_sub,vod_pic,vod_actor,vod_director,vod_remarks,vod_score,vod_area,vod_year,vod_class,vod_blurb,vod_time,vod_hits,type_id,type_id_1',
            'lang_key'    => 'vod',
        ],
        'art' => [
            'table'       => 'art',
            'pk'          => 'art_id',
            'status'      => 'art_status',
            'recycle'     => 'art_recycle_time',
            'like'        => 'art_name|art_sub|art_tag',
            'order'       => 'art_time desc',
            'field'       => 'art_id,art_name,art_sub,art_pic,art_tag,art_class,art_blurb,art_time,art_hits,art_score,type_id,type_id_1',
            'lang_key'    => 'art',
        ],
        'manga' => [
            'table'       => 'manga',
            'pk'          => 'manga_id',
            'status'      => 'manga_status',
            'recycle'     => 'manga_recycle_time',
            'like'        => 'manga_name|manga_en|manga_tag',
            'order'       => 'manga_time desc',
            'field'       => 'manga_id,manga_name,manga_en,manga_sub,manga_pic,manga_tag,manga_class,manga_blurb,manga_time,manga_hits,manga_score,manga_remarks,type_id,type_id_1',
            'lang_key'    => 'manga',
        ],
    ];

    const RATE_WINDOW = 60;
    const RATE_MAX    = 30;

    const RESULT_CACHE_TTL = 300;

    public function __construct()
    {
        parent::__construct();
        $this->check_config();
    }

    private function checkRateLimit()
    {
        $ip = function_exists('mac_get_client_ip') ? mac_get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($ip === '') {
            $ip = '0.0.0.0';
        }
        $key = 'api_search_rl_' . md5($ip);

        try {
            $handler = \think\facade\Cache::store()->handler();
            if (class_exists('\Redis', false) && $handler instanceof \Redis) {
                $cnt = (int)$handler->incr($key);
                if ($cnt === 1) {
                    $handler->expire($key, self::RATE_WINDOW);
                }
                return $cnt <= self::RATE_MAX;
            }
        } catch (\Throwable $e) {
        }

        $cnt = (int)Cache::get($key, 0);
        if ($cnt >= self::RATE_MAX) {
            return false;
        }
        Cache::set($key, $cnt + 1, self::RATE_WINDOW);
        return true;
    }

    private function resultCacheKey($endpoint, array $params)
    {
        ksort($params);
        return 'api_search_' . $endpoint . '_' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));
    }

    public function index(Request $request)
    {
        $param = $request->param();

        $wd = trim($param['wd'] ?? '');
        if (empty($wd)) {
            return json(['code' => 1001, 'msg' => '参数错误: wd 不能为空']);
        }
        if (mb_strlen($wd) > 50) {
            $wd = mb_substr($wd, 0, 50);
        }

        if ($GLOBALS['config']['app']['search'] != '1') {
            return json(['code' => 999, 'msg' => '搜索功能已关闭']);
        }

        $module = strtolower(trim($param['module'] ?? 'all'));
        $allowModules = ['all', 'vod', 'art', 'manga'];
        if (!in_array($module, $allowModules)) {
            $module = 'all';
        }

        $limit = max(1, min(50, intval($param['limit'] ?? 10)));
        $page = max(1, intval($param['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $safeWd = $this->format_sql_string($wd);
        if (empty($safeWd)) {
            return json(['code' => 1001, 'msg' => '参数错误: 关键字无效']);
        }

        $cacheKey = $this->resultCacheKey('index', [
            'wd'     => $safeWd,
            'module' => $module,
            'page'   => $page,
            'limit'  => $limit,
        ]);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            if (isset($cached['info']) && is_array($cached['info'])) {
                $cached['info']['wd'] = $wd;
            }
            return json($cached);
        }

        if (!$this->checkRateLimit()) {
            return json(['code' => 1004, 'msg' => '请求过于频繁，请稍后再试']);
        }

        $result = [
            'wd'     => $wd,
            'module' => $module,
            'page'   => $page,
            'limit'  => $limit,
        ];

        $kinds = $module === 'all' ? ['vod', 'art', 'manga'] : [$module];
        foreach ($kinds as $kind) {
            $result[$kind] = $this->searchKindRich($kind, $safeWd, $offset, $limit);
        }

        $resp = [
            'code' => 1,
            'msg'  => '搜索成功',
            'info' => $result,
        ];
        Cache::set($cacheKey, $resp, self::RESULT_CACHE_TTL);

        return json($resp);
    }

    private function searchKindRich($kind, $wd, $offset, $limit)
    {
        $meta = self::$kindRichMeta[$kind] ?? null;
        if (!$meta) {
            return ['total' => 0, 'list' => []];
        }

        $meiliRows = null;
        $meiliTotal = 0;
        if (MeilisearchService::enabled()) {
            $filter = MeilisearchService::filterPublishedKind($kind);
            if ($filter !== '') {
                $sr = MeilisearchService::search($wd, $filter, $limit, $offset);
                if (!empty($sr['ok'])) {
                    $hits = isset($sr['hits']) && is_array($sr['hits']) ? $sr['hits'] : [];
                    $meiliTotal = max(0, (int)($sr['estimatedTotalHits'] ?? 0));
                    $re = '/^' . preg_quote($kind, '/') . '_(\d+)$/';
                    $ids = [];
                    foreach ($hits as $hit) {
                        if (!empty($hit['id']) && is_string($hit['id']) && preg_match($re, $hit['id'], $mm)) {
                            $ids[] = (int)$mm[1];
                        }
                    }
                    $ids = MeilisearchListBridge::refinePrimaryIdsForPublished($ids, $kind);
                    if (!empty($ids)) {
                        $meiliRows = $this->loadRowsByIdsRich($kind, $ids, $meta);
                    } else {
                        $meiliRows = [];
                    }
                }
            }
        }

        if ($meiliRows !== null) {
            $list = $this->rowsToRichItems($kind, $meiliRows, $meta);
            return [
                'total' => $meiliRows === [] ? $meiliTotal : max($meiliTotal, count($list) + $offset),
                'list'  => $list,
            ];
        }

        $where = [
            $meta['status'] => ['eq', 1],
            $meta['like']   => ['like', '%' . $wd . '%'],
        ];
        if (!empty($meta['recycle'])) {
            try {
                $total = Db::name($meta['table'])->where($where)->where($meta['recycle'], 0)->count();
            } catch (\Throwable $e) {
                $total = Db::name($meta['table'])->where($where)->count();
            }
        } else {
            $total = Db::name($meta['table'])->where($where)->count();
        }
        $list = [];
        if ($total > 0) {
            $q = Db::name($meta['table'])->field($meta['field'])->where($where);
            if (!empty($meta['recycle'])) {
                try {
                    $q->where($meta['recycle'], 0);
                } catch (\Throwable $e) {
                }
            }
            $rows = $q->order($meta['order'])->limit($offset, $limit)->select();
            $list = $this->rowsToRichItems($kind, $rows, $meta);
        }

        return [
            'total' => (int)$total,
            'list'  => $list,
        ];
    }

    private function loadRowsByIdsRich($kind, array $ids, array $meta)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        })));
        if ($ids === []) {
            return [];
        }
        try {
            $q = Db::name($meta['table'])->field($meta['field'])->where($meta['status'], 1)->whereIn($meta['pk'], $ids);
            if (!empty($meta['recycle'])) {
                $q->where($meta['recycle'], 0);
            }
            $rows = $q->select();
        } catch (\Throwable $e) {
            $rows = Db::name($meta['table'])->field($meta['field'])->where($meta['status'], 1)->whereIn($meta['pk'], $ids)->select();
        }
        if ($rows instanceof \think\Collection) {
            $rows = $rows->toArray();
        }
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row[$meta['pk']]] = $row;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (!empty($map[$id])) {
                $ordered[] = $map[$id];
            }
        }
        return $ordered;
    }

    private function rowsToRichItems($kind, $rows, array $meta)
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        $list = [];
        if ($kind === 'vod') {
            mac_append_type_is_vip_exclusive_for_rows($rows);
            foreach ($rows as $v) {
                $list[] = [
                    'id'                    => (int)$v['vod_id'],
                    'name'                  => $v['vod_name'] ?? '',
                    'en'                    => $v['vod_en'] ?? '',
                    'sub'                   => $v['vod_sub'] ?? '',
                    'pic'                   => mac_url_img($v['vod_pic'] ?? ''),
                    'actor'                 => $v['vod_actor'] ?? '',
                    'director'              => $v['vod_director'] ?? '',
                    'remarks'               => $v['vod_remarks'] ?? '',
                    'score'                 => $v['vod_score'] ?? '0.0',
                    'area'                  => $v['vod_area'] ?? '',
                    'year'                  => $v['vod_year'] ?? '',
                    'class'                 => $v['vod_class'] ?? '',
                    'tag'                   => '',
                    'blurb'                 => $v['vod_blurb'] ?? '',
                    'time'                  => (int)($v['vod_time'] ?? 0),
                    'hits'                  => (int)($v['vod_hits'] ?? 0),
                    'link'                  => mac_url_vod_detail($v),
                    'type_id'               => (int)($v['type_id'] ?? 0),
                    'type_id_1'             => (int)($v['type_id_1'] ?? 0),
                    'type_is_vip_exclusive' => (int)($v['type_is_vip_exclusive'] ?? 0),
                    'module'                => 'vod',
                    'module_name'           => lang($meta['lang_key']),
                ];
            }
            return $list;
        }
        if ($kind === 'art') {
            foreach ($rows as $v) {
                $list[] = [
                    'id'                    => (int)$v['art_id'],
                    'name'                  => $v['art_name'] ?? '',
                    'en'                    => '',
                    'sub'                   => $v['art_sub'] ?? '',
                    'pic'                   => mac_url_img($v['art_pic'] ?? ''),
                    'actor'                 => '',
                    'director'               => '',
                    'remarks'               => '',
                    'score'                 => $v['art_score'] ?? '0.0',
                    'area'                  => '',
                    'year'                  => '',
                    'class'                 => $v['art_class'] ?? '',
                    'tag'                   => $v['art_tag'] ?? '',
                    'blurb'                 => $v['art_blurb'] ?? '',
                    'time'                  => (int)($v['art_time'] ?? 0),
                    'hits'                  => (int)($v['art_hits'] ?? 0),
                    'link'                  => mac_url_art_detail($v),
                    'type_id'               => (int)($v['type_id'] ?? 0),
                    'type_id_1'             => (int)($v['type_id_1'] ?? 0),
                    'type_is_vip_exclusive' => 0,
                    'module'                => 'art',
                    'module_name'           => lang($meta['lang_key']),
                ];
            }
            return $list;
        }
        if ($kind === 'manga') {
            foreach ($rows as $v) {
                $list[] = [
                    'id'                    => (int)$v['manga_id'],
                    'name'                  => $v['manga_name'] ?? '',
                    'en'                    => $v['manga_en'] ?? '',
                    'sub'                   => $v['manga_sub'] ?? '',
                    'pic'                   => mac_url_img($v['manga_pic'] ?? ''),
                    'actor'                 => '',
                    'director'               => '',
                    'remarks'               => $v['manga_remarks'] ?? '',
                    'score'                 => $v['manga_score'] ?? '0.0',
                    'area'                  => '',
                    'year'                  => '',
                    'class'                 => $v['manga_class'] ?? '',
                    'tag'                   => $v['manga_tag'] ?? '',
                    'blurb'                 => $v['manga_blurb'] ?? '',
                    'time'                  => (int)($v['manga_time'] ?? 0),
                    'hits'                  => (int)($v['manga_hits'] ?? 0),
                    'link'                  => mac_url_manga_detail($v),
                    'type_id'               => (int)($v['type_id'] ?? 0),
                    'type_id_1'             => (int)($v['type_id_1'] ?? 0),
                    'type_is_vip_exclusive' => 0,
                    'module'                => 'manga',
                    'module_name'           => lang($meta['lang_key']),
                ];
            }
            return $list;
        }
        return $list;
    }

    public function suggest(Request $request)
    {
        $param = $request->param();

        $wd = trim($param['wd'] ?? '');
        if (empty($wd)) {
            return json(['code' => 1001, 'msg' => '参数错误: wd 不能为空']);
        }
        if (mb_strlen($wd) > 50) {
            $wd = mb_substr($wd, 0, 50);
        }

        if ($GLOBALS['config']['app']['search'] != '1') {
            return json(['code' => 999, 'msg' => '搜索功能已关闭']);
        }

        $limit = max(1, min(10, intval($param['limit'] ?? 5)));

        $wdFilter = function_exists('mac_filter_xss') ? mac_filter_xss($wd) : $wd;
        if ($wdFilter === '') {
            return json(['code' => 1001, 'msg' => '参数错误: 关键字无效']);
        }

        $cacheKey = $this->resultCacheKey('suggest', [
            'wd'    => $wdFilter,
            'limit' => $limit,
        ]);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            if (isset($cached['info']) && is_array($cached['info'])) {
                $cached['info']['wd'] = $wd;
            }
            return json($cached);
        }

        if (!$this->checkRateLimit()) {
            return json(['code' => 1004, 'msg' => '请求过于频繁，请稍后再试']);
        }

        $moduleNames = [
            'vod'   => lang('vod'),
            'art'   => lang('art'),
            'manga' => lang('manga'),
        ];

        $suggestions = [];

        foreach (['vod', 'art', 'manga'] as $kind) {
            $res = ApiMeilisearchSuggest::suggestListDataRes($kind, $wdFilter, $limit);
            if (!is_array($res) || (int)($res['code'] ?? 0) !== 1) {
                continue;
            }
            $items = isset($res['list']) && is_array($res['list']) ? $res['list'] : [];
            foreach ($items as $it) {
                $linkKey = $kind . '_link';
                $suggestions[] = [
                    'id'          => (int)($it['id'] ?? 0),
                    'name'        => (string)($it['name'] ?? ''),
                    'en'          => (string)($it['en'] ?? ''),
                    'pic'         => (string)($it['pic'] ?? ''),
                    'link'        => (string)($it[$linkKey] ?? ''),
                    'module'      => $kind,
                    'module_name' => $moduleNames[$kind],
                ];
            }
        }

        $resp = [
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'wd'    => $wd,
                'total' => count($suggestions),
                'list'  => $suggestions,
            ],
        ];
        Cache::set($cacheKey, $resp, self::RESULT_CACHE_TTL);

        return json($resp);
    }
}
