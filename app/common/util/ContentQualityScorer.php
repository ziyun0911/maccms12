<?php
namespace app\common\util;

class ContentQualityScorer
{
    const STAY_MS_CAP = 300000;
    const CONV_RATE_CAP = 0.05;
    const VOLUME_PV_CAP = 10000;
    const SOCIAL_CAP = 500;
    const REPORT_PENALTY_PER = 2;
    const REPORT_PENALTY_MAX = 50;

    public static function defaultWeights()
    {
        return [
            'behavior' => 0.35,
            'interact' => 0.30,
            'complete' => 0.20,
            'fresh' => 0.15,
        ];
    }

    public static function completeness($mid, array $content)
    {
        $prefix = (intval($mid) === 2) ? 'art' : 'vod';
        $fields = ['blurb', 'tag', 'pic', 'content'];
        if ($prefix === 'vod') {
            $fields[] = 'actor';
        }

        $total = count($fields);
        $has = 0;
        foreach ($fields as $field) {
            $key = $prefix . '_' . $field;
            if (isset($content[$key]) && trim((string)$content[$key]) !== '') {
                $has++;
            }
        }

        if ($total <= 0) {
            return 0.0;
        }

        return self::clamp(($has / $total) * 100);
    }

    public static function freshness($timeAdd, $halflifeDays, $now)
    {
        $halflifeDays = floatval($halflifeDays);
        if ($halflifeDays <= 0) {
            $halflifeDays = 30.0;
        }

        $deltaDays = (floatval($now) - floatval($timeAdd)) / 86400;
        if ($deltaDays < 0) {
            $deltaDays = 0;
        }

        $score = exp(-$deltaDays / $halflifeDays) * 100;
        return self::clamp($score);
    }

    private static function behaviorSubScore(array $agg)
    {
        $viewUv = isset($agg['view_uv']) ? floatval($agg['view_uv']) : 0;
        $viewPv = isset($agg['view_pv']) ? floatval($agg['view_pv']) : 0;
        $avgStayMs = isset($agg['avg_stay_ms']) ? floatval($agg['avg_stay_ms']) : 0;
        $bounceCnt = isset($agg['bounce_cnt']) ? floatval($agg['bounce_cnt']) : 0;
        $orderCnt = isset($agg['order_cnt']) ? floatval($agg['order_cnt']) : 0;

        $stayScore = self::clamp(($avgStayMs / self::STAY_MS_CAP) * 100);

        $bounceRate = $bounceCnt / max(1, $viewUv);
        $bounceScore = self::clamp((1 - $bounceRate) * 100);

        $convRate = $orderCnt / max(1, $viewUv);
        $convScore = self::clamp(($convRate / self::CONV_RATE_CAP) * 100);

        $volumeScore = self::clamp((log10($viewPv + 1) / log10(self::VOLUME_PV_CAP + 1)) * 100);

        $score = 0.35 * $stayScore + 0.25 * $bounceScore + 0.20 * $convScore + 0.20 * $volumeScore;
        return self::clamp($score);
    }

    private static function interactSubScore($mid, array $content, array $agg)
    {
        $prefix = (intval($mid) === 2) ? 'art' : 'vod';

        $rawScore = isset($content[$prefix . '_score']) ? floatval($content[$prefix . '_score']) : 0;
        $scoreComponent = self::clamp($rawScore * 10);

        $up = isset($content[$prefix . '_up']) ? floatval($content[$prefix . '_up']) : 0;
        $down = isset($content[$prefix . '_down']) ? floatval($content[$prefix . '_down']) : 0;
        $ratioComponent = self::clamp(($up / max(1, $up + $down)) * 100);

        $collectAdd = isset($agg['collect_add']) ? floatval($agg['collect_add']) : 0;
        $wantAdd = isset($agg['want_add']) ? floatval($agg['want_add']) : 0;
        $socialComponent = self::clamp((log10($collectAdd + $wantAdd + 1) / log10(self::SOCIAL_CAP + 1)) * 100);

        $score = 0.40 * $scoreComponent + 0.25 * $ratioComponent + 0.35 * $socialComponent;

        if (isset($content['comment_report'])) {
            $reportPenalty = min(self::REPORT_PENALTY_MAX, floatval($content['comment_report']) * self::REPORT_PENALTY_PER);
            $score -= $reportPenalty;
        }

        return self::clamp($score);
    }

    public static function scoreRow($mid, array $content, $behaviorAgg, array $weights, $halflifeDays, $now)
    {
        $prefix = (intval($mid) === 2) ? 'art' : 'vod';

        $complete = self::completeness($mid, $content);
        $timeAdd = isset($content[$prefix . '_time_add']) ? $content[$prefix . '_time_add'] : 0;
        $fresh = self::freshness($timeAdd, $halflifeDays, $now);

        $w = self::normalizeWeights($weights);

        $viewPv = ($behaviorAgg !== null && isset($behaviorAgg['view_pv'])) ? floatval($behaviorAgg['view_pv']) : 0;
        $isColdStart = ($behaviorAgg === null || $viewPv <= 0);

        if ($isColdStart) {
            $wSum = $w['complete'] + $w['fresh'];
            if ($wSum > 0) {
                $wComplete = $w['complete'] / $wSum;
                $wFresh = $w['fresh'] / $wSum;
            } else {
                $wComplete = 0.5;
                $wFresh = 0.5;
            }

            $total = self::clamp($wComplete * $complete + $wFresh * $fresh);

            return [
                'score_total' => $total,
                'score_behavior' => 0.0,
                'score_interact' => 0.0,
                'score_complete' => $complete,
                'score_fresh' => $fresh,
                'is_cold_start' => 1,
            ];
        }

        $behavior = self::behaviorSubScore($behaviorAgg);
        $interact = self::interactSubScore($mid, $content, $behaviorAgg);

        $total = self::clamp(
            $w['behavior'] * $behavior
            + $w['interact'] * $interact
            + $w['complete'] * $complete
            + $w['fresh'] * $fresh
        );

        return [
            'score_total' => $total,
            'score_behavior' => $behavior,
            'score_interact' => $interact,
            'score_complete' => $complete,
            'score_fresh' => $fresh,
            'is_cold_start' => 0,
        ];
    }

    private static function normalizeWeights(array $weights)
    {
        $keys = ['behavior', 'interact', 'complete', 'fresh'];
        $w = [];
        $sum = 0;
        foreach ($keys as $key) {
            $v = isset($weights[$key]) ? floatval($weights[$key]) : 0;
            if ($v < 0) {
                $v = 0;
            }
            $w[$key] = $v;
            $sum += $v;
        }

        if ($sum <= 0) {
            return self::defaultWeights();
        }

        foreach ($keys as $key) {
            $w[$key] = $w[$key] / $sum;
        }

        return $w;
    }

    private static function clamp($value)
    {
        $value = floatval($value);
        if (is_nan($value)) {
            return 0.0;
        }
        if ($value < 0) {
            return 0.0;
        }
        if ($value > 100) {
            return 100.0;
        }
        return $value;
    }
}
