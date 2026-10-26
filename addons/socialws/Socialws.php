<?php

namespace addons\socialws;

use app\common\addon\Addon;

class Socialws extends Addon
{
    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    public function appInit()
    {
    }

    public function socialBroadcast($params)
    {
        if (empty($params['kind']) || empty($params['data'])) {
            return;
        }

        if (!class_exists('\GatewayWorker\Lib\Gateway')) {
            return;
        }

        try {
            $rooms = $this->targetRooms($params);
            if (empty($rooms)) {
                return;
            }
            $config = $this->getConfig();
            \GatewayWorker\Lib\Gateway::$registerAddress = $config['register_address'];
            \GatewayWorker\Lib\Gateway::$connectTimeout = 0.2;
            foreach ($rooms as $room) {
                \GatewayWorker\Lib\Gateway::sendToGroup($room, json_encode([
                    'type' => $params['kind'],
                    'room' => $room,
                    'data' => $params['data'],
                ]));
            }
        } catch (\Throwable $e) {
            \think\facade\Log::record('[socialws] broadcast fail: ' . $e->getMessage(), 'error');
        }
    }

    private function targetRooms($params)
    {
        if ($params['kind'] === 'dynamics') {
            if (!isset($params['user_id'])) {
                return [];
            }
            $fan_ids = (new \app\common\model\Follow())->getFansIds((int)$params['user_id']);
            $rooms = [];
            foreach ($fan_ids as $fid) {
                $rooms[] = 'user_' . (int)$fid;
            }
            return $rooms;
        }
        $room = $this->roomKey($params);
        return $room === '' ? [] : [$room];
    }

    private function roomKey($params)
    {
        $kind = $params['kind'];
        if ($kind === 'chat') {
            if (!isset($params['vod_id'])) {
                return '';
            }
            return 'chat_' . (int)$params['vod_id'];
        }
        if ($kind === 'danmaku') {
            if (!isset($params['vod_id']) || !isset($params['sid']) || !isset($params['nid'])) {
                return '';
            }
            return 'dm_' . (int)$params['vod_id'] . '_' . (int)$params['sid'] . '_' . (int)$params['nid'];
        }
        if ($kind === 'follow') {
            if (!isset($params['follow_uid'])) {
                return '';
            }
            return 'user_' . (int)$params['follow_uid'];
        }
        if ($kind === 'dynamics') {
            if (!isset($params['user_id'])) {
                return '';
            }
            return 'user_' . (int)$params['user_id'];
        }
        if ($kind === 'pm') {
            if (!isset($params['to_uid'])) {
                return '';
            }
            return 'user_' . (int)$params['to_uid'];
        }
        return '';
    }
}
