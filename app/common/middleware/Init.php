<?php
namespace app\common\middleware;

use Closure;
use think\facade\Cache;
use think\facade\Config;
use think\Request;
use think\Response;

/**
 * 苹果CMS v12 —— 站点初始化中间件
 *
 * 由 TP5 的 common\behavior\Init 迁移而来。负责：
 *  - 同步主题配置到 $GLOBALS['mctheme']
 *  - 解析站群（多域名）/手机端，选定模板目录
 *  - 定义 MAC_* 常量
 *  - 设置视图路径、缓存、默认语言、静态资源替换串等 TP8 运行期配置
 */
class Init
{
    public function handle(Request $request, Closure $next)
    {
        // TP8 迁移兼容：maccms 为 PHP7 遗留代码，PHP8 的 Undefined array key/variable 等
        // E_WARNING/E_NOTICE 会被 TP8 Error 初始化器转为异常中断页面。这里按 TP5/PHP7 语义
        // 关闭这类良性告警的异常化，保留 E_ERROR/E_PARSE 等真实错误。
        error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);

        // 主题配置已在入口通过 app/extra 扫描加载，同步到 $GLOBALS 供模板与 mac_tpl_* 直接读取
        $GLOBALS['mctheme'] = config('mctheme') ?: ['theme' => []];

        $config = config('maccms');
        if (!isset($config['app']) || !is_array($config['app'])) {
            $config['app'] = [];
        }
        if (!array_key_exists('security_headers_base', $config['app'])) {
            $config['app']['security_headers_base'] = '1';
        }
        if (!array_key_exists('content_lang_list', $config['app'])) {
            $config['app']['content_lang_list'] = '';
        }
        if (empty($config['app']['content_lang_default'])) {
            $config['app']['content_lang_default'] = mac_content_lang_first();
        }
        if (!isset($config['meilisearch']) || !is_array($config['meilisearch'])) {
            $config['meilisearch'] = [
                'enabled'        => '0',
                'host'           => 'http://127.0.0.1:7700',
                'api_key'        => '',
                'index_uid'      => 'maccms_contents',
                'timeout'        => '8',
                'sync_on_save'   => '1',
                'search_only_wd' => '1',
            ];
        }
        if (!isset($config['template_cloud']) || !is_array($config['template_cloud'])) {
            $config['template_cloud'] = [
                'status'       => '0',
                'catalog_url'  => 'https://api.maccms.ai/templates/catalog.json',
                'cache_ttl'    => '10800',
            ];
        }
        if (!isset($config['addon_cloud']) || !is_array($config['addon_cloud'])) {
            $config['addon_cloud'] = [
                'status'         => '0',
                'catalog_url'    => 'https://api.maccms.ai/addons/catalog.json',
                'cache_ttl'      => '10800',
                'rate_limit'     => '10',
                'audit_max'      => '200',
                'legacy_catalog' => '1',
                'mock'           => '0',
            ];
        } else {
            if (!array_key_exists('legacy_catalog', $config['addon_cloud'])) {
                $config['addon_cloud']['legacy_catalog'] = '1';
            }
            if (!array_key_exists('mock', $config['addon_cloud'])) {
                $config['addon_cloud']['mock'] = '0';
            }
        }
        $domain = config('domain');

        $isMobile = 0;
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uachar = "/(nokia|sony|ericsson|mot|samsung|sgh|lg|philips|panasonic|alcatel|lenovo|meizu|cldc|midp|iphone|wap|mobile|android)/i";
        if (preg_match($uachar, $ua)) {
            $isMobile = 1;
        }

        $http_host = $_SERVER['HTTP_HOST'] ?? '';
        $resolved  = self::resolveSite($config['site'], $domain, $http_host, $isMobile);
        $config['site'] = $resolved['site'];

        $TMP_ISWAP      = $resolved['is_wap'];
        $TMP_TEMPLATEDIR = $resolved['template_dir'];
        $TMP_HTMLDIR    = $resolved['html_dir'];
        $TMP_ADSDIR     = $resolved['ads_dir'];

