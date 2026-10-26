<?php
namespace app\common\util;

use think\facade\Db;

class UserProfileBuilder
{
    public function buildForUser($uid, $windowDays, $now)
    {
        $uid = intval($uid);
        $windowDays = intval($windowDays);
        $now = intval($now);
        $cutoff = $now - $windowDays * 86400;

        $watchRows = [];
        if ($uid > 0) {
            $watchRows = Db::name('Ulog')
                ->where('user_id', $uid)
                ->where('ulog_type', 4)
                ->where('ulog_time', '>=', $cutoff)
                ->field('ulog_mid,ulog_rid,ulog_point,ulog_duration')
                ->select();
        }
        if (empty($watchRows)) {
            $watchRows = [];
        }

        $watchCntWindow = count($watchRows);

        $ratioSum = 0.0;
        $ratioCnt = 0;
        foreach ($watchRows as $row) {
            $duration = intval($row['ulog_duration']);
            if ($duration <= 0) {
                continue;
            }
            $point = intval($row['ulog_point']);
            $ratio = $point / max(1, $duration);
            if ($ratio < 0) {
                $ratio = 0;
            } elseif ($ratio > 1) {
                $ratio = 1;
            }
            $ratioSum += $ratio;
            $ratioCnt++;
        }
        $avgCompletionRate = $ratioCnt > 0 ? round($ratioSum / $ratioCnt, 4) : 0.0;

        if ($watchCntWindow >= 16) {
            $activityLevel = 3;
        } elseif ($watchCntWindow >= 4) {
            $activityLevel = 2;
        } elseif ($watchCntWindow >= 1) {
            $activityLevel = 1;
        } else {
            $activityLevel = 0;
        }

        $vodRids = [];
        $artRids = [];
        foreach ($watchRows as $row) {
            $mid = intval($row['ulog_mid']);
            $rid = intval($row['ulog_rid']);
            if ($mid == 1) {
                $vodRids[] = $rid;
            } elseif ($mid == 2) {
                $artRids[] = $rid;
            }
        }

        $vodTypeMap = [];
        $vodTagRows = [];
        if (!empty($vodRids)) {
            $vodList = Db::name('Vod')
                ->whereIn('vod_id', array_unique($vodRids))
                ->field('vod_id,type_id,vod_tag')
                ->select();
            foreach ($vodList as $v) {
                $vodTypeMap[intval($v['vod_id'])] = intval($v['type_id']);
                $vodTagRows[] = $v;
            }
        }

        $artTypeMap = [];
        if (!empty($artRids)) {
            $artList = Db::name('Art')
                ->whereIn('art_id', array_unique($artRids))
                ->field('art_id,type_id')
                ->select();
            foreach ($artList as $a) {
                $artTypeMap[intval($a['art_id'])] = intval($a['type_id']);
            }
        }

        $typeCount = [];
        foreach ($watchRows as $row) {
            $mid = intval($row['ulog_mid']);
            $rid = intval($row['ulog_rid']);
            $typeId = 0;
            if ($mid == 1 && isset($vodTypeMap[$rid])) {
                $typeId = $vodTypeMap[$rid];
            } elseif ($mid == 2 && isset($artTypeMap[$rid])) {
                $typeId = $artTypeMap[$rid];
            }
            if ($typeId <= 0) {
                continue;
            }
            if (!isset($typeCount[$typeId])) {
                $typeCount[$typeId] = 0;
            }
            $typeCount[$typeId]++;
        }

        $preferTypes = '[]';
        if (!empty($typeCount)) {
            arsort($typeCount);
            $total = array_sum($typeCount);
            $typeCount = array_slice($typeCount, 0, 5, true);
            $list = [];
            foreach ($typeCount as $typeId => $cnt) {
                $list[] = [
                    'type_id' => intval($typeId),
                    'w' => $total > 0 ? round($cnt / $total, 4) : 0,
                ];
            }
            $preferTypes = json_encode($list);
        }

        $preferTags = '[]';
        if (!empty($vodTagRows)) {
            $tagCount = [];
            foreach ($vodTagRows as $v) {
                $tagStr = trim((string)$v['vod_tag']);
                if ($tagStr === '') {
                    continue;
                }
                $tags = preg_split('/[,\/]+/', $tagStr);
                foreach ($tags as $tag) {
                    $tag = trim($tag);
                    if ($tag === '') {
                        continue;
                    }
                    if (!isset($tagCount[$tag])) {
                        $tagCount[$tag] = 0;
                    }
                    $tagCount[$tag]++;
                }
            }
            if (!empty($tagCount)) {
                arsort($tagCount);
                $tagCount = array_slice($tagCount, 0, 5, true);
                $preferTags = json_encode(array_values(array_keys($tagCount)), JSON_UNESCAPED_UNICODE);
            }
        }

        $payAmountWindow = 0.0;
        if ($uid > 0) {
            $sum = Db::name('Order')
                ->where('user_id', $uid)
                ->where('order_status', 1)
                ->where('order_time', '>=', $cutoff)
                ->sum('order_price');
            $payAmountWindow = round(floatval($sum), 2);
        }

        $lastActiveTime = 0;
        if ($uid > 0) {
            $max = Db::name('Ulog')
                ->where('user_id', $uid)
                ->max('ulog_time');
            $lastActiveTime = intval($max);
        }

        return [
            'prefer_types' => $preferTypes,
            'prefer_tags' => $preferTags,
            'avg_completion_rate' => $avgCompletionRate,
            'watch_cnt_window' => $watchCntWindow,
            'pay_amount_window' => $payAmountWindow,
            'activity_level' => $activityLevel,
            'last_active_time' => $lastActiveTime,
        ];
    }
}
