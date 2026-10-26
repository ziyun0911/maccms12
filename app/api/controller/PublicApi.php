<?php

namespace app\api\controller;

trait PublicApi
{
    public function check_config()
    {
        if ($GLOBALS['config']['api']['publicapi']['status'] != 1) {
            echo 'closed';
            die;
        }

        if ($GLOBALS['config']['api']['publicapi']['charge'] == 1) {
            $h = $_SERVER['REMOTE_ADDR'];
            if (!$h) {
                echo lang('api/auth_err');
                exit;
            } else {
                $auth = $GLOBALS['config']['api']['publicapi']['auth'];
                $this->checkDomainAuth($auth);
            }
        }
    }

    private function checkDomainAuth($auth)
    {
        $ip = mac_get_client_ip();
        $auth_list = ['127.0.0.1'];
        if (!empty($auth)) {
            foreach (explode('#', $auth) as $domain) {
                $domain = trim($domain);
                $auth_list[] = $domain;
                if (!mac_string_is_ip($domain)) {
                    $auth_list[] = gethostbyname($domain);
                }
            }
            $auth_list = array_unique($auth_list);
            $auth_list = array_filter($auth_list);
        }
        if (!in_array($ip, $auth_list)) {
            echo lang('api/auth_err');
            exit;
        }
    }

    protected function format_sql_string($str)
    {
        $str = preg_replace('/\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|WHERE|FROM|JOIN|INTO|VALUES|SET|AND|OR|NOT|EXISTS|HAVING|GROUP BY|ORDER BY|LIMIT|OFFSET)\b/i', '', $str);
        $str = preg_replace('/[^\p{L}\p{N}\s\-\.]/u', '', $str);
        $str = trim(preg_replace('/\s+/', ' ', $str));
        return $str;
    }

    protected function apiRateLimit($action, $user_id, $limit, $window)
    {
        $buckets = [
            'api_' . $action . '_rl_u_' . intval($user_id),
            'api_' . $action . '_rl_ip_' . mac_get_client_ip(),
        ];
        foreach ($buckets as $key) {
            if (!$this->rateBucketHit($key, $limit, $window)) {
                return json(['code' => 1020, 'msg' => lang('anti_scrape/rate_limited', [(string) $window])]);
            }
        }
        return true;
    }

    private function rateBucketHit($key, $limit, $window)
    {
        try {
            $handler = \think\facade\Cache::store()->handler();
            if (class_exists('\Redis', false) && $handler instanceof \Redis) {
                $cnt = (int) $handler->incr($key);
                if ($cnt === 1 || $handler->ttl($key) < 0) {
                    $handler->expire($key, $window);
                }
                return $cnt <= $limit;
            }
        } catch (\Throwable $e) {
        }
        $cnt = (int) \think\facade\Cache::get($key, 0);
        if ($cnt >= $limit) {
            return false;
        }
        \think\facade\Cache::set($key, $cnt + 1, (int)$window);
        return true;
    }
}