        define('MAC_URL', 'http' . '://' . 'www' . '.' . 'maccms' . '.' . 'la' . '/');
        define('MAC_NAME', '苹果CMS');
        define('MAC_PATH', $config['site']['install_dir'] . '');
        define('MAC_MOB', $TMP_ISWAP);
        define('MAC_ROOT_TEMPLATE', ROOT_PATH . 'template/' . $TMP_TEMPLATEDIR . '/' . $TMP_HTMLDIR . '/');
        define('MAC_PATH_TEMPLATE', MAC_PATH . 'template/' . $TMP_TEMPLATEDIR . '/');
        define('MAC_PATH_TPL', MAC_PATH_TEMPLATE . $TMP_HTMLDIR . '/');
        define('MAC_PATH_ADS', MAC_PATH_TEMPLATE . $TMP_ADSDIR . '/');
        define('MAC_PAGE_SP', $config['path']['page_sp'] . '');
        define('MAC_PLAYER_SORT', $config['app']['player_sort']);
        define('MAC_ADDON_PATH', ROOT_PATH . 'addons' . '/');
        define('MAC_ADDON_PATH_STATIC', ROOT_PATH . 'static/addons/');

        $GLOBALS['MAC_ROOT_TEMPLATE'] = ROOT_PATH . 'template/' . $TMP_TEMPLATEDIR . '/' . $TMP_HTMLDIR . '/';
        $GLOBALS['MAC_PATH_TEMPLATE'] = MAC_PATH . 'template/' . $TMP_TEMPLATEDIR . '/';
        $GLOBALS['MAC_PATH_TPL'] = $GLOBALS['MAC_PATH_TEMPLATE'] . $TMP_HTMLDIR . '/';
        $GLOBALS['MAC_PATH_ADS'] = $GLOBALS['MAC_PATH_TEMPLATE'] . $TMP_ADSDIR . '/';

        $GLOBALS['http_type'] = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'))
            ? 'https://' : 'http://';

        if (defined('ENTRANCE') && ENTRANCE == 'index') {
            Config::set([
                'dispatch_success_tmpl' => 'public/jump',
                'dispatch_error_tmpl'   => 'public/jump',
            ], 'app');
        }

        // TP8：模板路径统一挂在 view 配置组
        $viewPath = 'template/' . $TMP_TEMPLATEDIR . '/' . $TMP_HTMLDIR . '/';
        $viewConfig = ['view_path' => $viewPath];

        if (defined('ENTRANCE') && ENTRANCE == 'admin') {
            if (!is_dir(ROOT_PATH . $viewPath)) {
                $viewConfig['view_path'] = '';
            }
            // 后台模板目录统一为 view：跨应用 fetch（如 fetch('admin@index/index')）
            // 在 think-view 中按 view_dir_name 解析，须显式指到 view
            $viewConfig['view_dir_name'] = 'view';
        }
        Config::set($viewConfig, 'view');
        if (intval($config['app']['search_len']) < 1) {
            $config['app']['search_len'] = 50;
        }
        if (empty($config['app']['pathinfo_depr'])) {
            $config['app']['pathinfo_depr'] = '/';
        }
        Config::set(['pathinfo_depr' => $config['app']['pathinfo_depr']], 'app');

        if (intval($config['app']['cache_time']) < 1) {
            $config['app']['cache_time'] = 60;
        }

        if (!in_array($config['app']['cache_type'], ['file', 'memcache', 'memcached', 'redis'])) {
            $config['app']['cache_type'] = 'file';
        }
        if (!empty($config['app']['lang'])) {
            Config::set(['default_lang' => $config['app']['lang']], 'app');
            Config::set(['default_lang' => $config['app']['lang']], 'lang');
            // Lang 服务在应用初始化阶段就已按旧配置构造，仅改 Config 不会切换语言包
            \think\facade\Lang::setLangSet($config['app']['lang']);
        }

