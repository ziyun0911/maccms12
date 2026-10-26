<?php
namespace app\common\util;

use think\facade\Db;

class AnalyticsServerTracker
{
    const VISITOR_COOKIE = 'mac_ana_vid';
    const SESSION_COOKIE = 'mac_ana_sk';
    const SESSION_IDLE_SEC = 1800;

    public static function isEnabled()
    {
        $cfg = config('maccms');
        if (!isset($cfg['analytics']) || !is_array($cfg['analytics'])) {
            return false;
        }
        return (string)(isset($cfg['analytics']['server_track']) ? $cfg['analytics']['server_track'] : '0') === '1';
    }

    private static function regionEnabled()
    {
        $cfg = config('maccms');
        return (string)(isset($cfg['analytics']['track_region']) ? $cfg['analytics']['track_region'] : '0') === '1';
    }

    public static function visitorId()
    {
        $vid = (string)cookie(self::VISITOR_COOKIE);
        if ($vid === '') {
            $vid = 'v_' . mac_get_rndstr(16);
            cookie(self::VISITOR_COOKIE, $vid, 3650 * 86400);
        }
        return mb_substr(mac_filter_xss($vid), 0, 64);
    }

    public static function sessionKey()
    {
        $sk = (string)cookie(self::SESSION_COOKIE);
        if ($sk === '') {
            $sk = 's_' . mac_get_rndstr(16);
            cookie(self::SESSION_COOKIE, $sk, self::SESSION_IDLE_SEC);
        }
        return mb_substr(mac_filter_xss($sk), 0, 64);
    }

    public static function track($mid, $rid, $typeId)
    {
        try {
            if (!self::isEnabled()) {
                return;
            }
            $request = request();
            $path = mb_substr((string)$request->url(), 0, 512);
            if ($path === '') {
                return;
            }

            $ua = (string)$request->header('user-agent');
            $ts = time();
            $visitorId = self::visitorId();
            $sessionKey = self::sessionKey();
            $userId = max(0, intval(cookie('user_id')));
            $region = self::regionEnabled() ? AnalyticsRegion::resolve(mac_get_client_ip()) : '';

            $sessionId = self::upsertSession($sessionKey, $visitorId, $userId, $ua, $region, $path, $ts);

            Db::name('AnalyticsPageview')->insert([
                'session_id' => $sessionId,
                'visitor_id' => $visitorId,
                'user_id' => $userId,
                'path' => $path,
                'mid' => max(0, intval($mid)),
                'rid' => max(0, intval($rid)),
                'type_id' => max(0, intval($typeId)),
                'stay_ms' => 0,
                'prev_path' => '',
                'referer_host' => AnalyticsUa::refererHost((string)$request->header('referer')),
                'ts' => $ts,
                'stat_date' => date('Y-m-d', $ts),
            ]);
        } catch (\Throwable $e) {
            trace('AnalyticsServerTracker::track ' . $e->getMessage(), 'error');
        }
    }

    private static function upsertSession($sessionKey, $visitorId, $userId, $ua, $region, $path, $ts)
    {
        $row = Db::name('AnalyticsSession')->where('session_key', $sessionKey)->find();
        if (empty($row)) {
            return intval(Db::name('AnalyticsSession')->insertGetId([
                'session_key' => $sessionKey,
                'visitor_id' => $visitorId,
                'user_id' => $userId,
                'device_type' => AnalyticsUa::device($ua),
                'os' => AnalyticsUa::os($ua),
                'browser' => AnalyticsUa::browser($ua),
                'app_version' => '',
                'region_code' => $region,
                'channel' => mb_substr(mac_filter_xss((string)input('get.utm_source', input('get.channel', ''))), 0, 64),
                'entry_path' => $path,
                'exit_path' => $path,
                'page_count' => 1,
                'duration_sec' => 0,
                'is_bounce' => 1,
                'started_at' => $ts,
                'ended_at' => $ts,
            ]));
        }

        $sessionId = intval($row['session_id']);
        $startedAt = intval($row['started_at']);
        $update = [
            'exit_path' => $path,
            'ended_at' => $ts,
            'page_count' => Db::raw('page_count+1'),
            'is_bounce' => 0,
            'duration_sec' => $startedAt > 0 ? max(0, $ts - $startedAt) : intval($row['duration_sec']),
        ];
        if ($userId > 0 && intval($row['user_id']) === 0) {
            $update['user_id'] = $userId;
        }
        Db::name('AnalyticsSession')->where('session_id', $sessionId)->update($update);
        return $sessionId;
    }
}
