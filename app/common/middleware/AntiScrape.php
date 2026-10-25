<?php
namespace app\common\middleware;

use Closure;
use app\common\util\SlidingWindowIpLimiter;
use think\exception\HttpResponseException;
use think\Request;
use think\Response;

/**
 * 苹果CMS v12 —— 防爬虫中间件
 * （由 TP5 的 common\behavior\AntiScrape 迁移）
 *
 * 开放 API 与前台 Ajax 的 IP 频率限制。
 */
class AntiScrape
{
    public function handle(Request $request, Closure $next)
    {
        if (PHP_SAPI === 'cli') {
            return $next($request);
        }
        $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app'])
            ? $GLOBALS['config']['app']
            : [];
        $req = request();

        if (defined('ENTRANCE') && ENTRANCE === 'index') {
            $this->runIndexAjax($req, $app);
        }
        if (defined('ENTRANCE') && ENTRANCE === 'api') {
            $this->runApi($req, $app);
        }

        return $next($request);
    }

    private function runIndexAjax(Request $req, array $app)
    {
        if (empty($app['anti_scrape_index_enabled']) || (string)$app['anti_scrape_index_enabled'] !== '1') {
            return;
        }
        $pi = strtolower(trim((string)$req->pathinfo(), '/'));
        if ($pi !== '' && strpos($pi, 'install') === 0) {
            return;
        }
        list($c, $a) = $this->pathControllerAction($req);
        if ($c !== 'ajax') {
            return;
        }
        $hits = ['suggest', 'data', 'search_hot', 'search_history'];
        if (!in_array($a, $hits, true)) {
            return;
        }
        $window = isset($app['anti_scrape_index_window_sec']) ? (int)$app['anti_scrape_index_window_sec'] : 60;
        $max = isset($app['anti_scrape_index_max']) ? (int)$app['anti_scrape_index_max'] : 90;
        $ip = (string)mac_get_client_ip();
        $r = SlidingWindowIpLimiter::checkHit($ip, 'ix_' . $a, $window, $max);
        if (!$r['allowed']) {
            $this->deny($r['retry_after']);
        }
    }

    private function runApi(Request $req, array $app)
    {
        if (empty($app['anti_scrape_api_enabled']) || (string)$app['anti_scrape_api_enabled'] !== '1') {
            return;
        }
        list($c, $a) = $this->pathControllerAction($req);
        $route = $c . '/' . $a;
        if ($route === '/' || $c === '') {
            return;
        }

        // 监控 cron 端点必须写死（站长运行期配置可能缺失该豁免项）
        $exempt = ['timming/index', 'monitor/cron'];
        $extra = isset($app['anti_scrape_api_exempt']) ? trim((string)$app['anti_scrape_api_exempt']) : '';
        if ($extra !== '') {
            foreach (explode(',', $extra) as $one) {
                $one = strtolower(trim(str_replace('\\', '/', $one)));
                if ($one !== '') {
                    $exempt[] = $one;
                }
            }
        }
        if (in_array($route, array_unique($exempt), true)) {
            return;
        }

        $ip = (string)mac_get_client_ip();
        $gw = isset($app['anti_scrape_api_window_sec']) ? (int)$app['anti_scrape_api_window_sec'] : 60;
        $gm = isset($app['anti_scrape_api_max']) ? (int)$app['anti_scrape_api_max'] : 120;
        $r1 = SlidingWindowIpLimiter::checkHit($ip, 'api_all', $gw, $gm);
        if (!$r1['allowed']) {
            $this->deny($r1['retry_after']);
        }

        if ($this->apiSearchHeavy($req)) {
            $sw = isset($app['anti_scrape_api_search_window_sec']) ? (int)$app['anti_scrape_api_search_window_sec'] : 60;
            $sm = isset($app['anti_scrape_api_search_max']) ? (int)$app['anti_scrape_api_search_max'] : 30;
            $r2 = SlidingWindowIpLimiter::checkHit($ip, 'api_search', $sw, $sm);
            if (!$r2['allowed']) {
                $this->deny($r2['retry_after']);
            }
        }
    }

    /**
     * @return array{0:string,1:string} controller, action lower
     */
    private function pathControllerAction(Request $req)
    {
        $pi = strtolower(trim((string)$req->pathinfo(), '/'));
        $pi = preg_replace('/\.(html|htm)$/i', '', $pi);
        if ($pi === '') {
            return ['', ''];
        }
        $parts = array_values(array_filter(explode('/', $pi), static function ($p) {
            return $p !== '';
        }));
        if (count($parts) >= 2) {
            return [strtolower($parts[0]), strtolower($parts[1])];
        }
        if (count($parts) === 1) {
            return [strtolower($parts[0]), 'index'];
        }
        return ['', ''];
    }

    private function apiSearchHeavy(Request $req)
    {
        if (trim((string)$req->param('wd', '')) !== '') {
            return true;
        }
        $keys = [
            'vod_name', 'art_name', 'actor_name', 'website_name', 'manga_name', 'topic_name',
            'vod_actor', 'vod_director', 'vod_blurb', 'art_tag', 'vod_tag',
        ];
        foreach ($keys as $k) {
            if (trim((string)$req->param($k, '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function markBlocked($w = 1)
    {
        try {
            $cfg = isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
                ? $GLOBALS['config']['monitor'] : [];
            if (empty($cfg['access_track_enabled']) || (string)$cfg['access_track_enabled'] !== '1') {
                return;
            }
            \app\common\util\MonitorBucket::addIp((string)mac_get_client_ip(), ['blk' => (int)$w]);
        } catch (\Throwable $e) {
        }
    }

    private function deny($retryAfter)
    {
        $this->markBlocked();
        $retryAfter = max(1, (int)$retryAfter);
        $msg = function_exists('lang')
            ? sprintf(lang('anti_scrape/rate_limited'), $retryAfter)
            : ('Too many requests, retry after ' . $retryAfter . ' seconds');
        throw new HttpResponseException(json([
            'code' => 100429,
            'msg'  => $msg,
            'data' => ['retry_after' => $retryAfter],
        ], 429, ['Retry-After' => (string)$retryAfter]));
    }
}