        // TP8：缓存采用 stores 结构，按 cache_type 切换 default 驱动并填充对应 store 参数
        $ct = $config['app']['cache_type'];
        $cacheConfig = config('cache');
        if (!is_array($cacheConfig)) {
            $cacheConfig = [];
        }
        $stores = isset($cacheConfig['stores']) && is_array($cacheConfig['stores']) ? $cacheConfig['stores'] : [];
        $cacheConfig['expire'] = (int)$config['app']['cache_time'];
        if ($ct === 'redis') {
            $cacheConfig['default'] = 'redis';
            $store = isset($stores['redis']) && is_array($stores['redis']) ? $stores['redis'] : ['type' => 'redis'];
            $store['host']     = $config['app']['cache_host'];
            $store['port']     = $config['app']['cache_port'];
            $store['password'] = $config['app']['cache_password'];
            $store['expire']   = (int)$config['app']['cache_time'];
            if (isset($config['app']['cache_db']) && intval($config['app']['cache_db']) > 0) {
                $store['select'] = intval($config['app']['cache_db']);
            }
            $stores['redis'] = $store;
        } elseif ($ct === 'memcache' || $ct === 'memcached') {
            $cacheConfig['default'] = $ct;
            $store = isset($stores[$ct]) && is_array($stores[$ct]) ? $stores[$ct] : ['type' => $ct];
            $store['host']     = $config['app']['cache_host'];
            $store['port']     = $config['app']['cache_port'];
            $store['username'] = $config['app']['cache_username'];
            $store['password'] = $config['app']['cache_password'];
            $store['expire']   = (int)$config['app']['cache_time'];
            $stores[$ct] = $store;
        } else {
            $store = isset($stores['file']) && is_array($stores['file']) ? $stores['file'] : ['type' => 'File'];
            $store['expire'] = (int)$config['app']['cache_time'];
            $stores['file']  = $store;
        }
        $cacheConfig['stores'] = $stores;
        Config::set($cacheConfig, 'cache');

        // 应用层修正静态资源替换串（TP8 使用 view.tpl_replace_string）
        $req = request();
        $base = $req->root();
        $root = strpos($base, '.') ? ltrim(dirname($base), DS) : $base;
        if ($root !== '') {
            $root = '/' . ltrim($root, '/');
        }
        $staticPath = '/static';
        $staticReplace = [
            '__ROOT__'   => $root,
            '__STATIC__' => $root . $staticPath,
            '__CSS__'    => $root . $staticPath . '/css',
            '__JS__'     => $root . $staticPath . '/js',
        ];
        $existReplace = config('view.tpl_replace_string');
        if (!is_array($existReplace)) {
            $existReplace = [];
        }
        Config::set(['tpl_replace_string' => array_merge($existReplace, $staticReplace)], 'view');

        $GLOBALS['config'] = $config;

