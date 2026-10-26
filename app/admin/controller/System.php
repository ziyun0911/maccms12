<?php
namespace app\admin\controller;
use app\common\util\ExternalSyncRunner;
use app\common\util\JwtService;
use app\common\util\PublishPage;
use app\common\util\SensitiveDataCrypto;
use app\common\util\SystemSettingsMenu;
use think\facade\Db;
use think\facade\Config;
use think\facade\Cache;

class System extends Base
{
    /**
     * 系统设置中心。
     *
     * 把本控制器内全部设置页聚合到一个入口：左侧按功能分组导航，
     * 右侧展示系统概览、配置体检或具体设置页。各设置页仍由原有
     * action 渲染并提交，保存逻辑、Token 校验与权限保持不变。
     */
    public function index()
    {
        // 入口再做一次权限兜底：仅超级管理员或拥有任意 system/* 权限的管理员可进入
        $adminId = isset($this->_admin['admin_id']) ? (string)$this->_admin['admin_id'] : '';
        $adminAuth = isset($this->_admin['admin_auth']) ? (string)$this->_admin['admin_auth'] : '';
        if ($adminId !== '1' && strpos(',' . strtolower($adminAuth) . ',', ',system/') === false) {
            return $this->error(lang('permission_denied'));
        }

        $config = config('maccms');
        if (!is_array($config)) {
            $config = [];
        }

        $nav = $this->systemSettingsNav($config);
        $groups = [];
        foreach ($nav as $item) {
            $groupKey = $item['group'];
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key'   => $groupKey,
                    'name'  => $item['group_name'],
                    'items' => [],
                ];
            }
            $groups[$groupKey]['items'][] = $item;
        }

        $health = $this->systemSettingsHealth($config);
        // 子管理员看不到无权访问设置页的“去配置”入口
        foreach ($health as &$healthRow) {
            if ($healthRow['url'] !== '' && !$this->check_auth('system', $healthRow['perm'])) {
                $healthRow['url'] = '';
            }
        }
        unset($healthRow);

        $stats = [
            'sections'  => count($nav),
            'enabled'   => 0,
            'attention' => 0,
            'healthy'   => 0,
            'ok'        => 0,
            'warn'      => 0,
            'danger'    => 0,
            'summary'   => '',
        ];
        foreach ($nav as $item) {
            if ($item['state'] === 'on') {
                $stats['enabled']++;
            } elseif ($item['state'] === 'warn') {
                $stats['attention']++;
            }
        }
        foreach ($health as $row) {
            if ($row['level'] === 'ok') {
                $stats['healthy']++;
                $stats['ok']++;
            } elseif ($row['level'] === 'warn') {
                $stats['attention']++;
                $stats['warn']++;
            } elseif ($row['level'] === 'danger') {
                $stats['attention']++;
                $stats['danger']++;
            }
        }
        $stats['summary'] = sprintf(lang('admin/safety/checkup_counts'), $stats['ok'], $stats['warn'], $stats['danger']);

        $version = config('version');
        $this->assign([
            'settings_nav'      => $nav,
            'settings_groups'   => $groups,
            'settings_overview' => $this->systemSettingsOverview($config),
            'settings_health'   => $health,
            'settings_stats'    => $stats,
            'settings_site'     => [
                'name'     => (string)$this->systemSettingsGet($config, 'site.site_name', ''),
                'url'      => (string)$this->systemSettingsGet($config, 'site.site_url', ''),
                'template' => (string)$this->systemSettingsGet($config, 'site.template_dir', ''),
                'cache'    => (string)$this->systemSettingsGet($config, 'app.cache_type', 'file'),
                'version'  => is_array($version) && isset($version['code']) ? (string)$version['code'] : '',
            ],
        ]);
        $this->assign('title', lang('menu/systeminfo'));
        return $this->fetch('admin@system/index');
    }

    /* ------------------------------------------------------------------
     * 设置中心：数据装配
     * ------------------------------------------------------------------ */

    /**
     * 读取多级配置，任意层级缺失时返回默认值。
     *
     * @param array  $config
     * @param string $path    点号路径，如 site.site_url
     * @param mixed  $default
     * @return mixed
     */
    private function systemSettingsGet($config, $path, $default = null)
    {
        return SystemSettingsMenu::get($config, $path, $default);
    }

    /**
     * 构建设置中心左侧导航（按当前管理员权限过滤）。
     *
     * @param array $config
     * @return array
     */
    private function systemSettingsNav($config)
    {
        return SystemSettingsMenu::build($config, function ($action) {
            return $this->check_auth('system', $action);
        });
    }

    /**
     * 系统概览：运行环境表。
     *
     * @param array $config
     * @return array
     */
    private function systemSettingsOverview($config)
    {
        $rows = [];
        $rows[] = [
            'label' => lang('admin/index/welcome/filed_php_ver'),
            'value' => PHP_VERSION,
        ];

        $thinkVersion = '';
        try {
            $thinkVersion = (string)\think\facade\App::version();
        } catch (\Throwable $e) {
            $thinkVersion = '';
        }
        if ($thinkVersion !== '') {
            $rows[] = [
                'label' => lang('admin/index/welcome/filed_thinkphp_ver'),
                'value' => $thinkVersion,
            ];
        }

        $dbVersion = '';
        try {
            $dbRows = Db::query('SELECT VERSION() AS version');
            if (is_array($dbRows) && isset($dbRows[0]['version'])) {
                $dbVersion = (string)$dbRows[0]['version'];
            }
        } catch (\Throwable $e) {
            $dbVersion = '';
        }
        if ($dbVersion !== '') {
            $rows[] = ['label' => 'MySQL', 'value' => $dbVersion];
        }

        $server = isset($_SERVER['SERVER_SOFTWARE']) ? trim((string)$_SERVER['SERVER_SOFTWARE']) : '';
        $osName = function_exists('php_uname') ? php_uname('s') . ' ' . php_uname('r') : PHP_OS;
        $os = trim($osName . ($server !== '' ? ' / ' . $server : ''));
        $rows[] = [
            'label' => lang('admin/index/welcome/filed_os'),
            'value' => $os,
        ];

        $cacheType = trim((string)$this->systemSettingsGet($config, 'app.cache_type', 'file'));
        $rows[] = [
            'label' => lang('admin/system/config/cache_type'),
            'value' => $cacheType !== '' ? ucfirst(strtolower($cacheType)) : 'File',
        ];

        $template = trim((string)$this->systemSettingsGet($config, 'site.template_dir', ''));
        $mobStatus = (string)$this->systemSettingsGet($config, 'site.mob_status', '0');
        $mobTemplate = trim((string)$this->systemSettingsGet($config, 'site.mob_template_dir', ''));
        $templateValue = $template !== '' ? $template : '-';
        if ($mobStatus !== '0' && $mobTemplate !== '' && $mobTemplate !== $template) {
            $templateValue .= ' / ' . $mobTemplate;
        }
        $rows[] = [
            'label' => lang('admin/system/config/template_dir'),
            'value' => $templateValue,
        ];

        $rows[] = [
            'label' => lang('admin/index/welcome/filed_max_upload'),
            'value' => (string)ini_get('upload_max_filesize'),
        ];
        $rows[] = [
            'label' => lang('admin/index/welcome/filed_date'),
            'value' => date('Y-m-d H:i:s'),
        ];

        $version = config('version');
        if (is_array($version) && !empty($version['code'])) {
            $rows[] = [
                'label' => lang('admin/index/welcome/filed_ver'),
                'value' => (string)$version['code'],
            ];
        }
        return $rows;
    }

    /**
     * 系统概览：配置体检表。
     *
     * @param array $config
     * @return array
     */
    private function systemSettingsHealth($config)
    {
        $texts = [
            'ok'     => lang('admin/safety/checkup_badge_ok'),
            'warn'   => lang('admin/safety/checkup_badge_warn'),
            'danger' => lang('admin/safety/checkup_badge_danger'),
            'off'    => lang('admin/safety/checkup_status_off'),
        ];
        $rows = [];
        $add = function ($label, $level, $detail = '', $url = '', $perm = 'config') use (&$rows, $texts) {
            $rows[] = [
                'label'  => $label,
                'level'  => $level,
                'text'   => $texts[$level],
                'detail' => $detail,
                'url'    => $url,
                'perm'   => $perm,
            ];
        };
        $configUrl = (string)url('system/config');

        // 站点 URL
        $siteUrl = trim((string)$this->systemSettingsGet($config, 'site.site_url', ''));
        if ($siteUrl === '') {
            $add(lang('admin/safety/checkup/item_site_url'), 'danger', lang('admin/safety/checkup/site_url_empty'), $configUrl);
        } elseif (preg_match('#^(https?://)?(localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\]|192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)#i', $siteUrl)) {
            $add(lang('admin/safety/checkup/item_site_url'), 'warn', lang('admin/safety/checkup/suggest_site_url'), $configUrl);
        } else {
            $add(lang('admin/safety/checkup/item_site_url'), 'ok', lang('admin/safety/checkup/site_url_ok'), $configUrl);
        }

        // 模板目录
        $template = trim((string)$this->systemSettingsGet($config, 'site.template_dir', ''));
        if ($template !== '' && is_dir(ROOT_PATH . 'template/' . $template)) {
            $add(lang('admin/system/config/template_dir'), 'ok', $template, $configUrl);
        } else {
            $add(lang('admin/system/config/template_dir'), 'danger', $template !== '' ? $template : '-', $configUrl);
        }

        // 手机模板
        $mobStatus = (string)$this->systemSettingsGet($config, 'site.mob_status', '0');
        if ($mobStatus === '0') {
            $add(lang('admin/system/config/mob_template_dir'), 'off', lang('admin/safety/checkup_status_off'), $configUrl);
        } else {
            $mobTemplate = trim((string)$this->systemSettingsGet($config, 'site.mob_template_dir', ''));
            if ($mobTemplate !== '' && is_dir(ROOT_PATH . 'template/' . $mobTemplate)) {
                $add(lang('admin/system/config/mob_template_dir'), 'ok', $mobTemplate, $configUrl);
            } else {
                $add(lang('admin/system/config/mob_template_dir'), 'warn', $mobTemplate !== '' ? $mobTemplate : '-', $configUrl);
            }
        }

        // 缓存标识
        $cacheFlag = trim((string)$this->systemSettingsGet($config, 'app.cache_flag', ''));
        if ($cacheFlag === '') {
            $add(lang('admin/safety/checkup/item_cache_flag'), 'warn', lang('admin/safety/checkup/cache_flag_empty'), $configUrl);
        } elseif (strlen($cacheFlag) < 8) {
            $add(lang('admin/safety/checkup/item_cache_flag'), 'warn', lang('admin/safety/checkup/suggest_cache_flag'), $configUrl);
        } else {
            $add(lang('admin/safety/checkup/item_cache_flag'), 'ok', lang('admin/safety/checkup/cache_flag_ok'), $configUrl);
        }

        // 后台 CSRF
        $csrfOn = (string)$this->systemSettingsGet($config, 'app.security_csrf_admin', '0') === '1';
        $add(
            lang('admin/safety/checkup/item_security_csrf_admin'),
            $csrfOn ? 'ok' : 'off',
            lang('admin/safety/checkup_status_' . ($csrfOn ? 'on' : 'off')),
            $configUrl
        );

        // 后台操作审计
        $auditOn = (string)$this->systemSettingsGet($config, 'app.admin_audit_enabled', '0') === '1';
        $add(
            lang('admin/safety/checkup/item_admin_audit_enabled'),
            $auditOn ? 'ok' : 'off',
            lang('admin/safety/checkup_status_' . ($auditOn ? 'on' : 'off')),
            $configUrl
        );

        // 接口 / 前台限频
        $apiLimit = (string)$this->systemSettingsGet($config, 'app.anti_scrape_api_enabled', '0') === '1';
        $add(
            lang('admin/safety/checkup/item_anti_scrape_api_enabled'),
            $apiLimit ? 'ok' : 'off',
            lang('admin/safety/checkup_status_' . ($apiLimit ? 'on' : 'off')),
            $configUrl
        );
        $indexLimit = (string)$this->systemSettingsGet($config, 'app.anti_scrape_index_enabled', '0') === '1';
        $add(
            lang('admin/safety/checkup/item_anti_scrape_index_enabled'),
            $indexLimit ? 'ok' : 'off',
            lang('admin/safety/checkup_status_' . ($indexLimit ? 'on' : 'off')),
            $configUrl
        );

        // API JWT
        $jwtOn = (string)$this->systemSettingsGet($config, 'app.api_jwt_enabled', '0') === '1';
        if (!$jwtOn) {
            $add(lang('admin/system/config/api_jwt_title'), 'off', lang('admin/safety/checkup_status_off'), $configUrl);
        } elseif (JwtService::hasStrongSecret()) {
            $add(lang('admin/system/config/api_jwt_title'), 'ok', lang('admin/safety/checkup_status_on'), $configUrl);
        } else {
            $add(lang('admin/system/config/api_jwt_title'), 'danger', lang('admin/system/config/api_jwt_secret_weak_tip'), $configUrl);
        }

        // AI SEO
        $seoOn = (string)$this->systemSettingsGet($config, 'ai_seo.enabled', '0') === '1';
        $seoReady = trim((string)$this->systemSettingsGet($config, 'ai_seo.api_key', '')) !== '';
        $seoUrl = (string)url('system/config', ['tab' => 'aiseo']);
        if (!$seoOn) {
            $add(lang('menu/configaiseo'), 'off', lang('admin/safety/checkup_status_off'), $seoUrl);
        } elseif ($seoReady) {
            $add(lang('menu/configaiseo'), 'ok', lang('admin/safety/checkup_status_on'), $seoUrl);
        } else {
            $add(lang('menu/configaiseo'), 'warn', lang('admin/ueditor_ai/no_key'), $seoUrl);
        }

        // AI 搜索
        $searchOn = (string)$this->systemSettingsGet($config, 'ai_search.enabled', '0') === '1';
        $searchReady = trim((string)$this->systemSettingsGet($config, 'ai_search.api_key', '')) !== '';
        $searchUrl = (string)url('system/configaisearch');
        if (!$searchOn) {
            $add(lang('menu/configaisearch'), 'off', lang('admin/safety/checkup_status_off'), $searchUrl, 'configaisearch');
        } elseif ($searchReady) {
            $add(lang('menu/configaisearch'), 'ok', lang('admin/safety/checkup_status_on'), $searchUrl, 'configaisearch');
        } else {
            $add(lang('menu/configaisearch'), 'warn', lang('admin/ueditor_ai/no_key'), $searchUrl, 'configaisearch');
        }

        // 邮件服务
        $emailType = trim((string)$this->systemSettingsGet($config, 'email.type', 'phpmailer'));
        $emailHost = $emailType !== ''
            ? trim((string)$this->systemSettingsGet($config, 'email.' . $emailType . '.host', ''))
            : '';
        $add(
            lang('admin/system/configemail/title'),
            $emailHost !== '' ? 'ok' : 'off',
            $emailHost !== '' ? $emailHost : lang('admin/safety/checkup_status_off'),
            (string)url('system/configemail'),
            'configemail'
        );

        return $rows;
    }

    public function test_email()
    {
        $post = input();
        $conf = [
            'nick' => $post['nick'],
        ];
        $type = strtolower($post['type']);
        $to = $post['test'];
        $conf['host'] = $GLOBALS['config']['email'][$type]['host'];
        $conf['port'] = $GLOBALS['config']['email'][$type]['port'];
        $conf['username'] = $GLOBALS['config']['email'][$type]['username'];
        $conf['password'] = $GLOBALS['config']['email'][$type]['password'];
        $conf['secure'] = $GLOBALS['config']['email'][$type]['secure'];
        $this->label_maccms();

        $title = $GLOBALS['config']['email']['tpl']['test_title'];
        $msg = $GLOBALS['config']['email']['tpl']['test_body'];
        $code = mac_get_rndstr(6,'num');
        \think\facade\View::assign(['code'=>$code,'time'=>$GLOBALS['config']['email']['time']]);
        $title =  \think\facade\View::display($title);
        $msg =  \think\facade\View::display($msg);
        $msg = htmlspecialchars_decode($msg);
        $res = mac_send_mail($to, $title, $msg, $conf);
        if ($res['code']==1) {
            return json(['code' => 1, 'msg' => lang('test_ok')]);
        }
        return json(['code' => 1001, 'msg' => lang('test_err').'：'.$res['msg']]);
    }

    public function test_cache()
    {
        $param = input();

        if (!isset($param['type']) || empty($param['host']) || empty($param['port'])) {
            return $this->error(lang('param_err'));
        }

        $options = [
            'type' => $param['type'],
            'host' => $param['host'],
            'port' => $param['port'],
            'username' => $param['username'],
            'password' => $param['password']
        ];

        if ($param['type'] == 'redis' && isset($param['db']) && intval($param['db']) > 0) {
            $options['select'] = intval($param['db']);
        }

        $stores = (array) Config::get('cache.stores', []);
        $stores['__test_cache'] = array_merge($options, ['type' => $param['type']]);
        Config::set(['stores' => $stores], 'cache');
        $hd = Cache::store('__test_cache');
        $hd->set('test', 'test');
        Cache::forgetDriver('__test_cache');

        return json(['code' => 1, 'msg' => lang('test_ok')]);
    }

    public function config()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');
            // 第 4 参 failException=false：默认 true 时 check() 抛 ValidateException，
            // 令牌失效直接落到框架调试页，下面返回 JSON 的分支走不到。
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);
            $invalidSubmitMsg = '提交数据不完整，请刷新页面后重试';
            if (
                !isset($config['site']) || !is_array($config['site']) ||
                !isset($config['app']) || !is_array($config['app'])
            ) {
                return $this->ajaxErrorWithFreshToken($invalidSubmitMsg);
            }
            $requiredSiteKeys = ['site_name', 'site_url', 'template_dir', 'mob_template_dir'];
            foreach ($requiredSiteKeys as $requiredKey) {
                if (!isset($config['site'][$requiredKey]) || trim((string)$config['site'][$requiredKey]) === '') {
                    return $this->ajaxErrorWithFreshToken($invalidSubmitMsg);
                }
            }
            if (!isset($config['app']['pathinfo_depr']) || trim((string)$config['app']['pathinfo_depr']) === '') {
                return $this->ajaxErrorWithFreshToken($invalidSubmitMsg);
            }
            // 模板名会原样拼进主题目录（think-view 不过滤 ../），只允许字面量文件名
            if (array_key_exists('page_404', $config['app'])) {
                $config['app']['page_404'] = trim((string)$config['app']['page_404']);
                if ($config['app']['page_404'] !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $config['app']['page_404'])) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/system/config/page_404_err'));
                }
            }


            $ads_dir='ads';
            $mob_ads_dir='ads';
            $path = ROOT_PATH .'template/'.$config['site']['template_dir'].'/info.ini';
            $cc = @parse_ini_file($path, false, INI_SCANNER_TYPED) ?: [];
            if(!empty($cc['adsdir'])){
                $ads_dir = $cc['adsdir'];
            }

            $path = ROOT_PATH .'template/'.$config['site']['mob_template_dir'].'/info.ini';
            $cc = @parse_ini_file($path, false, INI_SCANNER_TYPED) ?: [];
            if(!empty($cc['adsdir'])){
                $mob_ads_dir = $cc['adsdir'];
            }
            $config['site']['ads_dir'] = $ads_dir;
            $config['site']['mob_ads_dir'] = $mob_ads_dir;

            if(empty($config['app']['cache_flag'])){
                $config['app']['cache_flag'] = substr(md5(time()),0,10);
            }

            $config['app']['search_vod_rule'] = join('|', !empty($config['app']['search_vod_rule']) ? (array)$config['app']['search_vod_rule'] : []);
            $config['app']['search_art_rule'] = join('|', !empty($config['app']['search_art_rule']) ? (array)$config['app']['search_art_rule'] : []);
            $config['app']['vod_search_optimise'] = join('|', !empty($config['app']['vod_search_optimise']) ? (array)$config['app']['vod_search_optimise'] : []);
            $config['app']['vod_search_optimise_cache_minutes'] = (int)$config['app']['vod_search_optimise_cache_minutes'];

            //内容级多语言：默认语言必须合法且在启用列表内（它始终作为回退语言存在）
            $contentLangKnown = mac_content_lang_codes();
            if (empty($config['app']['content_lang_default']) || !in_array($config['app']['content_lang_default'], $contentLangKnown, true)) {
                $config['app']['content_lang_default'] = mac_content_lang_first();
            }
            $contentLangList = !empty($config['app']['content_lang_list']) ? (array)$config['app']['content_lang_list'] : [];
            // 只接受白名单内的语言码：缓存的旧后台页面可能仍在提交已下架的码
            $contentLangList = array_values(array_unique(array_filter(array_map(function ($v) {
                return strtolower(trim((string)$v));
            }, $contentLangList), function ($v) use ($contentLangKnown) {
                return in_array($v, $contentLangKnown, true);
            })));
            if (!in_array($config['app']['content_lang_default'], $contentLangList, true)) {
                $contentLangList[] = $config['app']['content_lang_default'];
            }
            $config['app']['content_lang_list'] = join('|', $contentLangList);

            $config_old_maccms = config('maccms');
            if (!isset($config['app']['admin_audit_crypto_secret']) || trim((string)$config['app']['admin_audit_crypto_secret']) === '') {
                if (isset($config_old_maccms['app']['admin_audit_crypto_secret'])) {
                    $config['app']['admin_audit_crypto_secret'] = $config_old_maccms['app']['admin_audit_crypto_secret'];
                }
            }
            if (!empty($config['app']['admin_audit_encrypt']) && (string)$config['app']['admin_audit_encrypt'] === '1'
                && !SensitiveDataCrypto::supportsAes256Gcm()) {
                return $this->ajaxErrorWithFreshToken(lang('admin/system/config/admin_audit_encrypt_gcm_required'));
            }

            if (!isset($config['app']['api_jwt_secret']) || trim((string)$config['app']['api_jwt_secret']) === '') {
                if (isset($config_old_maccms['app']['api_jwt_secret'])) {
                    $config['app']['api_jwt_secret'] = $config_old_maccms['app']['api_jwt_secret'];
                }
            }
            if (isset($config['app']['api_jwt_enabled']) && (string)$config['app']['api_jwt_enabled'] === '1') {
                $jwtSec = isset($config['app']['api_jwt_secret']) ? trim((string)$config['app']['api_jwt_secret']) : '';
                if (strlen($jwtSec) < 32) {
                    $config['app']['api_jwt_secret'] = mac_get_rndstr(32);
                }
            }

            $config['extra'] = [];
            if(!empty($config['app']['extra_var'])){
                $extra_var = str_replace(array(chr(10),chr(13)), array('','#'),$config['app']['extra_var']);
                $tmp = explode('#',$extra_var);
                foreach($tmp as $a){
                    if(strpos($a,'$$$')!==false){
                        $tmp2 = explode('$$$',$a);
                        $config['extra'][$tmp2[0]] = $tmp2[1];
                    }
                }
                unset($tmp,$tmp2);
            }

            $config['site']['site_tj'] = html_entity_decode($config['site']['site_tj']);

            $config_old = config('maccms');
            if (!isset($config['site']['site_publish_links']) && isset($config_old['site']['site_publish_links'])) {
                $opl = $config_old['site']['site_publish_links'];
                $config['site']['site_publish_links'] = is_scalar($opl) ? (string) $opl : '';
            }

            $pubStatus = isset($config['site']['site_publish_status']) ? (string) $config['site']['site_publish_status'] : '0';
            $config['site']['site_publish_status'] = $pubStatus === '1' ? '1' : '0';
            $permText = isset($config['site']['site_publish_permanent_text']) ? trim((string) $config['site']['site_publish_permanent_text']) : '';
            $permUrlRaw = isset($config['site']['site_publish_permanent_url']) ? (string) $config['site']['site_publish_permanent_url'] : '';
            $permUrl = PublishPage::sanitizeUrl($permUrlRaw);
            if (mb_strlen($permText, 'UTF-8') > 200) {
                return $this->error(lang('admin/system/config/site_publish_err_perm_text_len'));
            }
            $config['site']['site_publish_permanent_text'] = $permText;
            $config['site']['site_publish_permanent_url'] = $permUrl;
            foreach (['site_publish_links', 'site_publish_groups'] as $pk) {
                if (!isset($config['site'][$pk])) {
                    $config['site'][$pk] = '';
                } else {
                    $config['site'][$pk] = is_scalar($config['site'][$pk]) ? (string) $config['site'][$pk] : '';
                }
            }
            $gRaw = '';
            if (isset($config['site']['publish_groups']) && is_array($config['site']['publish_groups'])) {
                $pg = $config['site']['publish_groups'];
                unset($pg['__sent'], $pg['_sent']);
                $gRaw = PublishPage::buildGroupsJsonFromUiPost($pg);
                unset($config['site']['publish_groups']);
            } elseif (isset($config['site']['site_publish_groups'])) {
                $gRaw = (string) $config['site']['site_publish_groups'];
            }
            if (strlen($gRaw) > 120000) {
                return $this->error(lang('admin/system/config/site_publish_err_groups_len'));
            }
            $groupsParsed = PublishPage::parseGroups($gRaw);
            if ($gRaw !== '' && $gRaw !== '[]' && json_decode($gRaw, true) !== null && count($groupsParsed) < 1) {
                return $this->error(lang('admin/system/config/site_publish_err_groups_json'));
            }
            $config['site']['site_publish_groups'] = $gRaw;
            $hasGroups = count($groupsParsed) > 0;
            if ($config['site']['site_publish_status'] === '1' && !$hasGroups) {
                return $this->error(lang('admin/system/config/site_publish_err_links_or_groups'));
            }
            $config_new['site'] = $config['site'];
            $config_new['app'] = $config['app'];
            $config_new['extra'] = $config['extra'];

            $config_new = array_merge($config_old, $config_new);
            unset($config_new['site']['site_publish_home_pc'], $config_new['site']['site_publish_home_wap']);

            $tj = $config_new['site']['site_tj'];
            if(strpos($tj,'document.w') ===false){
                $tj = 'document.write(\'' . str_replace("'","\'",$tj) . '\')';
            }
            $res = @fwrite(fopen('./static/js/tj.js', 'wb'), $tj);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }


        $templates = glob('./template' . '/*', GLOB_ONLYDIR);
        foreach ($templates as $k => &$v) {
            $v = str_replace('./template/', '', $v);
        }
        $this->assign('templates', $templates);

        // 界面语言与前台内容语言共用同一份注册表：清单由 app/lang/*.php 的自述块决定
        $langs = array_values(mac_lang_registry());
        $this->assign('langs', $langs);

        $usergroup = Db::name('group')->select();
        $this->assign('usergroup', $usergroup);

        $editors = mac_extends_list('editor');
        $this->assign('editors',$editors);

        $config = config('maccms');
        $pubGroupsRaw = isset($config['site']['site_publish_groups']) ? $config['site']['site_publish_groups'] : '';
        $publish_groups_ui = PublishPage::parseGroups((string) $pubGroupsRaw);
        $this->assign('publish_groups_ui', $publish_groups_ui);
        // 默认get+post
        if (!isset($config['app']['input_type'])) {
            $config['app']['input_type'] = 1;
        }
        $config['app']['vod_search_optimise_cache_minutes'] = (new \app\common\model\VodSearch())->getResultCacheMinutes($config);

        // 「播放器参数配置」页签：可配项以 static/js/playerconfig.js 为准
        $fp = './static/js/playerconfig.js';
        if (!file_exists($fp)) {
            $fp .= '.bak';
        }
        $fc = file_exists($fp) ? @file_get_contents($fp) : '';
        $play = [];
        if ($fc) {
            $jsb = trim(mac_get_body($fc, '//参数开始', '//参数结束'));
            if (strlen($jsb) > 17) {
                $jsb = substr($jsb, 16, strlen($jsb) - 17);
                $play = (array) json_decode($jsb, true);
            }
        }
        $this->assign('play', $play);

        $this->assign('form_action_configseo', (string) url('configseo'));
        $this->assign('form_action_configaiseo', (string) url('configaiseo'));
        $this->assign('form_action_configaicover', (string) url('configaicover'));
        $this->assign('form_action_configupload', (string) url('configupload'));
        $this->assign('form_action_configurl', (string) url('configurl'));
        $this->assign('form_action_configplay', (string) url('configplay'));
        $tab = (string) input('tab', '');
        $allowTab = ['', 'seo', 'aiseo', 'aicover', 'upload', 'url', 'play'];
        $this->assign('config_merge_tab', in_array($tab, $allowTab, true) ? $tab : '');
        $this->assign('audit_crypto_gcm_supported', SensitiveDataCrypto::supportsAes256Gcm() ? 1 : 0);
        $this->assign('api_jwt_secret_strong', JwtService::hasStrongSecret() ? 1 : 0);
        // 「AI SEO 设置」「AI 视频封面设置」页签：补齐展示默认值并回显已保存 Key 的尾号
        $config['ai_seo'] = $this->aiSeoFormConfig()['ai_seo'];
        $config['ai_cover'] = $this->aiCoverFormConfig()['ai_cover'];
        $this->assign('config', $config);
        // 「附件参数配置」页签：上传扩展插件的字段各自 View::fetch，须先有 $config 才能回填已存的密钥
        $this->assign('extends', mac_extends_list('upload'));
        $this->assign('title', lang('admin/system/config/title'));
        return $this->fetch('admin@system/config');
    }


    public function configurl()
    {
        if (Request()->isPost()) {
            $config = input();

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['view'] = $config['view'];
            $config_new['path'] = $config['path'];
            $config_new['rewrite'] = $config['rewrite'];

            //写路由规则文件
            $route = [];
            $route['__pattern__'] = [

                'id'=>'[\s\S]*?',
                'ids'=>'[\s\S]*?',
                'wd' => '[\s\S]*',
                'en'=>'[\s\S]*?',
                'state' => '[\s\S]*?',
                'area' => '[\s\S]*',
                'year'=>'[\s\S]*?',
                'lang' => '[\s\S]*?',
                'letter'=>'[\s\S]*?',
                'actor' => '[\s\S]*?',
                'director' => '[\s\S]*?',
                'tag' => '[\s\S]*?',
                'class' => '[\s\S]*?',
                'order'=>'[\s\S]*?',
                'by'=>'[\s\S]*?',
                'file'=>'[\s\S]*?',
                'name'=>'[\s\S]*?',
                'url'=>'[\s\S]*?',
                'type'=>'[\s\S]*?',
                'sex' => '[\s\S]*?',
                'version' => '[\s\S]*?',
                'blood' => '[\s\S]*?',
                'starsign' => '[\s\S]*?',
                'page'=>'\d+',
                'ajax'=>'\d+',
                'tid'=>'\d+',
                'mid'=>'\d+',
                'rid'=>'\d+',
                'pid'=>'\d+',
                'sid'=>'\d+',
                'nid'=>'\d+',
                'uid'=>'\d+',
                'level'=>'\d+',
                'score'=>'\d+',
                'limit'=>'\d+',
            ];
            $rows = explode(chr(13), str_replace(chr(10), '', $config['rewrite']['route']));
            foreach ($rows as $r) {
                if (strpos($r, '=>') !== false) {
                    $a = explode('=>', $r);
                    $rule = [];
//                    if (strpos($a, ':id') !== false) {
                        //$rule['id'] = '\w+';
//                    }
                    $route[trim($a[0])] = [trim($a[1]), [], $rule];
                }
            }

            $pattern = isset($route['__pattern__']) ? $route['__pattern__'] : [];
            unset($route['__pattern__']);
            $route = PublishPage::mergePublishRoutes($route);

            // TP8 多应用：前台路由文件位于 app/index/route/，使用 Route 门面生成
            $lines = [];
            $lines[] = '<?php';
            $lines[] = '// 由后台“URL 配置”自动生成（ThinkPHP 8 路由定义）；手工修改会在下次保存时被覆盖。';
            $lines[] = 'use think\facade\Route;';
            $lines[] = '';
            $lines[] = 'Route::pattern(' . var_export($pattern, true) . ');';
            $lines[] = '';
            foreach ($route as $rule => $target) {
                $to = is_array($target) ? (isset($target[0]) ? (string)$target[0] : '') : (string)$target;
                if ($to === '') {
                    continue;
                }
                $lines[] = 'Route::rule(' . var_export((string)$rule, true) . ', ' . var_export($to, true) . ');';
            }
            $routeFile = APP_PATH . 'index/route/rewrite.php';
            $res = @file_put_contents($routeFile, implode("\n", $lines) . "\n");
            if ($res === false) {
                return $this->error(lang('write_err_route'));
            }

            //写扩展配置
            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);
            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('write_err_config'));
            }
            return $this->success(lang('save_ok'));
        }

        return $this->redirect( url('config', ['tab' => 'url']) );
    }

    public function configuser()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['user'] = $config['user'];
            
            if(isset($config['user']['invite_reward']) && is_array($config['user']['invite_reward'])) {
                $invite_reward = [];
                foreach($config['user']['invite_reward'] as $k => $v) {
                    if(!empty($v['count'])) {
                        $invite_reward[$v['count']] = [
                            'group_id' => intval($v['group_id']),
                            'long' => $v['long'],
                            'points' => intval($v['points'])
                        ];
                    }
                }
                $config_new['user']['invite_reward'] = $invite_reward;
            }
            
            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $config = config('maccms');
        
        $invite_reward_form = [];
        if(isset($config['user']['invite_reward']) && is_array($config['user']['invite_reward'])) {
            foreach($config['user']['invite_reward'] as $count => $reward) {
                $invite_reward_form[] = [
                    'count' => $count,
                    'group_id' => $reward['group_id'],
                    'long' => $reward['long'],
                    'points' => $reward['points']
                ];
            }
        }
        
        while(count($invite_reward_form) < 3) {
            $invite_reward_form[] = ['count' => '', 'group_id' => '3', 'long' => 'month', 'points' => 0];
        }
        $this->assign('invite_reward_form', $invite_reward_form);
        
        $group_list = \think\facade\Db::name('group')->select();
        $this->assign('group_list', $group_list);
        
        $this->assign('form_action_configcomment', (string) url('configcomment'));
        $tab = (string) input('tab', '');
        $allowTab = ['', 'comment'];
        $this->assign('config_merge_tab', in_array($tab, $allowTab, true) ? $tab : '');
        $this->assign('config', $config);
        $this->assign('title', lang('admin/system/configuser/title'));
        return $this->fetch('admin@system/configuser');
    }

    /**
     * 用户访问风控日志配置。
     *
     * 这 4 个键物理上仍存放在 maccms.php 的 monitor 段（与 UserAccessLog::cfg()
     * 和定时任务 user_access_purge 的读取路径保持一致），此页仅负责在“系统”菜单
     * 下呈现/回写这几个键；存档时用 array_merge 只覆盖这 4 个键，其余 monitor 键
     * （cron_token / webhook 等）原样保留，避免误伤。
     */
    public function configuseraccess()
    {
        if (Request()->isPost()) {
            $post = input('post.', '', 'htmlentities');
            // 该页使用会话级稳定令牌，不能调用 ThinkPHP Token validator：
            // validator 会消费并删除易变的 Session __token__，导致多标签页互相失效。
            // 同时保留 legacy token 比对，兼容升级前已经打开的旧页面。
            $submitted = isset($post['__token__']) && is_string($post['__token__'])
                ? $post['__token__'] : '';
            $stable = function_exists('mac_admin_csrf_token')
                ? (string)mac_admin_csrf_token() : (string)\think\facade\Session::get('admin_csrf');
            $legacy = \think\facade\Session::has('__token__') ? (string)\think\facade\Session::get('__token__') : '';
            $tokenOk = ($stable !== '' && hash_equals($stable, $submitted))
                || ($legacy !== '' && hash_equals($legacy, $submitted));
            if (!$tokenOk) {
                return $this->error(lang('token_err'));
            }
            // 只有真正使用旧 token 时才消费它；稳定 token 可跨标签页重复使用。
            if ($legacy !== '' && hash_equals($legacy, $submitted)) {
                \think\facade\Session::delete('__token__');
            }
            unset($post['__token__']);

            $config_old = config('maccms');
            $ua = isset($post['user_access']) && is_array($post['user_access']) ? $post['user_access'] : [];

            $retain = max(1, min(365, intval(isset($ua['retain_user_access_days']) ? $ua['retain_user_access_days'] : 90)));
            $anonymize = max(0, min(365, intval(isset($ua['user_access_anonymize_days']) ? $ua['user_access_anonymize_days'] : 30)));
            // 不变量：匿名化期限必须早于删除期限。若站长误填 anonymize >= retain，
            // 匿名化窗口（>=删除线 且 <匿名化线）恒为空，资料会在去识别化前就整行删除。
            // 这里钳制为 retain-1（retain=1 时归 0，即等价关闭匿名化），与模型层 purge() 双重兜底。
            if ($anonymize > 0 && $anonymize >= $retain) {
                $anonymize = $retain - 1;
            }

            $row = [
                'user_access_log_enabled'    => isset($ua['user_access_log_enabled']) && (string)$ua['user_access_log_enabled'] === '1' ? '1' : '0',
                'user_access_throttle_min'   => (string)max(0, min(1440, intval(isset($ua['user_access_throttle_min']) ? $ua['user_access_throttle_min'] : 10))),
                'retain_user_access_days'    => (string)$retain,
                'user_access_anonymize_days' => (string)$anonymize,
            ];

            $old_monitor = (isset($config_old['monitor']) && is_array($config_old['monitor'])) ? $config_old['monitor'] : [];
            $config_new = $config_old;
            $config_new['monitor'] = array_merge($old_monitor, $row);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $config = config('maccms');
        $mon = (isset($config['monitor']) && is_array($config['monitor'])) ? $config['monitor'] : [];
        // 视图专用聚合键（仅用于渲染表单，存储位置仍是 monitor 段）
        $config['user_access'] = [
            'user_access_log_enabled'    => (string)(isset($mon['user_access_log_enabled']) ? $mon['user_access_log_enabled'] : '1'),
            'user_access_throttle_min'   => (string)(isset($mon['user_access_throttle_min']) ? $mon['user_access_throttle_min'] : '10'),
            'retain_user_access_days'    => (string)(isset($mon['retain_user_access_days']) ? $mon['retain_user_access_days'] : '90'),
            'user_access_anonymize_days' => (string)(isset($mon['user_access_anonymize_days']) ? $mon['user_access_anonymize_days'] : '30'),
        ];

        // 仅承载于新版后台主题（view）；旧版 view/ 不再承载新功能（铁律 2）
        $this->assign('config', $config);
        $this->assign('title', lang('admin/system/configuseraccess'));
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
        return $this->fetch('system/configuseraccess');
    }

    public function configupload()
    {
        $phar_status = file_exists(ROOT_PATH . 'extend/aws/src/Aws/aws.phar');
        if (Request()->isPost()){
            $config = input('','','htmlentities');
            $invalidSubmitMsg = '提交数据不完整，请刷新页面后重试';
            if (
                !isset($config['upload']) || !is_array($config['upload']) ||
                !isset($config['upload']['mode']) || trim((string)$config['upload']['mode']) === '' ||
                !isset($config['upload']['protocol']) || trim((string)$config['upload']['protocol']) === '' ||
                !isset($config['upload']['keep_local'])
            ) {
                return $this->error($invalidSubmitMsg);
            }
            if($config['upload']['mode'] == 'S3' && $phar_status == false){
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_old = config('maccms');
            $upload_old = isset($config_old['upload']) && is_array($config_old['upload']) ? $config_old['upload'] : [];
            $config['upload'] = array_replace_recursive($upload_old, $config['upload']);

            $config_new['upload'] = $config['upload'];
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));
        return $this->redirect(url('config', ['tab' => 'upload']));
    }

    public function configcomment()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');
            $invalidSubmitMsg = '提交数据不完整，请刷新页面后重试';
            // 评论留言独立表单只提交 gbook / comment，不包含会员配置 user[*]
            if (
                !isset($config['gbook']) || !is_array($config['gbook']) ||
                !isset($config['comment']) || !is_array($config['comment'])
            ) {
                return $this->error($invalidSubmitMsg);
            }

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['gbook'] = $config['gbook'];
            $config_new['comment'] = $config['comment'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        return $this->redirect( url('configuser', ['tab' => 'comment']) );
    }

    public function configweixin()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['weixin'] = $config['weixin'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        return $this->redirect( url('configconnect', ['tab' => 'weixin']) );
    }

    public function configpay()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['pay'] = $config['pay'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        return $this->redirect( url('configconnect', ['tab' => 'pay']) );
    }

    public function configconnect()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['connect'] = $config['connect'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));
        $this->assign('http_type', $GLOBALS['http_type']);
        $extends = mac_extends_list('pay');
        $this->assign('extends', $extends);
        $this->assign('form_action_configweixin', (string) url('configweixin'));
        $this->assign('form_action_configpay', (string) url('configpay'));
        $tab = (string) input('tab', '');
        $allowTab = ['', 'weixin', 'pay'];
        $this->assign('config_merge_tab', in_array($tab, $allowTab, true) ? $tab : '');
        $this->assign('title', lang('admin/system/configconnect/title'));
        return $this->fetch('admin@system/configconnect');
    }

    public function configemail()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['email'] = $config['email'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }
        $this->assign('config', config('maccms'));

        $extends = mac_extends_list('email');
        $this->assign('extends',$extends);

        $this->assign('title', lang('admin/system/configemail/title'));
        return $this->fetch('admin@system/configemail');
    }

    public function configsms()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);


            $config_new['sms'] = $config['sms'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }
        $this->assign('config', config('maccms'));

        $extends = mac_extends_list('sms');
        $this->assign('extends',$extends);

        $this->assign('title', lang('admin/system/configsms/title'));
        return $this->fetch('admin@system/configsms');
    }

    public function configpush()
    {
        // 生成 VAPID 密钥对（AJAX，op=genkey）：仅返回给前端填入表单，由站长确认后再保存落盘
        if (input('op') === 'genkey') {
            $keys = \app\common\util\PushDispatcher::generateVapidKeys();
            if ($keys === false) {
                return $this->error(lang('admin/system/configpush/genkey_fail'));
            }
            return $this->success(lang('admin/system/configpush/genkey_ok'), null, $keys);
        }

        if (Request()->isPost()) {
            $config = input('', '', 'htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if (!$validate->check($config)) {
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $push = isset($config['push']) && is_array($config['push']) ? $config['push'] : [];
            $config_new = [];
            $config_new['push'] = [
                'enable'        => isset($push['enable']) && $push['enable'] == 1 ? '1' : '0',
                'vapid_public'  => isset($push['vapid_public']) ? trim($push['vapid_public']) : '',
                'vapid_private' => isset($push['vapid_private']) ? trim($push['vapid_private']) : '',
                'vapid_subject' => isset($push['vapid_subject']) ? trim($push['vapid_subject']) : '',
            ];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));
        $this->assign('title', lang('admin/system/configpush/title'));
        return $this->fetch('admin@system/configpush');
    }

    public function configapi()

    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['api'] = $config['api'];

            $config_new['api']['vod']['auth'] = mac_replace_text($config_new['api']['vod']['auth'], 2);
            $config_new['api']['art']['auth'] = mac_replace_text($config_new['api']['art']['auth'], 2);
            $config_new['api']['actor']['auth'] = mac_replace_text($config_new['api']['actor']['auth'], 2);
            $config_new['api']['manga']['auth'] = mac_replace_text($config_new['api']['manga']['auth'], 2);
            $config_new['api']['publicapi']['auth'] = mac_replace_text($config_new['api']['publicapi']['auth'], 2);

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        return $this->redirect( url('configcollect', ['tab' => 'api']) );
    }

    public function configinterface()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            if($config['interface']['status']==1 && strlen($config['interface']['pass']) < 16){
                return $this->error(lang('admin/system/configinterface/pass_check'));
            }

            $config_new['interface'] = $config['interface'];
            $config_new['interface']['vodtype'] = mac_replace_text($config_new['interface']['vodtype'], 2);
            $config_new['interface']['arttype'] = mac_replace_text($config_new['interface']['arttype'], 2);

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }

            //保存缓存
            mac_interface_type();

            return $this->success(lang('save_ok'));
        }

        return $this->redirect( url('configcollect', ['tab' => 'interface']) );
    }

    public function configcollect()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['collect'] = $config['collect'];
            if (empty($config_new['collect']['vod']['inrule'])) {
                $config_new['collect']['vod']['inrule'] = ['a'];
            }
            if (empty($config_new['collect']['vod']['uprule'])) {
                $config_new['collect']['vod']['uprule'] = [];
            }
            if (empty($config_new['collect']['art']['inrule'])) {
                $config_new['collect']['art']['inrule'] = ['a'];
            }
            if (empty($config_new['collect']['art']['uprule'])) {
                $config_new['collect']['art']['uprule'] = [];
            }
            if (empty($config_new['collect']['actor']['inrule'])) {
                $config_new['collect']['actor']['inrule'] = ['a'];
            }
            if (empty($config_new['collect']['actor']['uprule'])) {
                $config_new['collect']['actor']['uprule'] = [];
            }
            if (empty($config_new['collect']['role']['inrule'])) {
                $config_new['collect']['role']['inrule'] = ['a'];
            }
            if (empty($config_new['collect']['role']['uprule'])) {
                $config_new['collect']['role']['uprule'] = [];
            }
            if (empty($config_new['collect']['website']['inrule'])) {
                $config_new['collect']['website']['inrule'] = ['a'];
            }
            if (empty($config_new['collect']['website']['uprule'])) {
                $config_new['collect']['website']['uprule'] = [];
            }
            if (empty($config_new['collect']['comment']['inrule'])) {
                $config_new['collect']['comment']['inrule'] = ['a'];
            }
            if (empty($config_new['collect']['comment']['uprule'])) {
                $config_new['collect']['comment']['uprule'] = [];
            }
            if (empty($config_new['collect']['manga']['inrule'])) {
                $config_new['collect']['manga']['inrule'] = ['a'];
            }
            if (empty($config_new['collect']['manga']['uprule'])) {
                $config_new['collect']['manga']['uprule'] = [];
            }

            $config_new['collect']['vod']['inrule'] = ',' . join(',', $config_new['collect']['vod']['inrule']);
            $config_new['collect']['vod']['uprule'] = ',' . join(',', $config_new['collect']['vod']['uprule']);
            $config_new['collect']['art']['inrule'] = ',' . join(',', $config_new['collect']['art']['inrule']);
            $config_new['collect']['art']['uprule'] = ',' . join(',', $config_new['collect']['art']['uprule']);
            $config_new['collect']['actor']['inrule'] = ',' . join(',', $config_new['collect']['actor']['inrule']);
            $config_new['collect']['actor']['uprule'] = ',' . join(',', $config_new['collect']['actor']['uprule']);
            $config_new['collect']['role']['inrule'] = ',' . join(',', $config_new['collect']['role']['inrule']);
            $config_new['collect']['role']['uprule'] = ',' . join(',', $config_new['collect']['role']['uprule']);
            $config_new['collect']['website']['inrule'] = ',' . join(',', $config_new['collect']['website']['inrule']);
            $config_new['collect']['website']['uprule'] = ',' . join(',', $config_new['collect']['website']['uprule']);
            $config_new['collect']['comment']['inrule'] = ',' . join(',', $config_new['collect']['comment']['inrule']);
            $config_new['collect']['comment']['uprule'] = ',' . join(',', $config_new['collect']['comment']['uprule']);
            $config_new['collect']['manga']['inrule'] = ',' . join(',', $config_new['collect']['manga']['inrule']);
            $config_new['collect']['manga']['uprule'] = ',' . join(',', $config_new['collect']['manga']['uprule']);

            $config_new['collect']['vod']['namewords'] = mac_replace_text($config_new['collect']['vod']['namewords'], 2);
            $config_new['collect']['vod']['thesaurus'] = mac_replace_text($config_new['collect']['vod']['thesaurus'], 2);
            $config_new['collect']['vod']['playerwords'] = mac_replace_text($config_new['collect']['vod']['playerwords'], 2);
            $config_new['collect']['vod']['areawords'] = mac_replace_text($config_new['collect']['vod']['areawords'], 2);
            $config_new['collect']['vod']['langwords'] = mac_replace_text($config_new['collect']['vod']['langwords'], 2);
            $config_new['collect']['vod']['words'] = mac_replace_text($config_new['collect']['vod']['words'], 2);
            $config_new['collect']['art']['thesaurus'] = mac_replace_text($config_new['collect']['art']['thesaurus'], 2);
            $config_new['collect']['art']['words'] = mac_replace_text($config_new['collect']['art']['words'], 2);
            $config_new['collect']['actor']['thesaurus'] = mac_replace_text($config_new['collect']['actor']['thesaurus'], 2);
            $config_new['collect']['actor']['words'] = mac_replace_text($config_new['collect']['actor']['words'], 2);
            $config_new['collect']['role']['thesaurus'] = mac_replace_text($config_new['collect']['role']['thesaurus'], 2);
            $config_new['collect']['role']['words'] = mac_replace_text($config_new['collect']['role']['words'], 2);
            $config_new['collect']['website']['thesaurus'] = mac_replace_text($config_new['collect']['website']['thesaurus'], 2);
            $config_new['collect']['website']['words'] = mac_replace_text($config_new['collect']['website']['words'], 2);
            $config_new['collect']['comment']['thesaurus'] = mac_replace_text($config_new['collect']['comment']['thesaurus'], 2);
            $config_new['collect']['comment']['words'] = mac_replace_text($config_new['collect']['comment']['words'], 2);

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }


        $config = config('maccms');
        if (!isset($config['api']['publicapi'])) {
            $config['api']['publicapi'] = [
                'status' => '0',
                'charge' => '0',
                'auth' => '',
            ];
        }
        if (!isset($config['api']['manga'])) {
            $config['api']['manga'] = [
                'status' => '0',
                'charge' => '0',
                'pagesize' => '20',
                'imgurl' => '',
                'typefilter' => '',
                'datafilter' => 'manga_status=1',
                'cachetime' => '',
                'auth' => '',
            ];
        }
        $this->assign('form_action_configinterface', (string) url('configinterface'));
        $this->assign('form_action_configapi', (string) url('configapi'));
        $tab = (string) input('tab', '');
        $allowTab = ['', 'api', 'interface'];
        $this->assign('config_merge_tab', in_array($tab, $allowTab, true) ? $tab : '');
        $this->assign('config', $config);
        $this->assign('title', lang('admin/system/configcollect/title'));
        return $this->fetch('admin@system/configcollect');
    }

    public function configplay()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['play'] = $config['play'];
            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }

            $path = './static/js/playerconfig.js';
            if (!file_exists($path)) {
                $path .= '.bak';
            }
            $fc = @file_get_contents($path);
            $jsb = mac_get_body($fc, '//参数开始', '//参数结束');
            $content = 'MacPlayerConfig=' . json_encode($config['play']) . ';';
            $fc = str_replace($jsb, "\r\n" . $content . "\r\n", $fc);
            $res = @fwrite(fopen('./static/js/playerconfig.js', 'wb'), $fc);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        return $this->redirect( url('config', ['tab' => 'play']) );
    }

    public function configseo()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if(!$validate->check($config)){
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($config['__token__']);

            $config_new['seo'] = $config['seo'];
            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        return $this->redirect( url('config', ['tab' => 'seo']) );
    }

    /**
     * AI SEO 表单的展示默认值：未写入配置时须与运行时 mergeDetailSeoIntoMaccms 的「视为关闭」一致。
     */
    private function aiSeoFormConfig()
    {
        $config = config('maccms');
        $defaults = [
            'enabled' => '0',
            'auto_generate' => '0',
            'template_inject' => '0',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_base' => 'https://api.openai.com/v1',
            'api_key' => '',
            'timeout' => '20',
        ];
        $stored = isset($config['ai_seo']) && is_array($config['ai_seo']) ? $config['ai_seo'] : [];
        $config['ai_seo'] = array_merge($defaults, $stored);
        $key = trim((string)$config['ai_seo']['api_key']);
        $this->assign('ai_seo_key_saved', $key !== '' ? 1 : 0);
        $this->assign('ai_seo_key_tail', $key !== '' ? substr($key, -6) : '');
        return $config;
    }

    /**
     * AI 视频封面表单的展示默认值。
     */
    private function aiCoverFormConfig()
    {
        $config = config('maccms');
        $defaults = [
            'enabled' => '0',
            'provider' => 'openai',
            'model' => 'gpt-image-1',
            'api_base' => 'https://api.openai.com/v1',
            'api_key' => '',
            'timeout' => '120',
            'size' => '1024x1536',
            'quality' => 'medium',
            'prompt_suffix' => '',
        ];
        $stored = isset($config['ai_cover']) && is_array($config['ai_cover']) ? $config['ai_cover'] : [];
        $config['ai_cover'] = array_merge($defaults, $stored);
        $key = trim((string)$config['ai_cover']['api_key']);
        $this->assign('ai_cover_key_saved', $key !== '' ? 1 : 0);
        $this->assign('ai_cover_key_tail', $key !== '' ? substr($key, -6) : '');
        return $config;
    }

    public function configaiseo()
    {
        if (Request()->isPost()) {
            $post = input('post.', '', 'htmlentities');
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if (!$validate->check($post)) {
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($post['__token__']);

            $config_old = config('maccms');
            $ai = isset($post['ai_seo']) && is_array($post['ai_seo']) ? $post['ai_seo'] : [];

            $sanitize = function ($v) {
                return trim(strip_tags((string)$v));
            };

            $row = [
                'enabled' => isset($ai['enabled']) && (string)$ai['enabled'] === '1' ? '1' : '0',
                'auto_generate' => isset($ai['auto_generate']) && (string)$ai['auto_generate'] === '1' ? '1' : '0',
                'template_inject' => isset($ai['template_inject']) && (string)$ai['template_inject'] === '1' ? '1' : '0',
                'provider' => $sanitize(isset($ai['provider']) ? $ai['provider'] : 'openai'),
                'model' => $sanitize(isset($ai['model']) ? $ai['model'] : 'gpt-4o-mini'),
                'api_base' => $sanitize(isset($ai['api_base']) ? $ai['api_base'] : 'https://api.openai.com/v1'),
                'timeout' => (string)max(5, intval(isset($ai['timeout']) ? $ai['timeout'] : 20)),
            ];
            if ($row['api_base'] === '') {
                $row['api_base'] = 'https://api.openai.com/v1';
            }

            $newKey = isset($ai['api_key']) ? trim((string)$ai['api_key']) : '';
            if ($newKey !== '') {
                $row['api_key'] = $newKey;
            } else {
                // Read from latest config file to avoid accidental key loss when config cache is stale.
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                if (isset($latest['ai_seo']['api_key']) && $latest['ai_seo']['api_key'] !== '') {
                    $row['api_key'] = (string)$latest['ai_seo']['api_key'];
                } else {
                    $row['api_key'] = isset($config_old['ai_seo']['api_key']) ? $config_old['ai_seo']['api_key'] : '';
                }
            }

            $config_new = $config_old;
            $config_new['ai_seo'] = $row;

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        return $this->redirect(url('config', ['tab' => 'aiseo']));
    }

    public function configaicover()
    {
        if (Request()->isPost()) {
            $post = input('post.', '', 'htmlentities');
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if (!$validate->check($post)) {
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string) $err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($post['__token__']);

            $config_old = config('maccms');
            $ai = isset($post['ai_cover']) && is_array($post['ai_cover']) ? $post['ai_cover'] : [];

            $sanitize = function ($v) {
                return trim(strip_tags((string) $v));
            };

            $size = strtolower($sanitize(isset($ai['size']) ? $ai['size'] : '1024x1536'));
            $allowedSizes = ['1024x1024', '1024x1536', '1536x1024', '1024x1792', '1792x1024', '512x512', '256x256', 'auto'];
            if (!in_array($size, $allowedSizes, true)) {
                $size = '1024x1536';
            }
            if ($size === '1024x1792') {
                $size = '1024x1536';
            }
            if ($size === '1792x1024') {
                $size = '1536x1024';
            }
            $q = strtolower($sanitize(isset($ai['quality']) ? $ai['quality'] : 'medium'));
            if (!in_array($q, ['hd', 'standard', 'low', 'medium', 'high', 'auto'], true)) {
                $q = 'medium';
            }

            $row = [
                'enabled' => isset($ai['enabled']) && (string) $ai['enabled'] === '1' ? '1' : '0',
                'provider' => 'openai',
                'model' => $sanitize(isset($ai['model']) ? $ai['model'] : 'gpt-image-1'),
                'api_base' => $sanitize(isset($ai['api_base']) ? $ai['api_base'] : 'https://api.openai.com/v1'),
                'timeout' => (string) max(30, intval(isset($ai['timeout']) ? $ai['timeout'] : 120)),
                'size' => $size,
                'quality' => $q,
                'prompt_suffix' => $sanitize(isset($ai['prompt_suffix']) ? $ai['prompt_suffix'] : ''),
            ];
            if ($row['api_base'] === '') {
                $row['api_base'] = 'https://api.openai.com/v1';
            }

            $newKey = isset($ai['api_key']) ? trim((string) $ai['api_key']) : '';
            if ($newKey !== '') {
                $row['api_key'] = $newKey;
            } else {
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                if (isset($latest['ai_cover']['api_key']) && $latest['ai_cover']['api_key'] !== '') {
                    $row['api_key'] = (string) $latest['ai_cover']['api_key'];
                } else {
                    $row['api_key'] = isset($config_old['ai_cover']['api_key']) ? $config_old['ai_cover']['api_key'] : '';
                }
            }

            $config_new = $config_old;
            $config_new['ai_cover'] = $row;

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        return $this->redirect(url('config', ['tab' => 'aicover']));
    }

    public function configaisearch()
    {
        if (Request()->isPost()) {
            $post = input('post.', '', 'htmlentities');
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if (!$validate->check($post)) {
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($post['__token__']);

            $config_old = config('maccms');
            $ai = isset($post['ai_search']) && is_array($post['ai_search']) ? $post['ai_search'] : [];

            $sanitize = function ($v) {
                return trim(strip_tags((string)$v));
            };

            $module = isset($ai['module']) && is_array($ai['module']) ? $ai['module'] : [];
            $ext = isset($ai['external_sources']) && is_array($ai['external_sources']) ? $ai['external_sources'] : [];
            $tmdb = isset($ext['sources']['tmdb']) && is_array($ext['sources']['tmdb']) ? $ext['sources']['tmdb'] : [];
            $douban = isset($ext['sources']['douban']) && is_array($ext['sources']['douban']) ? $ext['sources']['douban'] : [];
            $imdb = isset($ext['sources']['imdb']) && is_array($ext['sources']['imdb']) ? $ext['sources']['imdb'] : [];
            $row = [
                'enabled' => isset($ai['enabled']) && (string)$ai['enabled'] === '1' ? '1' : '0',
                'provider' => $sanitize(isset($ai['provider']) ? $ai['provider'] : 'openai'),
                'model' => $sanitize(isset($ai['model']) ? $ai['model'] : 'gpt-4o-mini'),
                'response_language' => (function () use ($ai) {
                    $lang = strtolower(trim((string)(isset($ai['response_language']) ? $ai['response_language'] : 'auto')));
                    $allow = ['auto', 'zh', 'en', 'ja', 'ko', 'ru'];
                    return in_array($lang, $allow, true) ? $lang : 'auto';
                })(),
                'api_base' => $sanitize(isset($ai['api_base']) ? $ai['api_base'] : 'https://api.openai.com/v1'),
                'timeout' => (string)max(3, intval(isset($ai['timeout']) ? $ai['timeout'] : 12)),
                'max_terms' => (string)max(1, intval(isset($ai['max_terms']) ? $ai['max_terms'] : 4)),
                'min_query_len' => (string)max(1, intval(isset($ai['min_query_len']) ? $ai['min_query_len'] : 2)),
                'debug_log' => isset($ai['debug_log']) && (string)$ai['debug_log'] === '1' ? '1' : '0',
                'external_enabled' => isset($ai['external_enabled']) && (string)$ai['external_enabled'] === '1' ? '1' : '0',
                'external_domains' => $sanitize(isset($ai['external_domains']) ? $ai['external_domains'] : 'wikipedia.org,imdb.com,douban.com'),
                'external_max_links' => (string)max(1, min(10, intval(isset($ai['external_max_links']) ? $ai['external_max_links'] : 3))),
                'semantic_enabled' => isset($ai['semantic_enabled']) && (string)$ai['semantic_enabled'] === '1' ? '1' : '0',
                'embedding_model' => $sanitize(isset($ai['embedding_model']) ? $ai['embedding_model'] : 'text-embedding-3-small'),
                'semantic_weight' => (function () use ($ai) {
                    $w = floatval(isset($ai['semantic_weight']) ? $ai['semantic_weight'] : '0.45');
                    if ($w < 0) {
                        $w = 0;
                    }
                    if ($w > 1) {
                        $w = 1;
                    }
                    return (string)$w;
                })(),
                'semantic_candidates' => (string)max(8, min(64, intval(isset($ai['semantic_candidates']) ? $ai['semantic_candidates'] : 40))),
                'rate_limit_enabled' => isset($ai['rate_limit_enabled']) && (string)$ai['rate_limit_enabled'] === '1' ? '1' : '0',
                'rate_limit_window' => (string)max(10, min(3600, intval(isset($ai['rate_limit_window']) ? $ai['rate_limit_window'] : 60))),
                'rate_limit_max' => (string)max(1, min(500, intval(isset($ai['rate_limit_max']) ? $ai['rate_limit_max'] : 20))),
                'max_question_chars' => (string)max(0, min(8000, intval(isset($ai['max_question_chars']) ? $ai['max_question_chars'] : 800))),
                'require_login' => isset($ai['require_login']) && (string)$ai['require_login'] === '1' ? '1' : '0',
                'anon_captcha_after' => (string)max(0, min(500, intval(isset($ai['anon_captcha_after']) ? $ai['anon_captcha_after'] : 10))),
                'daily_budget' => (string)max(0, min(100000, intval(isset($ai['daily_budget']) ? $ai['daily_budget'] : 500))),
                'llm_call_cap' => (string)max(1, min(10, intval(isset($ai['llm_call_cap']) ? $ai['llm_call_cap'] : 3))),
                'circuit_fail_threshold' => (string)max(1, min(100, intval(isset($ai['circuit_fail_threshold']) ? $ai['circuit_fail_threshold'] : 8))),
                'circuit_hold_seconds' => (string)max(30, min(86400, intval(isset($ai['circuit_hold_seconds']) ? $ai['circuit_hold_seconds'] : 1800))),
                'module' => [
                    'vod' => isset($module['vod']) && (string)$module['vod'] === '1' ? '1' : '0',
                    'art' => isset($module['art']) && (string)$module['art'] === '1' ? '1' : '0',
                    'manga' => isset($module['manga']) && (string)$module['manga'] === '1' ? '1' : '0',
                    'topic' => isset($module['topic']) && (string)$module['topic'] === '1' ? '1' : '0',
                    'actor' => isset($module['actor']) && (string)$module['actor'] === '1' ? '1' : '0',
                    'role' => isset($module['role']) && (string)$module['role'] === '1' ? '1' : '0',
                    'plot' => isset($module['plot']) && (string)$module['plot'] === '1' ? '1' : '0',
                    'website' => isset($module['website']) && (string)$module['website'] === '1' ? '1' : '0',
                ],
                'external_sources' => [
                    'enabled' => isset($ext['enabled']) && (string)$ext['enabled'] === '1' ? '1' : '0',
                    'use_live' => isset($ext['use_live']) && (string)$ext['use_live'] === '1' ? '1' : '0',
                    'use_cache' => isset($ext['use_cache']) && (string)$ext['use_cache'] === '1' ? '1' : '0',
                    'cache_ttl' => (string)max(60, intval(isset($ext['cache_ttl']) ? $ext['cache_ttl'] : 21600)),
                    'merge_limit' => (string)max(1, min(12, intval(isset($ext['merge_limit']) ? $ext['merge_limit'] : 4))),
                    'sync_interval' => (string)max(300, intval(isset($ext['sync_interval']) ? $ext['sync_interval'] : 21600)),
                    'sources' => [
                        'tmdb' => [
                            'enabled' => isset($tmdb['enabled']) && (string)$tmdb['enabled'] === '1' ? '1' : '0',
                            'base_url' => $sanitize(isset($tmdb['base_url']) ? $tmdb['base_url'] : 'https://api.themoviedb.org/3'),
                            'image_base_url' => $sanitize(isset($tmdb['image_base_url']) ? $tmdb['image_base_url'] : 'https://image.tmdb.org/t/p/w500'),
                            'language' => $sanitize(isset($tmdb['language']) ? $tmdb['language'] : 'zh-CN'),
                            'region' => $sanitize(isset($tmdb['region']) ? $tmdb['region'] : 'CN'),
                        ],
                        'douban' => [
                            'enabled' => isset($douban['enabled']) && (string)$douban['enabled'] === '1' ? '1' : '0',
                            'search_url' => $sanitize(isset($douban['search_url']) ? $douban['search_url'] : 'https://movie.douban.com/j/subject_suggest?q=__query__'),
                            'recent_url' => $sanitize(isset($douban['recent_url']) ? $douban['recent_url'] : 'https://movie.douban.com/j/search_subjects?type=movie&tag=%E7%83%AD%E9%97%A8&page_limit=__limit__&page_start=0'),
                            'referer' => $sanitize(isset($douban['referer']) ? $douban['referer'] : 'https://movie.douban.com/'),
                            'user_agent' => $sanitize(isset($douban['user_agent']) ? $douban['user_agent'] : 'Mozilla/5.0'),
                        ],
                        'imdb' => [
                            'enabled' => isset($imdb['enabled']) && (string)$imdb['enabled'] === '1' ? '1' : '0',
                            'search_url' => $sanitize(isset($imdb['search_url']) ? $imdb['search_url'] : 'https://v3.sg.media-imdb.com/suggestion/__prefix__/__query__.json'),
                            'recent_seed_query' => $sanitize(isset($imdb['recent_seed_query']) ? $imdb['recent_seed_query'] : 'popular'),
                            'user_agent' => $sanitize(isset($imdb['user_agent']) ? $imdb['user_agent'] : 'Mozilla/5.0'),
                        ],
                    ],
                ],
                'anilist_enabled' => isset($ai['anilist_enabled']) && (string)$ai['anilist_enabled'] === '1' ? '1' : '0',
                'google_books_enabled' => isset($ai['google_books_enabled']) && (string)$ai['google_books_enabled'] === '1' ? '1' : '0',
                'catalog_per_source' => (string)max(1, min(8, intval(isset($ai['catalog_per_source']) ? $ai['catalog_per_source'] : 3))),
            ];
            if ($row['api_base'] === '') {
                $row['api_base'] = 'https://api.openai.com/v1';
            }
            if ($row['embedding_model'] === '') {
                $row['embedding_model'] = 'text-embedding-3-small';
            }

            $newKey = isset($ai['api_key']) ? trim((string)$ai['api_key']) : '';
            if ($newKey !== '') {
                $row['api_key'] = $newKey;
            } else {
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                if (isset($latest['ai_search']['api_key']) && $latest['ai_search']['api_key'] !== '') {
                    $row['api_key'] = (string)$latest['ai_search']['api_key'];
                } else {
                    $row['api_key'] = isset($config_old['ai_search']['api_key']) ? $config_old['ai_search']['api_key'] : '';
                }
            }
            $newTmdbKey = isset($tmdb['api_key']) ? trim((string)$tmdb['api_key']) : '';
            if ($newTmdbKey !== '') {
                $row['external_sources']['sources']['tmdb']['api_key'] = $newTmdbKey;
            } else {
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                $oldTmdbKey = '';
                if (isset($latest['ai_search']['external_sources']['sources']['tmdb']['api_key'])) {
                    $oldTmdbKey = (string)$latest['ai_search']['external_sources']['sources']['tmdb']['api_key'];
                } elseif (isset($config_old['ai_search']['external_sources']['sources']['tmdb']['api_key'])) {
                    $oldTmdbKey = (string)$config_old['ai_search']['external_sources']['sources']['tmdb']['api_key'];
                }
                $row['external_sources']['sources']['tmdb']['api_key'] = $oldTmdbKey;
            }
            $newDoubanKey = isset($douban['api_key']) ? trim((string)$douban['api_key']) : '';
            if ($newDoubanKey !== '') {
                $row['external_sources']['sources']['douban']['api_key'] = $newDoubanKey;
            } else {
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                $oldDoubanKey = '';
                if (isset($latest['ai_search']['external_sources']['sources']['douban']['api_key'])) {
                    $oldDoubanKey = (string)$latest['ai_search']['external_sources']['sources']['douban']['api_key'];
                } elseif (isset($config_old['ai_search']['external_sources']['sources']['douban']['api_key'])) {
                    $oldDoubanKey = (string)$config_old['ai_search']['external_sources']['sources']['douban']['api_key'];
                }
                $row['external_sources']['sources']['douban']['api_key'] = $oldDoubanKey;
            }
            $newImdbKey = isset($imdb['api_key']) ? trim((string)$imdb['api_key']) : '';
            if ($newImdbKey !== '') {
                $row['external_sources']['sources']['imdb']['api_key'] = $newImdbKey;
            } else {
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                $oldImdbKey = '';
                if (isset($latest['ai_search']['external_sources']['sources']['imdb']['api_key'])) {
                    $oldImdbKey = (string)$latest['ai_search']['external_sources']['sources']['imdb']['api_key'];
                } elseif (isset($config_old['ai_search']['external_sources']['sources']['imdb']['api_key'])) {
                    $oldImdbKey = (string)$config_old['ai_search']['external_sources']['sources']['imdb']['api_key'];
                }
                $row['external_sources']['sources']['imdb']['api_key'] = $oldImdbKey;
            }
            $newGoogleBooksKey = isset($ai['google_books_api_key']) ? trim((string)$ai['google_books_api_key']) : '';
            if ($newGoogleBooksKey !== '') {
                $row['google_books_api_key'] = $newGoogleBooksKey;
            } else {
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                $oldGb = '';
                if (isset($latest['ai_search']['google_books_api_key']) && $latest['ai_search']['google_books_api_key'] !== '') {
                    $oldGb = (string)$latest['ai_search']['google_books_api_key'];
                } elseif (isset($config_old['ai_search']['google_books_api_key'])) {
                    $oldGb = (string)$config_old['ai_search']['google_books_api_key'];
                }
                $row['google_books_api_key'] = $oldGb;
            }

            $config_new = $config_old;
            $config_new['ai_search'] = $row;
            $trustedProxies = isset($post['trusted_proxies']) ? trim((string)$post['trusted_proxies']) : '';
            $config_new['trusted_proxies'] = $trustedProxies;

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $config = config('maccms');
        if (!isset($config['ai_search']) || !is_array($config['ai_search'])) {
            $config['ai_search'] = [
                'enabled' => '0',
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'response_language' => 'auto',
                'api_base' => 'https://api.openai.com/v1',
                'api_key' => '',
                'timeout' => '12',
                'max_terms' => '4',
                'min_query_len' => '2',
                'debug_log' => '0',
                'external_enabled' => '0',
                'external_domains' => 'wikipedia.org,imdb.com,douban.com',
                'external_max_links' => '3',
                'semantic_enabled' => '0',
                'embedding_model' => 'text-embedding-3-small',
                'semantic_weight' => '0.45',
                'semantic_candidates' => '40',
                'rate_limit_enabled' => '1',
                'rate_limit_window' => '60',
                'rate_limit_max' => '20',
                'max_question_chars' => '800',
                'require_login' => '1',
                'anon_captcha_after' => '10',
                'daily_budget' => '500',
                'llm_call_cap' => '3',
                'circuit_fail_threshold' => '8',
                'circuit_hold_seconds' => '1800',
                'module' => [
                    'vod' => '1',
                    'art' => '1',
                    'manga' => '1',
                    'topic' => '0',
                    'actor' => '0',
                    'role' => '0',
                    'plot' => '0',
                    'website' => '0',
                ],
                'external_sources' => [
                    'enabled' => '0',
                    'use_live' => '1',
                    'use_cache' => '1',
                    'cache_ttl' => '21600',
                    'merge_limit' => '4',
                    'sync_interval' => '21600',
                    'sources' => [
                        'tmdb' => [
                            'enabled' => '0',
                            'api_key' => '',
                            'base_url' => 'https://api.themoviedb.org/3',
                            'image_base_url' => 'https://image.tmdb.org/t/p/w500',
                            'language' => 'zh-CN',
                            'region' => 'CN',
                        ],
                        'douban' => [
                            'enabled' => '0',
                            'api_key' => '',
                            'search_url' => 'https://movie.douban.com/j/subject_suggest?q=__query__',
                            'recent_url' => 'https://movie.douban.com/j/search_subjects?type=movie&tag=%E7%83%AD%E9%97%A8&page_limit=__limit__&page_start=0',
                            'referer' => 'https://movie.douban.com/',
                            'user_agent' => 'Mozilla/5.0',
                        ],
                        'imdb' => [
                            'enabled' => '0',
                            'api_key' => '',
                            'search_url' => 'https://v3.sg.media-imdb.com/suggestion/__prefix__/__query__.json',
                            'recent_seed_query' => 'popular',
                            'user_agent' => 'Mozilla/5.0',
                        ],
                    ],
                ],
                'anilist_enabled' => '0',
                'google_books_enabled' => '0',
                'google_books_api_key' => '',
                'catalog_per_source' => '3',
            ];
        }

        if (!isset($config['trusted_proxies'])) {
            $config['trusted_proxies'] = '';
        }

        $aiSearchFill = [
            'response_language' => 'auto',
            'rate_limit_enabled' => '1',
            'rate_limit_window' => '60',
            'rate_limit_max' => '20',
            'max_question_chars' => '800',
            'require_login' => '1',
            'anon_captcha_after' => '10',
            'daily_budget' => '500',
            'llm_call_cap' => '3',
            'circuit_fail_threshold' => '8',
            'circuit_hold_seconds' => '1800',
            'anilist_enabled' => '0',
            'google_books_enabled' => '0',
            'google_books_api_key' => '',
            'catalog_per_source' => '3',
        ];
        foreach ($aiSearchFill as $k => $v) {
            if (!isset($config['ai_search'][$k])) {
                $config['ai_search'][$k] = $v;
            }
        }
        if (!isset($config['ai_search']['module']) || !is_array($config['ai_search']['module'])) {
            $config['ai_search']['module'] = [];
        }
        $config['ai_search']['module'] = array_merge([
            'vod' => '1',
            'art' => '1',
            'manga' => '1',
            'topic' => '0',
            'actor' => '0',
            'role' => '0',
            'plot' => '0',
            'website' => '0',
        ], $config['ai_search']['module']);
        if (!isset($config['ai_search']['external_sources']) || !is_array($config['ai_search']['external_sources'])) {
            $config['ai_search']['external_sources'] = [];
        }
        $config['ai_search']['external_sources'] = array_merge([
            'enabled' => '0',
            'use_live' => '1',
            'use_cache' => '1',
            'cache_ttl' => '21600',
            'merge_limit' => '4',
            'sync_interval' => '21600',
            'sources' => [],
        ], $config['ai_search']['external_sources']);
        if (!isset($config['ai_search']['external_sources']['sources']['tmdb']) || !is_array($config['ai_search']['external_sources']['sources']['tmdb'])) {
            $config['ai_search']['external_sources']['sources']['tmdb'] = [];
        }
        $config['ai_search']['external_sources']['sources']['tmdb'] = array_merge([
            'enabled' => '0',
            'api_key' => '',
            'base_url' => 'https://api.themoviedb.org/3',
            'image_base_url' => 'https://image.tmdb.org/t/p/w500',
            'language' => 'zh-CN',
            'region' => 'CN',
        ], $config['ai_search']['external_sources']['sources']['tmdb']);
        if (!isset($config['ai_search']['external_sources']['sources']['douban']) || !is_array($config['ai_search']['external_sources']['sources']['douban'])) {
            $config['ai_search']['external_sources']['sources']['douban'] = [];
        }
        $config['ai_search']['external_sources']['sources']['douban'] = array_merge([
            'enabled' => '0',
            'api_key' => '',
            'search_url' => 'https://movie.douban.com/j/subject_suggest?q=__query__',
            'recent_url' => 'https://movie.douban.com/j/search_subjects?type=movie&tag=%E7%83%AD%E9%97%A8&page_limit=__limit__&page_start=0',
            'referer' => 'https://movie.douban.com/',
            'user_agent' => 'Mozilla/5.0',
        ], $config['ai_search']['external_sources']['sources']['douban']);
        if (!isset($config['ai_search']['external_sources']['sources']['imdb']) || !is_array($config['ai_search']['external_sources']['sources']['imdb'])) {
            $config['ai_search']['external_sources']['sources']['imdb'] = [];
        }
        $config['ai_search']['external_sources']['sources']['imdb'] = array_merge([
            'enabled' => '0',
            'api_key' => '',
            'search_url' => 'https://v3.sg.media-imdb.com/suggestion/__prefix__/__query__.json',
            'recent_seed_query' => 'popular',
            'user_agent' => 'Mozilla/5.0',
        ], $config['ai_search']['external_sources']['sources']['imdb']);

        $apiKey = isset($config['ai_search']['api_key']) ? trim((string)$config['ai_search']['api_key']) : '';
        $this->assign('ai_search_key_saved', $apiKey !== '' ? 1 : 0);
        $this->assign('ai_search_key_tail', $apiKey !== '' ? substr($apiKey, -6) : '');
        $tmdbApiKey = trim((string)$config['ai_search']['external_sources']['sources']['tmdb']['api_key']);
        $this->assign('tmdb_key_saved', $tmdbApiKey !== '' ? 1 : 0);
        $this->assign('tmdb_key_tail', $tmdbApiKey !== '' ? substr($tmdbApiKey, -6) : '');
        $doubanApiKey = trim((string)$config['ai_search']['external_sources']['sources']['douban']['api_key']);
        $this->assign('douban_key_saved', $doubanApiKey !== '' ? 1 : 0);
        $this->assign('douban_key_tail', $doubanApiKey !== '' ? substr($doubanApiKey, -6) : '');
        $imdbApiKey = trim((string)$config['ai_search']['external_sources']['sources']['imdb']['api_key']);
        $this->assign('imdb_key_saved', $imdbApiKey !== '' ? 1 : 0);
        $this->assign('imdb_key_tail', $imdbApiKey !== '' ? substr($imdbApiKey, -6) : '');
        $gbKey = isset($config['ai_search']['google_books_api_key']) ? trim((string)$config['ai_search']['google_books_api_key']) : '';
        $this->assign('google_books_key_saved', $gbKey !== '' ? 1 : 0);
        $this->assign('google_books_key_tail', $gbKey !== '' ? substr($gbKey, -6) : '');

        $this->assign('config', $config);
        $this->assign('title', lang('admin/system/configaisearch/title'));
        return $this->fetch('admin@system/configaisearch');
    }

    public function configassistant()
    {
        if (Request()->isPost()) {
            $post = input('post.', '', 'htmlentities');
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if (!$validate->check($post)) {
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($post['__token__']);

            $config_old = config('maccms');
            $as = isset($post['admin_assistant']) && is_array($post['admin_assistant']) ? $post['admin_assistant'] : [];
            $sanitize = function ($v) {
                return trim(strip_tags((string)$v));
            };
            $assistantRow = [
                'enabled' => isset($as['enabled']) && (string)$as['enabled'] === '1' ? '1' : '0',
                'access_scope' => (function () use ($as) {
                    $scope = isset($as['access_scope']) ? strtolower(trim((string)$as['access_scope'])) : 'all';
                    return in_array($scope, ['all', 'super'], true) ? $scope : 'all';
                })(),
                'use_ai_search_credentials' => isset($as['use_ai_search_credentials']) && (string)$as['use_ai_search_credentials'] === '1' ? '1' : '0',
                'provider' => $sanitize(isset($as['provider']) ? $as['provider'] : 'openai'),
                'model' => $sanitize(isset($as['model']) ? $as['model'] : ''),
                'api_base' => $sanitize(isset($as['api_base']) ? $as['api_base'] : ''),
                'timeout' => (string)max(8, intval(isset($as['timeout']) ? $as['timeout'] : 45)),
                'max_tokens' => (string)max(256, min(4096, intval(isset($as['max_tokens']) ? $as['max_tokens'] : 1200))),
                'include_env_snapshot' => isset($as['include_env_snapshot']) && (string)$as['include_env_snapshot'] === '1' ? '1' : '0',
                'rate_per_minute' => (string)max(1, min(120, intval(isset($as['rate_per_minute']) ? $as['rate_per_minute'] : 20))),
                'retrieve_chunks' => (string)max(1, min(12, intval(isset($as['retrieve_chunks']) ? $as['retrieve_chunks'] : 8))),
            ];
            $newAsKey = isset($as['api_key']) ? trim((string)$as['api_key']) : '';
            if ($newAsKey !== '') {
                $assistantRow['api_key'] = $newAsKey;
            } else {
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                if (isset($latest['admin_assistant']['api_key']) && $latest['admin_assistant']['api_key'] !== '') {
                    $assistantRow['api_key'] = (string)$latest['admin_assistant']['api_key'];
                } else {
                    $assistantRow['api_key'] = isset($config_old['admin_assistant']['api_key']) ? $config_old['admin_assistant']['api_key'] : '';
                }
            }

            $config_new = $config_old;
            $config_new['admin_assistant'] = $assistantRow;
            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $config = config('maccms');
        if (!isset($config['admin_assistant']) || !is_array($config['admin_assistant'])) {
            $config['admin_assistant'] = [];
        }
        $config['admin_assistant'] = array_merge([
            'enabled' => '0',
            'access_scope' => 'all',
            'use_ai_search_credentials' => '1',
            'provider' => 'openai',
            'model' => '',
            'api_base' => '',
            'api_key' => '',
            'timeout' => '45',
            'max_tokens' => '1200',
            'include_env_snapshot' => '1',
            'rate_per_minute' => '20',
            'retrieve_chunks' => '8',
        ], $config['admin_assistant']);
        $asKey = isset($config['admin_assistant']['api_key']) ? trim((string)$config['admin_assistant']['api_key']) : '';
        $this->assign('admin_assistant_key_saved', $asKey !== '' ? 1 : 0);
        $this->assign('admin_assistant_key_tail', $asKey !== '' ? substr($asKey, -6) : '');
        $this->assign('config', $config);
        $this->assign('title', lang('admin/system/configassistant/title'));
        return $this->fetch('admin@system/configassistant');
    }

    /**
     * 主题AI 配置（主题设计「AI 生成」专用，独立于后台助手）。
     * 写入 maccms.theme_ai：enabled / provider(openai|claude) / model / api_base / api_key / timeout / max_tokens。
     * api_key 留空则保留原值（避免每次都要重填密钥）。
     */
    public function configthemeai()
    {
        if (Request()->isPost()) {
            $post = input('post.', '', 'htmlentities');
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if (!$validate->check($post)) {
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($post['__token__']);

            $config_old = config('maccms');
            $ta = isset($post['theme_ai']) && is_array($post['theme_ai']) ? $post['theme_ai'] : [];
            $sanitize = function ($v) {
                return trim(strip_tags((string)$v));
            };
            $provider = strtolower($sanitize(isset($ta['provider']) ? $ta['provider'] : 'openai'));
            if (!in_array($provider, ['openai', 'claude'], true)) {
                $provider = 'openai';
            }
            $model = $sanitize(isset($ta['model']) ? $ta['model'] : '');
            if ($model === '' || preg_match('/[<>{};]/', $model)) {
                $model = 'claude-haiku-4-5-20251001';
            }
            $apiBase = $sanitize(isset($ta['api_base']) ? $ta['api_base'] : '');
            $themeRow = [
                'enabled' => isset($ta['enabled']) && (string)$ta['enabled'] === '1' ? '1' : '0',
                'provider' => $provider,
                'model' => $model,
                'api_base' => $apiBase,
                'timeout' => (string)max(10, min(300, intval(isset($ta['timeout']) ? $ta['timeout'] : 60))),
                'max_tokens' => (string)max(500, min(8000, intval(isset($ta['max_tokens']) ? $ta['max_tokens'] : 2000))),
            ];
            $newKey = isset($ta['api_key']) ? trim((string)$ta['api_key']) : '';
            if ($newKey !== '') {
                $themeRow['api_key'] = $newKey;
            } else {
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                if (isset($latest['theme_ai']['api_key']) && $latest['theme_ai']['api_key'] !== '') {
                    $themeRow['api_key'] = (string)$latest['theme_ai']['api_key'];
                } else {
                    $themeRow['api_key'] = isset($config_old['theme_ai']['api_key']) ? $config_old['theme_ai']['api_key'] : '';
                }
            }

            // 图像生成（logo/头像）独立配置：端点 / 密钥 / 模型；留空则回退到上方文本端点
            $themeRow['image_api_base'] = $sanitize(isset($ta['image_api_base']) ? $ta['image_api_base'] : '');
            $imgModel = $sanitize(isset($ta['image_model']) ? $ta['image_model'] : '');
            if (preg_match('/[<>{};]/', $imgModel)) {
                $imgModel = '';
            }
            $themeRow['image_model'] = $imgModel;
            $newImgKey = isset($ta['image_api_key']) ? trim((string)$ta['image_api_key']) : '';
            if ($newImgKey !== '') {
                $themeRow['image_api_key'] = $newImgKey;
            } else {
                $cfgFile2 = APP_PATH . 'extra/maccms.php';
                $latest2 = is_file($cfgFile2) ? include $cfgFile2 : [];
                if (isset($latest2['theme_ai']['image_api_key']) && $latest2['theme_ai']['image_api_key'] !== '') {
                    $themeRow['image_api_key'] = (string)$latest2['theme_ai']['image_api_key'];
                } else {
                    $themeRow['image_api_key'] = isset($config_old['theme_ai']['image_api_key']) ? $config_old['theme_ai']['image_api_key'] : '';
                }
            }

            $config_new = $config_old;
            $config_new['theme_ai'] = $themeRow;
            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $config = config('maccms');
        if (!isset($config['theme_ai']) || !is_array($config['theme_ai'])) {
            $config['theme_ai'] = [];
        }
        $config['theme_ai'] = array_merge([
            'enabled' => '1',
            'provider' => 'openai',
            'model' => 'claude-haiku-4-5-20251001',
            'api_base' => 'https://newapi.westsecret.com/v1',
            'api_key' => '',
            'timeout' => '60',
            'max_tokens' => '4000',
            'image_api_base' => '',
            'image_api_key' => '',
            'image_model' => 'gpt-image-1',
        ], $config['theme_ai']);
        $taKey = isset($config['theme_ai']['api_key']) ? trim((string)$config['theme_ai']['api_key']) : '';
        $this->assign('theme_ai_key_saved', $taKey !== '' ? 1 : 0);
        $this->assign('theme_ai_key_tail', $taKey !== '' ? substr($taKey, -6) : '');
        $imgKey = isset($config['theme_ai']['image_api_key']) ? trim((string)$config['theme_ai']['image_api_key']) : '';
        $this->assign('theme_ai_img_key_saved', $imgKey !== '' ? 1 : 0);
        $this->assign('theme_ai_img_key_tail', $imgKey !== '' ? substr($imgKey, -6) : '');
        $this->assign('theme_ai_models', ['claude-opus-4-8', 'claude-opus-4-7', 'claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001', 'claude-fable-5']);
        $this->assign('theme_ai_image_models', ['gpt-image-1', 'dall-e-3']);
        $this->assign('config', $config);
        $this->assign('title', lang('menu/configthemeai'));
        return $this->fetch('admin@system/configthemeai');

    }

    public function configanalytics()
    {
        if (Request()->isPost()) {
            $post = input('post.', '', 'htmlentities');
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if (!$validate->check($post)) {
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($post['__token__']);

            $config_old = config('maccms');
            $an = isset($post['analytics']) && is_array($post['analytics']) ? $post['analytics'] : [];

            $row = [
                'server_track' => isset($an['server_track']) && (string)$an['server_track'] === '1' ? '1' : '0',
                'track_region' => isset($an['track_region']) && (string)$an['track_region'] === '1' ? '1' : '0',
            ];

            $old_analytics = (isset($config_old['analytics']) && is_array($config_old['analytics'])) ? $config_old['analytics'] : [];
            $config_new = $config_old;
            $config_new['analytics'] = array_merge($old_analytics, $row);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $config = config('maccms');
        if (!isset($config['analytics']) || !is_array($config['analytics'])) {
            $config['analytics'] = [];
        }
        $config['analytics'] = array_merge([
            'server_track' => '0',
            'track_region' => '0',
        ], $config['analytics']);

        // 行为分析开关配置页迁移到新版后台主题（view）；旧版 view/ 不再承载新功能（铁律 2）
        $this->assign('config', $config);
        $this->assign('title', lang('admin/system/configanalytics'));
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
        return $this->fetch('system/configanalytics');
    }

    public function configaicontent()
    {
        if (Request()->isPost()) {
            $post = input('post.', '', 'htmlentities');
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if (!$validate->check($post)) {
                $err = $validate->getError();
                $msg = is_scalar($err) ? (string)$err : lang('param_err');
                return $this->ajaxErrorWithFreshToken($msg);
            }
            unset($post['__token__']);

            $config_old = config('maccms');
            $ai = isset($post['ai_content']) && is_array($post['ai_content']) ? $post['ai_content'] : [];

            $sanitize = function ($v) {
                return trim(strip_tags((string)$v));
            };

            $providers = ['openai', 'claude', 'gemini', 'deepseek', 'qwen', 'glm'];
            $provider = strtolower($sanitize(isset($ai['provider']) ? $ai['provider'] : 'openai'));
            if (!in_array($provider, $providers, true)) {
                $provider = 'openai';
            }

            $row = [
                'enabled' => isset($ai['enabled']) && (string)$ai['enabled'] === '1' ? '1' : '0',
                'use_ai_search_credentials' => isset($ai['use_ai_search_credentials']) && (string)$ai['use_ai_search_credentials'] === '1' ? '1' : '0',
                'provider' => $provider,
                'model' => $sanitize(isset($ai['model']) ? $ai['model'] : 'gpt-4o-mini'),
                'api_base' => $sanitize(isset($ai['api_base']) ? $ai['api_base'] : ''),
                'timeout' => (string)max(5, intval(isset($ai['timeout']) ? $ai['timeout'] : 30)),
                'max_tokens' => (string)max(256, intval(isset($ai['max_tokens']) ? $ai['max_tokens'] : 800)),
                'batch_size' => (string)max(1, min(100, intval(isset($ai['batch_size']) ? $ai['batch_size'] : 20))),
                'auto_adopt_empty' => isset($ai['auto_adopt_empty']) && (string)$ai['auto_adopt_empty'] === '1' ? '1' : '0',
            ];

            // 金钥留空 = 保留旧值。从最新的配置文件读，避免配置缓存过期把 key 弄丢。
            $newKey = isset($ai['api_key']) ? trim((string)$ai['api_key']) : '';
            if ($newKey !== '') {
                $row['api_key'] = $newKey;
            } else {
                $cfgFile = APP_PATH . 'extra/maccms.php';
                $latest = is_file($cfgFile) ? include $cfgFile : [];
                if (isset($latest['ai_content']['api_key']) && $latest['ai_content']['api_key'] !== '') {
                    $row['api_key'] = (string)$latest['ai_content']['api_key'];
                } else {
                    $row['api_key'] = isset($config_old['ai_content']['api_key']) ? $config_old['ai_content']['api_key'] : '';
                }
            }

            $config_new = $config_old;
            $config_new['ai_content'] = $row;

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));
        $this->assign('title', lang('admin/system/configaicontent'));
        // AI 内容标注配置页仅放新版后台主题（view）；旧版 view/ 不再承载新功能（铁律 2）
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
        return $this->fetch('system/configaicontent');
    }

    public function aisearchsync()
    {
        $post = input('post.', '', 'htmlentities');
        $token = isset($post['__token__']) ? (string)$post['__token__'] : '';
        $sessionToken = (string)session('__token__');
        if ($token === '' || $sessionToken === '' || !$this->safeHashEquals($sessionToken, $token)) {
            $fresh = token('__token__');
            return json(['code' => 1001, 'msg' => lang('token_err'), 'data' => ['__token__' => $fresh]]);
        }
        $provider = isset($post['provider']) ? trim((string)$post['provider']) : '';
        $config = config('maccms');
        $extCfg = isset($config['ai_search']['external_sources']) && is_array($config['ai_search']['external_sources'])
            ? $config['ai_search']['external_sources']
            : [];
        if ((string)(isset($extCfg['enabled']) ? $extCfg['enabled'] : '0') !== '1') {
            return json(['code' => 1002, 'msg' => lang('admin/system/configaisearch/ext_sync_disabled')]);
        }
        $runner = new ExternalSyncRunner();
        $result = $runner->runDueJobs($extCfg, $provider);
        return json(['code' => 1, 'msg' => lang('admin/system/configaisearch/ext_sync_ok'), 'data' => $result]);
    }

    private function safeHashEquals($knownString, $userString)
    {
        if (function_exists('hash_equals')) {
            return hash_equals((string)$knownString, (string)$userString);
        }
        $a = (string)$knownString;
        $b = (string)$userString;
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        $res = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $res |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $res === 0;
    }

    public function configlang(){
        $param = input();
        // 只接受注册表里的语言码：缓存的旧后台页面可能仍在提交已删除的语言码
        $lang = strtolower(trim((string)($param['lang'] ?? '')));
        if (!in_array($lang, mac_lang_codes(), true)) {
            return $this->ajaxErrorWithFreshToken(lang('param_err'));
        }
        $config = config('maccms');
        if (!isset($config['app'])) {
            $config['app'] = [];
        }
        $config['app']['lang'] = $lang;
        $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config);
        if ($res === false) {
            return $this->ajaxErrorWithFreshToken(lang('save_err'));
        }
        return json(['code' => 1, 'msg' => 'ok']);
    }

    /**
     * AJAX form error with a new __token__ (ThinkPHP deletes the session token when validation runs).
     */
    private function ajaxErrorWithFreshToken($msg)
    {
        $t = token('__token__');
        return $this->error($msg, null, ['__token__' => $t]);
    }

}
