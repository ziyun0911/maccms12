<?php
namespace app\api\controller;

use app\common\util\MonitorCron;

class Monitor extends Base
{
    public function cron()
    {
        $cfg = isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor']
            : [];

        $token = isset($cfg['cron_token']) ? trim((string)$cfg['cron_token']) : '';
        $given = (string)input('token/s', '');

        if ($token === '' || strlen($token) < 16) {
            return json(['code' => 0, 'msg' => 'monitor cron_token not configured'], 403);
        }

        if (!hash_equals($token, $given)) {
            return json(['code' => 0, 'msg' => 'forbidden'], 403);
        }

        if (empty($cfg['enabled']) || (string)$cfg['enabled'] !== '1') {
            return json(['code' => 1, 'msg' => 'monitor disabled', 'data' => []]);
        }

        return json(MonitorCron::run());
    }
}