        return $next($request);
    }

    /**
     * 站群（多域名）匹配。
     */
    public static function matchDomain($domain_list, $host, $mob_status)
    {
        if (!is_array($domain_list) || empty($domain_list) || empty($host)) {
            return null;
        }
        if (!empty($domain_list[$host]) && is_array($domain_list[$host])) {
            return ['entry' => $domain_list[$host], 'is_wap_domain' => 0];
        }
        if ($mob_status == 1) {
            $host_lc = mac_domain_host($host);
            if ($host_lc !== '') {
                foreach ($domain_list as $one) {
                    if (is_array($one) && !empty($one['site_wapurl'])
                        && mac_domain_host($one['site_wapurl']) === $host_lc) {
                        return ['entry' => $one, 'is_wap_domain' => 1];
                    }
                }
            }
        }
        return null;
    }

    /**
     * 取站群条目自己配置的手机模板目录。
     */
    public static function domainMobTemplate($entry)
    {
        if (!is_array($entry) || !isset($entry['mob_template_dir'])) {
            return '';
        }
        $dir = trim($entry['mob_template_dir']);
        if ($dir === 'no') {
            return '';
        }
        return $dir;
    }

    /**
     * 选定本次请求使用的模板目录。
     */
    public static function pickTemplate($site, $is_mobile, $is_domain, $is_wap_domain, $domain_mob_tpl, $host)
    {
        $is_wap = 0;
        $mob_status = isset($site['mob_status']) ? $site['mob_status'] : 0;

        if ($is_mobile) {
            if ($is_domain) {
                if ($mob_status == 1 && $is_wap_domain) {
                    $is_wap = 1;
                } elseif ($mob_status == 2 && $domain_mob_tpl !== '') {
                    $is_wap = 1;
                }
            } else {
                $wapurl = isset($site['site_wapurl']) ? $site['site_wapurl'] : '';
                if ($mob_status == 2 || ($mob_status == 1 && $wapurl !== '' && $host === $wapurl)) {
                    $is_wap = 1;
                }
            }
        }

        if ($is_wap) {
            return [
                'is_wap'       => 1,
                'template_dir' => $site['mob_template_dir'],
                'html_dir'     => $site['mob_html_dir'],
                'ads_dir'      => $site['mob_ads_dir'],
            ];
        }
        return [
            'is_wap'       => 0,
            'template_dir' => $site['template_dir'],
            'html_dir'     => $site['html_dir'],
            'ads_dir'      => $site['ads_dir'],
        ];
    }

    /**
     * 合并站群条目并选定模板目录。
     */
    public static function resolveSite($site, $domain_list, $host, $is_mobile)
    {
        $is_domain = 0;
        $is_wap_domain = 0;
        $domain_mob_tpl = '';

        $mob_status = isset($site['mob_status']) ? $site['mob_status'] : 0;
        $hit = self::matchDomain($domain_list, $host, $mob_status);

        if ($hit !== null) {
            $is_domain = 1;
            $is_wap_domain = $hit['is_wap_domain'];
            $domain_mob_tpl = self::domainMobTemplate($hit['entry']);
            $entry_wapurl = isset($hit['entry']['site_wapurl']) ? mac_domain_host($hit['entry']['site_wapurl']) : '';
            $global_mob_tpl = isset($site['mob_template_dir']) ? trim($site['mob_template_dir']) : '';
            $global_tpl = isset($site['template_dir']) ? trim($site['template_dir']) : '';
            $global_mob_html = isset($site['mob_html_dir']) ? $site['mob_html_dir'] : '';
            $global_mob_ads = isset($site['mob_ads_dir']) ? $site['mob_ads_dir'] : '';

            $site = array_merge($site, $hit['entry']);

            $entry_tpl = isset($site['template_dir']) ? trim($site['template_dir']) : '';
            if ($entry_tpl === '' || $entry_tpl === 'no') {
                $site['template_dir'] = $global_tpl;
            }

            $site['site_wapurl'] = $entry_wapurl !== '' ? $entry_wapurl : $site['site_url'];
            if ($domain_mob_tpl !== '') {
                $site['mob_template_dir'] = $domain_mob_tpl;
                $site['mob_html_dir'] = $site['html_dir'];
                $site['mob_ads_dir'] = $site['ads_dir'];
            } elseif ($global_mob_tpl !== '' && $global_mob_tpl !== 'no') {
                $site['mob_template_dir'] = $global_mob_tpl;
                $site['mob_html_dir'] = $global_mob_html;
                $site['mob_ads_dir'] = $global_mob_ads;
            } else {
                $site['mob_template_dir'] = $site['template_dir'];
                $site['mob_html_dir'] = $site['html_dir'];
                $site['mob_ads_dir'] = $site['ads_dir'];
            }
        }

        $res = self::pickTemplate($site, $is_mobile, $is_domain, $is_wap_domain, $domain_mob_tpl, $host);
        $res['site'] = $site;
        $res['is_domain'] = $is_domain;
        $res['is_wap_domain'] = $is_wap_domain;

        return $res;
    }
}
