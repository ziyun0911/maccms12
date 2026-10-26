<?php

namespace addons\socialws\server;

use GatewayWorker\Lib\Gateway;

class Events
{
    public static function onWebSocketConnect($client_id, $data)
    {
        $uid = self::resolveUidFromCookie(isset($data['server']) ? $data['server'] : []);
        if ($uid > 0) {
            $_SESSION['uid'] = $uid;
        }
    }

    public static function onMessage($client_id, $message)
    {
        $data = json_decode($message, true);
        if (!is_array($data) || empty($data['type'])) {
            return;
        }

        switch ($data['type']) {
            case 'ping':
                Gateway::sendToClient($client_id, json_encode(['type' => 'pong']));
                break;

            case 'subscribe':
                $joined = [];
                $denied = [];
                if (!empty($data['rooms']) && is_array($data['rooms'])) {
                    foreach ($data['rooms'] as $room) {
                        $key = self::roomKey($room);
                        if ($key === '') {
                            continue;
                        }
                        if (!self::canSubscribe($key, $room)) {
                            $denied[] = $key;
                            continue;
                        }
                        Gateway::joinGroup($client_id, $key);
                        $joined[] = $key;
                    }
                }
                Gateway::sendToClient($client_id, json_encode(['type' => 'subscribed', 'rooms' => $joined, 'denied' => $denied]));
                break;

            case 'unsubscribe':
                if (!empty($data['rooms']) && is_array($data['rooms'])) {
                    foreach ($data['rooms'] as $room) {
                        $key = self::roomKey($room);
                        if ($key !== '') {
                            Gateway::leaveGroup($client_id, $key);
                        }
                    }
                }
                break;
        }
    }

    private static function canSubscribe($key, $room)
    {
        if (strpos($key, 'chat_') === 0 || strpos($key, 'dm_') === 0) {
            return true;
        }
        if (strpos($key, 'user_') === 0) {
            $target_uid = (int)substr($key, 5);
            $my_uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
            return $my_uid > 0 && $my_uid === $target_uid;
        }
        return false;
    }

    private static function resolveUidFromCookie(array $server)
    {
        $cookie_raw = isset($server['HTTP_COOKIE']) ? $server['HTTP_COOKIE'] : '';
        if (empty($cookie_raw)) {
            return 0;
        }
        $cookies = [];
        foreach (explode(';', $cookie_raw) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2) {
                $cookies[urldecode($parts[0])] = urldecode($parts[1]);
            }
        }
        $uid = isset($cookies['user_id']) ? (int)$cookies['user_id'] : 0;
        $check = isset($cookies['user_check']) ? $cookies['user_check'] : '';
        if ($uid < 1 || empty($check)) {
            return 0;
        }

        static $cache = [];
        $now = time();
        $cache_key = $uid . '|' . md5($check);
        if (isset($cache[$cache_key]) && $cache[$cache_key]['exp'] > $now) {
            return $cache[$cache_key]['ok'] ? $uid : 0;
        }

        if (count($cache) > 2000) {
            foreach ($cache as $k => $v) {
                if ($v['exp'] <= $now) {
                    unset($cache[$k]);
                }
            }
            if (count($cache) > 2000) {
                $cache = [];
            }
        }

        $ok = self::verifyUserCheck($uid, $check);
        $cache[$cache_key] = ['ok' => $ok, 'exp' => $now + 60];
        return $ok ? $uid : 0;
    }

    private static function verifyUserCheck($uid, $user_check)
    {
        static $db_cfg = null;
        if ($db_cfg === null) {
            $cfg_file = __DIR__ . '/../../../config/database.php';
            if (!is_file($cfg_file)) {
                return false;
            }
            $all = include $cfg_file;
            if (!is_array($all)) {
                return false;
            }
            $db_cfg = isset($all['connections']['mysql']) ? $all['connections']['mysql'] : $all;
        }
        try {
            $dsn = 'mysql:host=' . $db_cfg['hostname'] . ';port=' . $db_cfg['hostport'] . ';dbname=' . $db_cfg['database'] . ';charset=utf8mb4';
            $pdo = new \PDO($dsn, $db_cfg['username'], $db_cfg['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 2,
            ]);
            $pre = isset($db_cfg['prefix']) ? $db_cfg['prefix'] : 'mac_';
            $stmt = $pdo->prepare('SELECT user_name, user_random FROM ' . $pre . 'user WHERE user_id = :uid LIMIT 1');
            $stmt->bindValue(':uid', $uid, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (empty($row) || empty($row['user_random']) || empty($row['user_name'])) {
                return false;
            }
            $expected = md5($row['user_random'] . '-' . $row['user_name'] . '-' . $uid . '-');
            return hash_equals($expected, (string)$user_check);
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function roomKey($r)
    {
        if (!is_array($r) || empty($r['kind'])) {
            return '';
        }
        if ($r['kind'] === 'chat') {
            if (!isset($r['vod_id'])) {
                return '';
            }
            return 'chat_' . (int)$r['vod_id'];
        }
        if ($r['kind'] === 'danmaku') {
            if (!isset($r['vod_id']) || !isset($r['sid']) || !isset($r['nid'])) {
                return '';
            }
            return 'dm_' . (int)$r['vod_id'] . '_' . (int)$r['sid'] . '_' . (int)$r['nid'];
        }
        if ($r['kind'] === 'follow') {
            if (!isset($r['follow_uid'])) {
                return '';
            }
            return 'user_' . (int)$r['follow_uid'];
        }
        if ($r['kind'] === 'dynamics') {
            if (!isset($r['user_id'])) {
                return '';
            }
            return 'user_' . (int)$r['user_id'];
        }
        if ($r['kind'] === 'pm') {
            if (!isset($r['to_uid'])) {
                return '';
            }
            return 'user_' . (int)$r['to_uid'];
        }
        return '';
    }
}
