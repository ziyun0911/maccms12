<?php
namespace app\admin\controller;
use think\facade\Db;
use think\facade\Cache;
use app\common\util\Pinyin;

class ResourceHub extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
    }

    private function validateRemoteUrl($url)
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (empty($parts['host'])) {
            return false;
        }

        $host = $parts['host'];

        $ip = gethostbyname($host);

        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (strpos($ip, '169.254.') === 0) {
            return false;
        }

        return true;
    }

    private function fetchRemote($url)
    {
        if (!$this->validateRemoteUrl($url)) {
            return false;
        }
        return mac_curl_get($url, [], '', false);
    }

    const CLOUD_API_URL = 'https://api.maccms.ai/sites.json';
    const CLOUD_ENCRYPT_KEY = 'maccms_rh_2024_s3cr3t_k3y!@#$%^&';
    const CLOUD_CACHE_TTL = 10800;

    private function fetchCloudSites()
    {
        $cacheKey     = 'resource_hub_cloud_sites';
        $cacheHashKey = 'resource_hub_cloud_sites_hash';

        $cached = Cache::get($cacheKey);

        if (!empty($cached) && is_array($cached)) {
            return $cached;
        }

        try {
            $raw = @file_get_contents(self::CLOUD_API_URL);
            if (empty($raw)) {
                return $this->fallbackCloudCache($cacheKey);
            }

            $payload = json_decode($raw, true);
            if (!$payload || empty($payload['data']) || empty($payload['iv'])) {
                return $this->fallbackCloudCache($cacheKey);
            }

            $decrypted = openssl_decrypt(
                base64_decode($payload['data']),
                'AES-256-CBC',
                self::CLOUD_ENCRYPT_KEY,
                OPENSSL_RAW_DATA,
                base64_decode($payload['iv'])
            );

            if ($decrypted === false) {
                return $this->fallbackCloudCache($cacheKey);
            }

            $sites = json_decode($decrypted, true);

            if (!$this->validateCloudSitesFormat($sites)) {
                return $this->fallbackCloudCache($cacheKey);
            }

            $newHash = md5($decrypted);
            $oldHash = Cache::get($cacheHashKey);
            if ($oldHash === $newHash && !empty($cached)) {
                Cache::set($cacheKey, $cached, self::CLOUD_CACHE_TTL);
                return $cached;
            }

            Cache::set($cacheKey, $sites, self::CLOUD_CACHE_TTL);
            Cache::set($cacheKey . '_backup', $sites, self::CLOUD_CACHE_TTL * 10);
            Cache::set($cacheHashKey, $newHash, self::CLOUD_CACHE_TTL * 3);
            return $sites;
        } catch (\Exception $e) {
            return $this->fallbackCloudCache($cacheKey);
        }
    }

    private function fallbackCloudCache($cacheKey)
    {
        $old = Cache::get($cacheKey . '_backup');
        if (!empty($old) && is_array($old)) {
            Cache::set($cacheKey, $old, self::CLOUD_CACHE_TTL);
            return $old;
        }
        return [];
    }

    private function validateCloudSitesFormat($sites)
    {
        if (!is_array($sites) || empty($sites)) {
            return false;
        }

        $requiredFields = ['name', 'url'];

        foreach ($sites as $site) {
            if (!is_array($site)) {
                return false;
            }
            foreach ($requiredFields as $field) {
                if (!isset($site[$field]) || $site[$field] === '') {
                    return false;
                }
            }
            if (!preg_match('#^https?://#i', $site['url'])) {
                return false;
            }
        }

        return true;
    }

    private function loadCustomSites()
    {
        $file = APP_PATH . 'extra/resource_sites_custom.php';
        if (is_file($file)) {
            $data = include $file;
            return is_array($data) ? $data : [];
        }
        return [];
    }

    private function saveCustomSites(array $sites)
    {
        return mac_arr2file(APP_PATH . 'extra/resource_sites_custom.php', $sites);
    }

    private function loadBindConfig()
    {
        $file = APP_PATH . 'extra/bind.php';
        if (is_file($file)) {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            $data = include $file;
            return is_array($data) ? $data : [];
        }
        return [];
    }

    public function index()
    {
        $cloudSites = $this->fetchCloudSites();
        $customSites = $this->loadCustomSites();

        $categoryNames = [
            'main' => lang('admin/resourcehub/cat_main') ?: '综合资源站（推荐）',
            'vod' => lang('admin/resourcehub/cat_vod') ?: '影视资源站',
            'short' => lang('admin/resourcehub/cat_short') ?: '短剧资源站',
            'anime' => lang('admin/resourcehub/cat_anime') ?: '动漫资源站',
            'art' => lang('admin/resourcehub/cat_art') ?: '资讯资源站',
            'midnight' => lang('admin/resourcehub/cat_midnight') ?: '午夜资源站',
            'custom' => lang('admin/resourcehub/cat_custom') ?: '自定义资源站',
        ];

        $grouped = [];

        foreach ($cloudSites as $site) {
            $cat = $site['category'] ?? 'vod';
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [
                    'title' => $categoryNames[$cat] ?? $cat,
                    'sites' => [],
                ];
            }
            $grouped[$cat]['sites'][] = [
                'name' => $site['name'] ?? '',
                'url' => $site['url'] ?? '',
                'type' => $site['type'] ?? '2',
                'mid' => '1',
                'desc' => $site['remark'] ?? '',
                'recommend' => $site['recommend'] ?? 0,
                'verified' => $site['verified'] ?? false,
            ];
        }

        if (!empty($customSites)) {
            $grouped['custom'] = [
                'title' => $categoryNames['custom'],
                'sites' => [],
            ];
            foreach ($customSites as $site) {
                $grouped['custom']['sites'][] = [
                    'name' => $site['name'] ?? '',
                    'url' => $site['url'] ?? '',
                    'type' => $site['type'] ?? '2',
                    'mid' => $site['mid'] ?? '1',
                    'desc' => $site['desc'] ?? '',
                    'recommend' => 0,
                    'verified' => false,
                ];
            }
        }

        $ordered = [];
        foreach (['main', 'vod', 'short', 'anime', 'art', 'midnight', 'custom'] as $key) {
            if (isset($grouped[$key]) && !empty($grouped[$key]['sites'])) {
                $ordered[$key] = $grouped[$key];
            }
        }

        $cloudError = (empty($cloudSites) && empty($customSites));

        $this->assign('sites', $ordered);
        $this->assign('cloud_error', $cloudError);
        $this->assign('title', lang('admin/resourcehub/title'));
        return $this->fetch('resourcehub/index');
    }

    public function check()
    {
        $param = input();
        $url = $param['url'];
        if (empty($url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $test_url = $url . (strpos($url, '?') === false ? '?' : '&') . 'ac=list&pg=1';
        $html = $this->fetchRemote($test_url);
        if (empty($html)) {
            return json(['code' => 0, 'msg' => lang('admin/resourcehub/check_fail')]);
        }

        $json = json_decode($html, true);
        if ($json && isset($json['list'])) {
            return json(['code' => 1, 'msg' => lang('admin/resourcehub/check_ok'), 'data' => ['total' => $json['total'] ?? 0]]);
        }

        $xml = @simplexml_load_string($html);
        if ($xml) {
            return json(['code' => 1, 'msg' => lang('admin/resourcehub/check_ok'), 'data' => ['total' => (string)$xml->list->attributes()->recordcount]]);
        }

        return json(['code' => 0, 'msg' => lang('admin/resourcehub/check_fail')]);
    }

    public function getTypes()
    {
        $param = input();
        $url = $param['url'];
        $type = $param['type'] ?? '2';

        if (empty($url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $api_url = $url . (strpos($url, '?') === false ? '?' : '&') . 'ac=list&pg=1';
        $html = $this->fetchRemote($api_url);
        if (empty($html)) {
            return json(['code' => 0, 'msg' => lang('admin/resourcehub/get_types_fail')]);
        }

        $types = [];
        if ($type == '2') {
            $json = json_decode($html, true);
            if ($json && isset($json['class'])) {
                foreach ($json['class'] as $v) {
                    $name = mac_filter_xss(trim($v['type_name'] ?? ''));
                    if (empty($name) || mb_strlen($name) > 30) continue;
                    $types[] = [
                        'id' => $v['type_id'],
                        'name' => $name,
                        'pid' => $v['type_pid'] ?? 0,
                    ];
                }
            }
        } else {
            $xml = @simplexml_load_string($html);
            if ($xml && $xml->class) {
                foreach ($xml->class->ty as $v) {
                    $name = mac_filter_xss(trim((string)$v));
                    if (empty($name) || mb_strlen($name) > 30) continue;
                    $types[] = [
                        'id' => (string)$v->attributes()->id,
                        'name' => $name,
                        'pid' => 0,
                    ];
                }
            }
        }

        $local_types = (new \app\common\model\Type())->getCache('type_list');
        $local_type_names = [];
        if (!empty($local_types)) {
            foreach ($local_types as $lt) {
                $local_type_names[] = $lt['type_name'];
            }
        }

        foreach ($types as &$t) {
            $t['exists'] = in_array($t['name'], $local_type_names);
        }

        return json(['code' => 1, 'msg' => 'ok', 'data' => ['types' => $types, 'local_types' => $local_types]]);
    }

    public function syncTypes()
    {
        $param = input('post.');
        $type_names = $param['type_names'] ?? [];
        $pid = intval($param['pid'] ?? 0);

        if (empty($type_names)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        Cache::delete('cache_type');
        $local_types_db = Db::name('type')->select();
        $local_type_names = [];
        if (!empty($local_types_db)) {
            foreach ($local_types_db as $lt) {
                $local_type_names[$lt['type_name']] = $lt['type_id'];
            }
        }

        $added = 0;
        $skipped = 0;
        foreach ($type_names as $name) {
            $name = mac_filter_xss(trim($name));
            if (empty($name) || mb_strlen($name) > 30) continue;

            if (isset($local_type_names[$name])) {
                $skipped++;
                continue;
            }

            $exists = Db::name('type')->where('type_name', $name)->find();
            if ($exists) {
                $skipped++;
                $local_type_names[$name] = $exists['type_id'];
                continue;
            }

            $data = [
                'type_name' => $name,
                'type_en' => Pinyin::get($name),
                'type_pid' => $pid,
                'type_mid' => 1,
                'type_status' => 1,
                'type_sort' => 0,
                'type_extend' => '',
                'type_key' => '',
                'type_des' => '',
                'type_title' => '',
                'type_union' => '',
                'type_tpl' => '',
                'type_tpl_list' => '',
                'type_tpl_detail' => '',
                'type_tpl_play' => '',
                'type_tpl_down' => '',
                'type_logo' => '',
                'type_pic' => '',
            ];

            $res = Db::name('type')->insert($data);
            if ($res) {
                $added++;
                $local_type_names[$name] = Db::name('type')->getLastInsID();
            }
        }

        Cache::delete('cache_type');
        
        return json([
            'code' => 1,
            'msg' => sprintf(lang('admin/resourcehub/sync_types_ok'), $added, $skipped)
        ]);
    }

    public function autoBind()
    {
        $param = input('post.');
        $url = $param['url'] ?? '';
        $type = $param['type'] ?? '2';

        if (empty($url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $api_url = $url . (strpos($url, '?') === false ? '?' : '&') . 'ac=list&pg=1';
        $html = $this->fetchRemote($api_url);
        if (empty($html)) {
            return json(['code' => 0, 'msg' => lang('admin/resourcehub/get_types_fail')]);
        }

        $remote_types = [];
        if ($type == '2') {
            $json = json_decode($html, true);
            if ($json && isset($json['class'])) {
                foreach ($json['class'] as $v) {
                    $remote_types[$v['type_id']] = $v['type_name'];
                }
            }
        } else {
            $xml = @simplexml_load_string($html);
            if ($xml && $xml->class) {
                foreach ($xml->class->ty as $v) {
                    $remote_types[(string)$v->attributes()->id] = (string)$v;
                }
            }
        }

        if (empty($remote_types)) {
            return json(['code' => 0, 'msg' => lang('admin/resourcehub/no_remote_types')]);
        }

        Cache::delete('cache_type');
        $local_types_db = Db::name('type')->select();
        $local_type_map = [];
        $local_type_list = [];
        if (!empty($local_types_db)) {
            foreach ($local_types_db as $lt) {
                $local_type_map[$lt['type_name']] = $lt['type_id'];
                $local_type_list[] = $lt;
            }
        }

        $config = config('maccms.collect');
        $custom_rules = [];
        if (!empty($config['vod_type_map'])) {
            $rules = explode("\n", $config['vod_type_map']);
            foreach ($rules as $rule) {
                $rule = trim($rule);
                if (empty($rule) || strpos($rule, '=') === false) continue;
                list($local_name, $remote_name) = explode('=', $rule, 2);
                $custom_rules[trim($remote_name)] = trim($local_name);
            }
        }

        $cjflag = md5($url);
        $bind_config = $this->loadBindConfig();
        $bound = 0;
        $changed = false;

        foreach ($remote_types as $remote_id => $remote_name) {
            $bind_key = $cjflag . '_' . $remote_id;

            if (isset($bind_config[$bind_key]) && $bind_config[$bind_key] > 0) {
                $bound++;
                continue;
            }

            if (isset($local_type_map[$remote_name])) {
                $bind_config[$bind_key] = $local_type_map[$remote_name];
                $bound++;
                $changed = true;
                continue;
            }

            if (isset($custom_rules[$remote_name]) && isset($local_type_map[$custom_rules[$remote_name]])) {
                $bind_config[$bind_key] = $local_type_map[$custom_rules[$remote_name]];
                $bound++;
                $changed = true;
                continue;
            }

            $matched_id = $this->fuzzyMatchType($remote_name, $local_type_list);
            if ($matched_id > 0) {
                $bind_config[$bind_key] = $matched_id;
                $bound++;
                $changed = true;
                continue;
            }
        }

        if ($changed) {
            $res = mac_arr2file(APP_PATH . 'extra/bind.php', $bind_config);
            if ($res === false) {
                return json(['code' => 0, 'msg' => lang('write_err_config')]);
            }
        }

        return json([
            'code' => 1,
            'msg' => sprintf(lang('admin/resourcehub/auto_bind_ok'), $bound, count($remote_types))
        ]);
    }

    private function fuzzyMatchType($remote_name, $local_type_list)
    {
        $remote_name = trim($remote_name);
        if (empty($remote_name)) return 0;

        $suffixes = ['片', '劇', '剧', '类', '類'];
        $remote_stripped = $remote_name;
        foreach ($suffixes as $suffix) {
            if (mb_substr($remote_name, -mb_strlen($suffix)) === $suffix && mb_strlen($remote_name) > mb_strlen($suffix)) {
                $remote_stripped = mb_substr($remote_name, 0, -mb_strlen($suffix));
                break;
            }
        }

        $synonyms_file = APP_PATH . 'extra/type_synonyms.php';
        $synonyms = is_file($synonyms_file) ? include $synonyms_file : [];

        foreach ($local_type_list as $lt) {
            $local_name = $lt['type_name'];
            $local_id = $lt['type_id'];

            $local_stripped = $local_name;
            foreach ($suffixes as $suffix) {
                if (mb_substr($local_name, -mb_strlen($suffix)) === $suffix && mb_strlen($local_name) > mb_strlen($suffix)) {
                    $local_stripped = mb_substr($local_name, 0, -mb_strlen($suffix));
                    break;
                }
            }

            if ($remote_stripped === $local_stripped && !empty($remote_stripped)) {
                return $local_id;
            }
            if ($remote_stripped === $local_name || $remote_name === $local_stripped) {
                return $local_id;
            }
        }

        foreach ($local_type_list as $lt) {
            $local_name = $lt['type_name'];
            $local_id = $lt['type_id'];

            $local_stripped = $local_name;
            foreach ($suffixes as $suffix) {
                if (mb_substr($local_name, -mb_strlen($suffix)) === $suffix && mb_strlen($local_name) > mb_strlen($suffix)) {
                    $local_stripped = mb_substr($local_name, 0, -mb_strlen($suffix));
                    break;
                }
            }

            foreach ($synonyms as $key => $values) {
                $all_names = array_merge([$key], $values);
                $remote_in = in_array($remote_name, $all_names) || in_array($remote_stripped, $all_names);
                $local_in = in_array($local_name, $all_names) || in_array($local_stripped, $all_names);
                if ($remote_in && $local_in) {
                    return $local_id;
                }
            }
        }

        foreach ($local_type_list as $lt) {
            $local_name = $lt['type_name'];
            $local_id = $lt['type_id'];

            if (mb_strlen($remote_name) >= 3 && mb_strlen($local_name) >= 3) {
                $shortLen = min(mb_strlen($remote_name), mb_strlen($local_name));
                $longLen = max(mb_strlen($remote_name), mb_strlen($local_name));
                if ($longLen > 0 && ($shortLen / $longLen) >= 0.6) {
                    if (mb_strpos($remote_name, $local_name) !== false || mb_strpos($local_name, $remote_name) !== false) {
                        return $local_id;
                    }
                }
            }

            $local_stripped = $local_name;
            foreach ($suffixes as $suffix) {
                if (mb_substr($local_name, -mb_strlen($suffix)) === $suffix && mb_strlen($local_name) > mb_strlen($suffix)) {
                    $local_stripped = mb_substr($local_name, 0, -mb_strlen($suffix));
                    break;
                }
            }

            if (mb_strlen($remote_stripped) >= 3 && mb_strlen($local_stripped) >= 3) {
                $shortLen = min(mb_strlen($remote_stripped), mb_strlen($local_stripped));
                $longLen = max(mb_strlen($remote_stripped), mb_strlen($local_stripped));
                if ($longLen > 0 && ($shortLen / $longLen) >= 0.6) {
                    if (mb_strpos($remote_stripped, $local_stripped) !== false || mb_strpos($local_stripped, $remote_stripped) !== false) {
                        return $local_id;
                    }
                }
            }
        }

        return 0;
    }

    public function autoPlayer()
    {
        $param = input('post.');
        $url = $param['url'] ?? '';
        $type = $param['type'] ?? '2';

        if (empty($url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $api_url = $url . (strpos($url, '?') === false ? '?' : '&') . 'ac=detail&pg=1';
        $html = $this->fetchRemote($api_url);
        if (empty($html)) {
            return json(['code' => 0, 'msg' => lang('admin/resourcehub/get_data_fail')]);
        }

        $player_froms = [];
        if ($type == '2') {
            $json = json_decode($html, true);
            if ($json && !empty($json['list'])) {
                foreach ($json['list'] as $item) {
                    if (!empty($item['vod_play_from'])) {
                        $froms = explode('$$$', $item['vod_play_from']);
                        foreach ($froms as $from) {
                            $from = trim($from);
                            if (!empty($from) && !in_array($from, $player_froms)) {
                                $player_froms[] = $from;
                            }
                        }
                    }
                }
            }
        } else {
            $xml = @simplexml_load_string($html);
            if ($xml && $xml->list->video) {
                foreach ($xml->list->video as $video) {
                    $dt = (string)$video->dt;
                    if (!empty($dt)) {
                        $froms = explode('$$$', $dt);
                        foreach ($froms as $from) {
                            $from = trim($from);
                            if (!empty($from) && !in_array($from, $player_froms)) {
                                $player_froms[] = $from;
                            }
                        }
                    }
                }
            }
        }

        if (empty($player_froms)) {
            return json(['code' => 0, 'msg' => lang('admin/resourcehub/no_player_found')]);
        }

        $vodplayer_list = config('vodplayer') ?: [];
        $existing_froms = array_keys($vodplayer_list);

        $added = 0;
        foreach ($player_froms as $from) {
            if (in_array($from, $existing_froms)) {
                continue;
            }

            if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $from)) {
                continue;
            }

            $player_data = [
                'status' => '1',
                'from' => $from,
                'show' => $from . '播放器',
                'des' => '在线播放',
                'target' => '_self',
                'ps' => '0',
                'parse' => '',
                'sort' => '800',
                'tip' => '无需安装任何插件',
                'id' => $from,
            ];

            $vodplayer_list[$from] = $player_data;
            $added++;

            $js_content = $this->generatePlayerJs($from);
            $js_path = ROOT_PATH . 'static/player/' . $from . '.js';
            if (!file_exists($js_path)) {
                @file_put_contents($js_path, $js_content);
            }
        }

        if ($added > 0) {
            $res = mac_arr2file(APP_PATH . 'extra/vodplayer.php', $vodplayer_list);
            if ($res === false) {
                return json(['code' => 0, 'msg' => lang('write_err_config')]);
            }
        }

        return json([
            'code' => 1,
            'msg' => sprintf(lang('admin/resourcehub/auto_player_ok'), $added, count($player_froms)),
            'data' => ['players' => $player_froms, 'added' => $added]
        ]);
    }

    private function generatePlayerJs($from)
    {
        return 'MacPlayer.Html = \'<iframe border="0" src="\'+maccms.path+\'/static/player/dplayer.html" width="100%" height="100%" marginWidth="0" frameSpacing="0" marginHeight="0" frameBorder="0" scrolling="no" vspale="0" noResize></iframe>\';
MacPlayer.Show();
';
    }

    public function addToCollect()
    {
        $param = input('post.');
        $name = $param['name'] ?? '';
        $url = $param['url'] ?? '';
        $type = $param['type'] ?? '2';
        $mid = $param['mid'] ?? '1';

        if (empty($name) || empty($url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $exists = Db::name('collect')->where('collect_url', $url)->find();
        if ($exists) {
            return json(['code' => 0, 'msg' => lang('admin/resourcehub/already_exists')]);
        }

        $data = [
            'collect_name' => $name,
            'collect_url' => $url,
            'collect_type' => $type,
            'collect_mid' => $mid,
            'collect_opt' => '1',
            'collect_filter' => '0',
            'collect_filter_from' => '',
            'collect_filter_year' => '',
            'collect_sync_pic_opt' => '0',
            'collect_param' => '',
        ];

        $res = Db::name('collect')->insert($data);
        if ($res === false) {
            return json(['code' => 0, 'msg' => lang('save_err')]);
        }

        return json(['code' => 1, 'msg' => lang('admin/resourcehub/add_collect_ok')]);
    }

    public function addTimming()
    {
        $param = input('post.');
        $name = $param['name'] ?? '';
        $url = $param['url'] ?? '';
        $type = $param['type'] ?? '2';
        $mid = $param['mid'] ?? '1';
        $hours = $param['hours'] ?? '2';

        if (empty($name) || empty($url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $cjflag = md5($url);
        $task_name = 'resourcehub_' . $cjflag;

        $collect_url = http_build_query([
            'ac' => 'cj',
            'cjflag' => $cjflag,
            'cjurl' => $url,
            'h' => $hours,
            't' => '',
            'ids' => '',
            'wd' => '',
            'type' => $type,
            'mid' => $mid,
            'opt' => '1',
            'sync_pic_opt' => '0',
            'filter' => '0',
            'filter_from' => '',
            'filter_year' => '',
            'param' => '',
        ]);

        $timming_list = config('timming') ?: [];

        if (isset($timming_list[$task_name])) {
            return json(['code' => 0, 'msg' => lang('admin/resourcehub/timming_exists')]);
        }

        $timming_list[$task_name] = [
            'id' => $task_name,
            'status' => '1',
            'name' => $task_name,
            'des' => '自动采集 - ' . $name,
            'file' => 'collect',
            'param' => $collect_url,
            'weeks' => '1,2,3,4,5,6,0',
            'hours' => '00,01,02,03,04,05,06,07,08,09,10,11,12,13,14,15,16,17,18,19,20,21,22,23',
            'runtime' => 0,
        ];

        $res = mac_arr2file(APP_PATH . 'extra/timming.php', $timming_list);
        if ($res === false) {
            return json(['code' => 0, 'msg' => lang('write_err_config')]);
        }

        return json(['code' => 1, 'msg' => lang('admin/resourcehub/add_timming_ok')]);
    }

    public function quickCollect()
    {
        if (!$this->request->isPost()) {
            return json(['code' => 0, 'msg' => lang('illegal_request')]);
        }

        $param = input('post.');
        $url = $param['url'] ?? '';
        $type = $param['type'] ?? '2';
        $mid = $param['mid'] ?? '1';
        $h = $param['h'] ?? '24';

        if (empty($url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        if (!$this->validateRemoteUrl($url)) {
            return json(['code' => 0, 'msg' => lang('admin/resourcehub/check_fail')]);
        }

        $cjflag = md5($url);

        $this->autoBindSilent($url, $type);

        $this->autoPlayerSilent($url, $type);

        $collect_params = [
            'ac' => 'list',
            'cjflag' => $cjflag,
            'cjurl' => $url,
            'h' => $h,
            't' => '',
            'ids' => '',
            'wd' => '',
            'type' => $type,
            'mid' => $mid,
            'opt' => '1',
            'sync_pic_opt' => '0',
            'filter' => '0',
            'filter_from' => '',
            'filter_year' => '',
            'param' => '',
        ];

        $redirect_url = url('collect/api') . '?' . http_build_query($collect_params);
        return $this->redirect($redirect_url);
    }

    private function autoBindSilent($url, $type)
    {
        $api_url = $url . (strpos($url, '?') === false ? '?' : '&') . 'ac=list&pg=1';
        $html = $this->fetchRemote($api_url);
        if (empty($html)) return;

        $remote_types = [];
        if ($type == '2') {
            $json = json_decode($html, true);
            if ($json && isset($json['class'])) {
                foreach ($json['class'] as $v) {
                    $remote_types[$v['type_id']] = $v['type_name'];
                }
            }
        } else {
            $xml = @simplexml_load_string($html);
            if ($xml && $xml->class) {
                foreach ($xml->class->ty as $v) {
                    $remote_types[(string)$v->attributes()->id] = (string)$v;
                }
            }
        }

        if (empty($remote_types)) return;

        Cache::delete('cache_type');
        $local_types_db = Db::name('type')->select();
        $local_type_map = [];
        $local_type_list = [];
        if (!empty($local_types_db)) {
            foreach ($local_types_db as $lt) {
                $local_type_map[$lt['type_name']] = $lt['type_id'];
                $local_type_list[] = $lt;
            }
        }

        $cjflag = md5($url);
        $bind_config = $this->loadBindConfig();
        $changed = false;

        foreach ($remote_types as $remote_id => $remote_name) {
            $bind_key = $cjflag . '_' . $remote_id;
            if (isset($bind_config[$bind_key]) && $bind_config[$bind_key] > 0) continue;

            if (isset($local_type_map[$remote_name])) {
                $bind_config[$bind_key] = $local_type_map[$remote_name];
                $changed = true;
                continue;
            }

            $matched_id = $this->fuzzyMatchType($remote_name, $local_type_list);
            if ($matched_id > 0) {
                $bind_config[$bind_key] = $matched_id;
                $changed = true;
            }
        }

        if ($changed) {
            mac_arr2file(APP_PATH . 'extra/bind.php', $bind_config);
        }
    }

    private function autoPlayerSilent($url, $type)
    {
        $api_url = $url . (strpos($url, '?') === false ? '?' : '&') . 'ac=detail&pg=1';
        $html = $this->fetchRemote($api_url);
        if (empty($html)) return;

        $player_froms = [];
        if ($type == '2') {
            $json = json_decode($html, true);
            if ($json && !empty($json['list'])) {
                foreach ($json['list'] as $item) {
                    if (!empty($item['vod_play_from'])) {
                        $froms = explode('$$$', $item['vod_play_from']);
                        foreach ($froms as $from) {
                            $from = trim($from);
                            if (!empty($from) && !in_array($from, $player_froms)) {
                                $player_froms[] = $from;
                            }
                        }
                    }
                }
            }
        }

        if (empty($player_froms)) return;

        $vodplayer_list = config('vodplayer') ?: [];
        $existing_froms = array_keys($vodplayer_list);
        $changed = false;

        foreach ($player_froms as $from) {
            if (in_array($from, $existing_froms)) continue;

            if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $from)) {
                continue;
            }

            $vodplayer_list[$from] = [
                'status' => '1',
                'from' => $from,
                'show' => $from . '播放器',
                'des' => '在线播放',
                'target' => '_self',
                'ps' => '0',
                'parse' => '',
                'sort' => '800',
                'tip' => '无需安装任何插件',
                'id' => $from,
            ];
            $changed = true;

            $js_path = ROOT_PATH . 'static/player/' . $from . '.js';
            if (!file_exists($js_path)) {
                @file_put_contents($js_path, $this->generatePlayerJs($from));
            }
        }

        if ($changed) {
            mac_arr2file(APP_PATH . 'extra/vodplayer.php', $vodplayer_list);
        }
    }

    public function addCustomSite()
    {
        $param = input('post.');
        $name = trim($param['name'] ?? '');
        $url = trim($param['url'] ?? '');
        $type = $param['type'] ?? '2';
        $mid = $param['mid'] ?? '1';
        $desc = trim($param['desc'] ?? '');

        if (empty($name) || empty($url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $sites = $this->loadCustomSites();
        $sites[] = [
            'name' => $name,
            'url' => $url,
            'type' => $type,
            'mid' => $mid,
            'desc' => $desc,
        ];

        $res = $this->saveCustomSites($sites);
        if ($res === false) {
            return json(['code' => 0, 'msg' => lang('write_err_config')]);
        }

        return json(['code' => 1, 'msg' => lang('admin/resourcehub/add_site_ok')]);
    }

    public function delCustomSite()
    {
        $param = input('post.');
        $index = intval($param['index'] ?? -1);

        if ($index < 0) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $sites = $this->loadCustomSites();
        if (isset($sites[$index])) {
            array_splice($sites, $index, 1);
            $res = $this->saveCustomSites($sites);
            if ($res === false) {
                return json(['code' => 0, 'msg' => lang('write_err_config')]);
            }
        }

        return json(['code' => 1, 'msg' => lang('del_ok')]);
    }

    public function multiCollect()
    {
        $param = input();
        $this->assign('param', $param);
        $this->assign('title', lang('admin/resourcehub/multi_collect'));
        return $this->fetch('resourcehub/multi_collect');
    }

    public function poster()
    {
        $param = input();
        $param['page'] = intval($param['page'] ?? 1) < 1 ? 1 : intval($param['page']);
        $limit = intval($param['limit'] ?? 40);
        if ($limit < 1) $limit = 40;
        if ($limit > 200) $limit = 200;
        $param['limit'] = $limit;
        $filter = $param['filter'] ?? 'all';

        $where = [];
        $where['vod_status'] = ['eq', 1];
        $where['vod_pic'] = ['neq', ''];

        if ($filter == 'recommended') {
            $where['vod_level'] = ['gt', 0];
        } elseif ($filter == 'not_recommended') {
            $where['vod_level'] = ['eq', 0];
        }

        $order = 'vod_level desc, vod_hits desc';
        $list = Db::name('vod')->where($where)->order($order)->page($param['page'])->limit($param['limit'])->select();
        $total = Db::name('vod')->where($where)->count();

        $recommended_count = Db::name('vod')->where(['vod_status' => ['eq', 1], 'vod_pic' => ['neq', ''], 'vod_level' => ['gt', 0]])->count();

        $this->assign('list', $list);
        $this->assign('total', $total);
        $this->assign('page', $param['page']);
        $this->assign('limit', $param['limit']);
        $this->assign('filter', $filter);
        $this->assign('recommended_count', $recommended_count);
        $this->assign('title', lang('admin/resourcehub/poster_title'));
        return $this->fetch('resourcehub/poster');
    }

    public function setRecommend()
    {
        $param = input('post.');
        $ids = $param['ids'] ?? '';
        $level = intval($param['level'] ?? 9);

        if (empty($ids)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $res = Db::name('vod')->where('vod_id', 'in', $ids)->update(['vod_level' => $level]);
        if ($res === false) {
            return json(['code' => 0, 'msg' => lang('save_err')]);
        }

        return json(['code' => 1, 'msg' => lang('admin/resourcehub/set_recommend_ok')]);
    }

    public function previewDuplicateTypes()
    {
        $duplicates = Db::name('type')
            ->field('type_name, type_pid, type_mid, COUNT(*) as cnt')
            ->group('type_name, type_pid, type_mid')
            ->having('cnt > 1')
            ->select();

        if (empty($duplicates)) {
            return json(['code' => 1, 'msg' => '没有发现重复的分类', 'data' => []]);
        }

        $groups = [];
        foreach ($duplicates as $dup) {
            $items = Db::name('type')
                ->where('type_name', $dup['type_name'])
                ->where('type_pid', $dup['type_pid'])
                ->where('type_mid', $dup['type_mid'])
                ->order('type_id asc')
                ->select();

            $group = [
                'type_name' => $dup['type_name'],
                'type_pid' => $dup['type_pid'],
                'type_mid' => $dup['type_mid'],
                'items' => [],
            ];

            foreach ($items as $item) {
                $tid = $item['type_id'];

                $vod_count = Db::name('vod')->where('type_id', $tid)->count();
                $vod_count_1 = Db::name('vod')->where('type_id_1', $tid)->count();
                $child_count = Db::name('type')->where('type_pid', $tid)->count();

                $maintained = (
                    !empty($item['type_sort']) ||
                    !empty($item['type_logo']) ||
                    !empty($item['type_pic']) ||
                    !empty($item['type_des']) ||
                    !empty($item['type_title']) ||
                    !empty($item['type_tpl']) ||
                    !empty($item['type_tpl_list']) ||
                    !empty($item['type_tpl_detail']) ||
                    !empty($item['type_tpl_play']) ||
                    !empty($item['type_tpl_down']) ||
                    !empty($item['type_extend'])
                );

                $group['items'][] = [
                    'type_id' => $tid,
                    'type_name' => $item['type_name'],
                    'type_en' => $item['type_en'],
                    'type_pid' => $item['type_pid'],
                    'type_mid' => $item['type_mid'],
                    'type_sort' => $item['type_sort'],
                    'vod_count' => $vod_count + $vod_count_1,
                    'child_count' => $child_count,
                    'maintained' => $maintained,
                ];
            }

            usort($group['items'], function ($a, $b) {
                if ($a['maintained'] !== $b['maintained']) {
                    return $b['maintained'] - $a['maintained'];
                }
                return $b['vod_count'] - $a['vod_count'];
            });
            $group['recommended_keep'] = $group['items'][0]['type_id'];

            $groups[] = $group;
        }

        return json(['code' => 1, 'msg' => sprintf('发现 %d 组重复分类', count($groups)), 'data' => $groups]);
    }

    public function cleanDuplicateTypes()
    {
        $raw = file_get_contents('php://input');
        $param = json_decode($raw, true);
        if (empty($param)) {
            $param = input('post.');
        }
        $actions = $param['actions'] ?? [];

        if (empty($actions) || !is_array($actions)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }

        $backup_data = [];
        $timestamp = date('Ymd_His');
        $total_deleted = 0;
        $total_updated_vod = 0;

        foreach ($actions as $action) {
            $keep_id = intval($action['keep_id'] ?? 0);
            $delete_ids = $action['delete_ids'] ?? [];

            if ($keep_id <= 0 || empty($delete_ids)) {
                continue;
            }

            $keep_type = Db::name('type')->where('type_id', $keep_id)->find();
            if (!$keep_type) {
                continue;
            }

            foreach ($delete_ids as $del_id) {
                $del_id = intval($del_id);
                if ($del_id <= 0 || $del_id === $keep_id) {
                    continue;
                }

                $del_type = Db::name('type')->where('type_id', $del_id)->find();
                if (!$del_type) {
                    continue;
                }

                $backup_data[] = $del_type;

                $vod_updated = Db::name('vod')->where('type_id', $del_id)->update(['type_id' => $keep_id]);
                $total_updated_vod += ($vod_updated ?: 0);

                Db::name('vod')->where('type_id_1', $del_id)->update(['type_id_1' => $keep_id]);

                Db::name('type')->where('type_pid', $del_id)->update(['type_pid' => $keep_id]);

                Db::name('type')->where('type_id', $del_id)->delete();
                $total_deleted++;
            }
        }

        if (!empty($backup_data)) {
            $log_dir = runtime_path() . 'log/';
            if (!is_dir($log_dir)) {
                @mkdir($log_dir, 0755, true);
            }
            $log_file = $log_dir . 'clean_type_' . $timestamp . '.log';
            $log_content = "=== 分类清理备份 ===\n";
            $log_content .= "时间: " . date('Y-m-d H:i:s') . "\n";
            $log_content .= "删除数量: " . $total_deleted . "\n";
            $log_content .= "影响视频: " . $total_updated_vod . "\n\n";
            $log_content .= json_encode($backup_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            @file_put_contents($log_file, $log_content);
        }

        Cache::delete('cache_type');

        return json([
            'code' => 1,
            'msg' => sprintf('清理完成！删除了 %d 个重复分类，更新了 %d 条视频记录', $total_deleted, $total_updated_vod)
        ]);
    }
}
