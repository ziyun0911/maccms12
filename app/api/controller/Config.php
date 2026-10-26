<?php
namespace app\api\controller;

use think\Request;
use think\facade\Db;

class Config extends Base
{
    use PublicApi;
    public function __construct()
    {
        parent::__construct();
        $this->check_config();
    }

    private function resolveTemplateDir()
    {
        $config = config('maccms');
        $site   = isset($config['site']) ? $config['site'] : [];
        $dir    = isset($site['site_tpl_dir']) ? trim((string) $site['site_tpl_dir']) : '';
        if ($dir === '') {
            $dir = isset($site['template_dir']) ? trim((string) $site['template_dir']) : '';
        }
        return $dir !== '' ? $dir : 'default';
    }

    public function get_config(Request $request)
    {
        $config = config('maccms');

        $banners = isset($config['site']['site_banner']) ? $config['site']['site_banner'] : '';
        $banner_list = [];
        if (!empty($banners)) {
            $banner_list = explode("\n", $banners);
            foreach ($banner_list as $k => &$v) {
                $v = mac_url_img($v);
            }
        }

        $res = [
            'code' => 1,
            'msg' => '获取成功',
            'data' => [
                'site_banner' => $banner_list,
                'site_app_launch_image' => isset($config['site']['site_app_launch_image']) ? mac_url_img($config['site']['site_app_launch_image']) : '',
            ]
        ];
        return json($res)->options(['json_encode_param' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE]);
    }

    public function get_extra_var(Request $request)
    {
        $config = config('maccms');
        $app = isset($config['app']) ? $config['app'] : [];

        $toArray = function($val) {
            if (empty($val)) return [];
            return array_values(array_filter(array_map('trim', explode(',', $val)), function($v) {
                return $v !== '';
            }));
        };

        $data = [
            'player_sort'        => isset($app['player_sort']) ? $app['player_sort'] : '',
            'encrypt'            => isset($app['encrypt']) ? $app['encrypt'] : '0',
            'search_hot'         => $toArray(isset($app['search_hot']) ? $app['search_hot'] : ''),
            'art_extend_class'   => $toArray(isset($app['art_extend_class']) ? $app['art_extend_class'] : ''),
            'vod_extend_class'   => $toArray(isset($app['vod_extend_class']) ? $app['vod_extend_class'] : ''),
            'vod_extend_state'   => $toArray(isset($app['vod_extend_state']) ? $app['vod_extend_state'] : ''),
            'vod_extend_version' => $toArray(isset($app['vod_extend_version']) ? $app['vod_extend_version'] : ''),
            'vod_extend_area'    => $toArray(isset($app['vod_extend_area']) ? $app['vod_extend_area'] : ''),
            'vod_extend_lang'    => $toArray(isset($app['vod_extend_lang']) ? $app['vod_extend_lang'] : ''),
            'vod_extend_year'    => $toArray(isset($app['vod_extend_year']) ? $app['vod_extend_year'] : ''),
            'vod_extend_weekday' => $toArray(isset($app['vod_extend_weekday']) ? $app['vod_extend_weekday'] : ''),
            'actor_extend_area'  => $toArray(isset($app['actor_extend_area']) ? $app['actor_extend_area'] : ''),
            'filter_words'       => $toArray(isset($app['filter_words']) ? $app['filter_words'] : ''),
        ];

        $extra = isset($config['extra']) && is_array($config['extra']) ? $config['extra'] : [];
        if (empty($extra) && !empty($app['extra_var'])) {
            $extra_var = str_replace(array(chr(10), chr(13)), array('', '#'), $app['extra_var']);
            $tmp = explode('#', $extra_var);
            foreach ($tmp as $a) {
                if (!empty($a)) {
                    $tmp2 = explode('$$$', $a);
                    if (count($tmp2) >= 2) {
                        $extra[trim($tmp2[0])] = trim($tmp2[1]);
                    }
                }
            }
        }
        $data['extra_var'] = $extra;

        return json([
            'code' => 1,
            'msg'  => lang('api/get_ok'),
            'data' => $data,
        ])->options(['json_encode_param' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE]);
    }

    public function get_tpl_config(Request $request)
    {
        $config      = config('maccms');
        $templateDir = $this->resolveTemplateDir();

        $tplconfig = [];
        $configFile = './template/' . $templateDir . '/config.json';
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            $tplconfig = json_decode($content, true);
            if (!is_array($tplconfig)) {
                $tplconfig = [];
            }
        }

        $site = [
            'site_name'        => $config['site']['site_name'] ?? '',
            'site_url'         => $config['site']['site_url'] ?? '',
            'site_logo'        => !empty($config['site']['site_logo']) ? mac_url_img($config['site']['site_logo']) : '',
            'site_wap_logo'    => !empty($config['site']['site_wap_logo']) ? mac_url_img($config['site']['site_wap_logo']) : '',
            'site_keywords'    => $config['site']['site_keywords'] ?? '',
            'site_description' => $config['site']['site_description'] ?? '',
            'site_icp'         => $config['site']['site_icp'] ?? '',
            'site_email'       => $config['site']['site_email'] ?? '',
            'template_dir'     => $templateDir,
        ];

        $features = [
            'user_status'    => intval($config['user']['status'] ?? 0),
            'gbook_status'   => intval($config['gbook']['status'] ?? 0),
            'comment_status' => intval($config['comment']['status'] ?? 0),
        ];

        $search = [
            'search_hot' => $config['app']['search_hot'] ?? '',
        ];

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'site'       => $site,
                'features'   => $features,
                'search'     => $search,
                'tpl_config' => $tplconfig,
            ],
        ])->options(['json_encode_param' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE]);
    }

    public function get_mctheme(Request $request)
    {
        $mctheme = config('mctheme');
        if (!is_array($mctheme)) {
            $mctheme = [];
        }
        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'template_dir' => $this->resolveTemplateDir(),
                'mctheme'      => $mctheme,
            ],
        ])->options(['json_encode_param' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE]);
    }

    public function get_ads_files(Request $request)
    {
        $config      = config('maccms');
        $templateDir = $this->resolveTemplateDir();
        $adsDir      = !empty($config['site']['ads_dir']) ? trim((string) $config['site']['ads_dir']) : 'ads';
        $physical    = ROOT_PATH . 'template/' . $templateDir . '/' . $adsDir;

        $files = [];
        if (is_dir($physical)) {
            foreach (glob($physical . DIRECTORY_SEPARATOR . '*.js') ?: [] as $full) {
                if (!is_file($full)) {
                    continue;
                }
                $name = basename($full);
                $rel  = 'template/' . $templateDir . '/' . $adsDir . '/' . $name;
                $files[] = [
                    'name' => $name,
                    'path' => $rel,
                    'url'  => mac_url_img($rel),
                ];
            }
        }
        usort($files, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return json([
            'code' => 1,
            'msg'  => '获取成功',
            'info' => [
                'template_dir' => $templateDir,
                'ads_dir'      => $adsDir,
                'files'        => $files,
            ],
        ])->options(['json_encode_param' => JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE]);
    }
}
