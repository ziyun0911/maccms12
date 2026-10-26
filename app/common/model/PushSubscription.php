<?php
namespace app\common\model;

use think\facade\Db;

class PushSubscription extends Base
{
    protected $name = 'push_subscription';
    protected $pk = 'subscription_id';
    protected $createTime = '';
    protected $updateTime = '';

    const MAX_PER_USER = 10;

    protected static $endpointHostWhitelist = [
        'googleapis.com',
        'google.com',
        'push.services.mozilla.com',
        'notify.windows.com',
        'push.apple.com',
    ];

    public function saveSubscription($data)
    {
        $userId   = isset($data['user_id']) ? intval($data['user_id']) : 0;
        $endpoint = isset($data['endpoint']) ? trim((string)$data['endpoint']) : '';
        $p256dh   = isset($data['p256dh']) ? trim((string)$data['p256dh']) : '';
        $auth     = isset($data['auth']) ? trim((string)$data['auth']) : '';
        $ua       = isset($data['user_agent']) ? substr((string)$data['user_agent'], 0, 255) : '';

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return ['code' => 0, 'msg' => lang('param_err')];
        }
        if (strlen($endpoint) > 512) {
            return ['code' => 0, 'msg' => lang('param_err')];
        }
        if (!self::isAllowedEndpoint($endpoint)) {
            return ['code' => 0, 'msg' => lang('param_err')];
        }

        $now = time();
        $where = ['user_id' => $userId, 'endpoint' => $endpoint];
        $exist = Db::name('push_subscription')->where($where)->find();

        if ($exist) {
            Db::name('push_subscription')->where($where)->update([
                'p256dh'      => $p256dh,
                'auth'        => $auth,
                'user_agent'  => $ua,
                'update_time' => $now,
            ]);
            return ['code' => 1, 'msg' => lang('save_ok')];
        }

        $cnt = (int)Db::name('push_subscription')->where('user_id', $userId)->count();
        if ($cnt >= self::MAX_PER_USER) {
            $evict = Db::name('push_subscription')
                ->where('user_id', $userId)
                ->order('subscription_id asc')
                ->limit($cnt - self::MAX_PER_USER + 1)
                ->column('subscription_id');
            if (!empty($evict)) {
                Db::name('push_subscription')->where('subscription_id', 'in', $evict)->delete();
            }
        }

        try {
            Db::name('push_subscription')->insert([
                'user_id'     => $userId,
                'endpoint'    => $endpoint,
                'p256dh'      => $p256dh,
                'auth'        => $auth,
                'user_agent'  => $ua,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } catch (\Exception $e) {
            Db::name('push_subscription')->where($where)->update([
                'p256dh'      => $p256dh,
                'auth'        => $auth,
                'user_agent'  => $ua,
                'update_time' => $now,
            ]);
        }
        return ['code' => 1, 'msg' => lang('save_ok')];
    }

    public static function isAllowedEndpoint($endpoint)
    {
        $p = parse_url($endpoint);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return false;
        }
        if (strtolower($p['scheme']) !== 'https') {
            return false;
        }
        $host = strtolower($p['host']);
        $hostNoBracket = trim($host, '[]');
        if (filter_var($hostNoBracket, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        if (preg_match('/^(0x[0-9a-f]+|\d+)$/i', $host)) {
            return false;
        }
        if (strpos($host, '.') === false) {
            return false;
        }
        foreach (self::$endpointHostWhitelist as $suffix) {
            if ($host === $suffix || substr($host, -(strlen($suffix) + 1)) === '.' . $suffix) {
                return true;
            }
        }
        return false;
    }

    public function deleteByEndpoint($endpoint, $userId = null)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') {
            return ['code' => 0, 'msg' => lang('param_err')];
        }
        $where = ['endpoint' => $endpoint];
        if ($userId !== null) {
            $where['user_id'] = intval($userId);
        }
        Db::name('push_subscription')->where($where)->delete();
        return ['code' => 1, 'msg' => lang('del_ok')];
    }

    public function listByUser($userId)
    {
        return Db::name('push_subscription')->where('user_id', intval($userId))->select();
    }

    public function listAll($limit = 0, $offset = 0)
    {
        $query = Db::name('push_subscription')->order('subscription_id asc');
        if ($limit > 0) {
            $query = $query->limit(intval($offset), intval($limit));
        }
        return $query->select();
    }

    public function listAfterId($lastId, $limit)
    {
        return Db::name('push_subscription')
            ->where('subscription_id', '>', intval($lastId))
            ->order('subscription_id asc')
            ->limit(intval($limit))
            ->select();
    }

    public function countValid()
    {
        return (int)Db::name('push_subscription')->count();
    }
}
