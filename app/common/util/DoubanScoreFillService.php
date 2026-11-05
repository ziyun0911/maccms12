<?php
namespace app\common\util;

use think\facade\Cache;
use think\facade\Db;

class DoubanScoreFillService
{
    const ID_CHUNK = 500;
    const UPDATE_CHUNK = 500;
    const MAX_ROWS = 5000;

    public static function scoreMap()
    {
        $rows = Db::name('ext_source_item')
            ->field('item_key,item_score')
            ->where('provider_code', 'douban')
            ->where('item_score', '>', 0)
            ->select();

        $map = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : []);
            $key = trim((string)(isset($row['item_key']) ? $row['item_key'] : ''));
            if (strpos($key, 'subject_') !== 0) {
                continue;
            }
            $num = substr($key, 8);
            if ($num === false || !ctype_digit($num)) {
                continue;
            }
            $doubanId = intval($num);
            $score = round(floatval(isset($row['item_score']) ? $row['item_score'] : 0), 1);
            if ($doubanId <= 0 || $score <= 0 || $score > 10) {
                continue;
            }
            $map[$doubanId] = $score;
        }
        return $map;
    }

    public static function run(array $opt = [])
    {
        $limit = max(1, min(self::MAX_ROWS, intval(isset($opt['limit']) ? $opt['limit'] : 1000)));
        $overwrite = intval(isset($opt['overwrite']) ? $opt['overwrite'] : 0) === 1;

        $map = self::scoreMap();
        if (empty($map)) {
            return [
                'map' => 0,
                'scanned' => 0,
                'updated' => 0,
                'overwrite' => $overwrite ? 1 : 0,
                'msg' => 'no douban score map, run extsync first',
            ];
        }

        $scanned = 0;
        $updated = 0;
        foreach (array_chunk(array_keys($map), self::ID_CHUNK) as $chunk) {
            if ($updated >= $limit) {
                break;
            }
            $query = Db::name('vod')
                ->field('vod_id,vod_en,vod_douban_id')
                ->where('vod_douban_id', 'in', $chunk);
            if (!$overwrite) {
                $query->where('vod_douban_score', 0);
            }
            $rows = $query->limit($limit - $updated)->select();

            $byScore = [];
            $enMap = [];
            foreach ($rows as $row) {
                $row = is_array($row) ? $row : (method_exists($row, 'toArray') ? $row->toArray() : []);
                $vodId = intval(isset($row['vod_id']) ? $row['vod_id'] : 0);
                $doubanId = intval(isset($row['vod_douban_id']) ? $row['vod_douban_id'] : 0);
                if ($vodId <= 0 || !isset($map[$doubanId])) {
                    continue;
                }
                $scanned++;
                $score = $map[$doubanId];
                if (!isset($enMap[$vodId])) {
                    $en = trim((string)(isset($row['vod_en']) ? $row['vod_en'] : ''));
                    if ($en !== '') {
                        $enMap[$vodId] = $en;
                    }
                }
                $bucket = sprintf('%.1f', $score);
                $byScore[$bucket][] = $vodId;
            }

            foreach ($byScore as $score => $ids) {
                foreach (array_chunk($ids, self::UPDATE_CHUNK) as $idChunk) {
                    $affected = Db::name('vod')
                        ->where('vod_id', 'in', $idChunk)
                        ->update(['vod_douban_score' => floatval($score)]);
                    if ($affected === false) {
                        continue;
                    }
                    $updated += intval($affected);
                    foreach ($idChunk as $vodId) {
                        Cache::delete('vod_detail_' . $vodId);
                        if (isset($enMap[$vodId])) {
                            Cache::delete('vod_detail_' . $enMap[$vodId]);
                        }
                    }
                }
            }
        }

        return [
            'map' => count($map),
            'scanned' => $scanned,
            'updated' => $updated,
            'overwrite' => $overwrite ? 1 : 0,
            'msg' => 'scanned=' . $scanned . ' updated=' . $updated . ' map=' . count($map),
        ];
    }
}
