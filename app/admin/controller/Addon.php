<?php
namespace app\admin\controller;

use app\common\addon\AddonException;
use app\common\addon\Service;
use think\facade\Cache;
use think\facade\Config;
use think\Exception;
use app\common\util\AddonSecureInstaller;
use app\common\util\Dir;
use app\common\util\UploadCheck;
use think\facade\Log;

class Addon extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->view->config(['view_path' => APP_PATH . 'admin/view/']);
    }

    public function index()
    {
        $cloud = new \app\common\util\AddonCloudService();
        $this->assign('title', lang('admin/addon/title'));
        $this->assign('addon_cloud_enabled', $cloud->isEnabled() ? 1 : 0);
        $this->assign('addon_audit', $cloud->recentAudit(5));
        return $this->fetch('addon/index');
    }

    public function config()
    {
        $name = $this->addonNameFromInput();
        if ($name === '') {
            return $this->error(lang('param_err'));
        }
        if (!is_dir(root_path('addons') . $name)) {
            return $this->error(lang('get_dir_err'));
        }

        $info = addon_info($name);
        $config = addon_full_config($name);
        if (!$info) {
            return $this->error(lang('get_addon_info_err'));
        }
        if (!is_array($config)) {
            $config = [];
        }

        if ($this->request->isPost()) {
            $post = $this->request->post();
            $validate = validate('app\\common\\validate\\Token', [], false, false);
            if (!$validate->check($post)) {
                $t = token('__token__');
                return $this->error($validate->getError() ?: lang('param_err'), null, ['__token__' => $t]);
            }

            $params = $this->request->post('row/a');
            if (!is_array($params)) {
                $params = [];
            }

            $validated = $this->validateAddonConfigRows($config, $params);
            if ($validated['ok'] !== true) {
                $t = token('__token__');
                return $this->error($validated['msg'], null, ['__token__' => $t]);
            }
            $config = $validated['config'];

            try {
                addon_save_full_config($name, $config);
                Service::refresh();
                Cache::delete('hooks');
                Cache::delete('addons');
                $t = token('__token__');
                return $this->success(lang('admin/addon/config_save_ok'), null, ['__token__' => $t]);
            } catch (Exception $e) {
                $t = token('__token__');
                return $this->error(lang('admin/addon/op_fail'), null, ['__token__' => $t]);
            }
        }

        $this->assign('info', $info);
        $this->assign('config', $config);
        $this->assign('title', lang('admin/addon/config_title'));
        return $this->fetch('addon/config');
    }

    public function info()
    {
    }

    public function downloaded()
    {
        $offset = (int)$this->request->get('offset');
        $limit = (int)$this->request->get('limit');
        $search = $this->request->get('search');
        $search = htmlspecialchars(strip_tags((string)$search));
        if ($search === '' || $search === null) {
            $wd = input('wd/s', '');
            $search = htmlspecialchars(strip_tags((string)$wd));
        }

        $onlineaddons = $this->fetchLegacyOnlineCatalog();

        $addons = addon_list();
        $list = [];
        foreach ($addons as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            if ($search && stripos($v['name'], $search) === false
                && stripos((string)(isset($v['intro']) ? $v['intro'] : ''), $search) === false
                && stripos((string)(isset($v['title']) ? $v['title'] : ''), $search) === false) {
                continue;
            }
            if (isset($onlineaddons[$v['name']]) && is_array($onlineaddons[$v['name']])) {
                $meta = $onlineaddons[$v['name']];
                foreach (['title', 'intro', 'author', 'image', 'price'] as $mk) {
                    if (empty($v[$mk]) && !empty($meta[$mk])) {
                        $v[$mk] = $meta[$mk];
                    }
                }
            }
            if (!isset($v['image'])) {
                $v['image'] = '';
            } else {
                $v['image'] = $this->sanitizeLegacyImageUrl($v['image']);
            }
            if (!isset($v['price'])) {
                $v['price'] = '0.00';
            }
            if (!isset($v['author'])) {
                $v['author'] = '';
            }
            $v['url'] = function_exists('addon_url') ? addon_url($v['name']) : '';
            $v['createtime'] = is_dir(root_path('addons') . $v['name']) ? filemtime(root_path('addons') . $v['name']) : 0;
            $v['install'] = '1';
            $v['legacy_meta'] = isset($onlineaddons[$v['name']]) ? 1 : 0;
            $list[] = $v;
        }
        $total = count($list);
        if ($limit) {
            $list = array_slice($list, $offset, $limit);
        }
        return json(['total' => $total, 'rows' => $list, 'legacy_catalog' => !empty($onlineaddons) ? 1 : 0]);
    }

    public function install()
    {
        return $this->error(lang('admin/addon/cloud_use_catalog'));
    }

    protected function fetchLegacyOnlineCatalog()
    {
        $cfg = isset($GLOBALS['config']['addon_cloud']) && is_array($GLOBALS['config']['addon_cloud'])
            ? $GLOBALS['config']['addon_cloud'] : [];
        if (isset($cfg['legacy_catalog']) && (string)$cfg['legacy_catalog'] === '0') {
            return [];
        }

        $flag = isset($GLOBALS['config']['app']['cache_flag']) ? $GLOBALS['config']['app']['cache_flag'] : 'mac';
        $key = $flag . '_onlineaddons_v2';
        $onlineaddons = Cache::get($key);
        if (is_array($onlineaddons)) {
            return $onlineaddons;
        }

        $onlineaddons = [];
        $raw = $this->fetchLegacyCatalogBody('https://api.maccms.com/addon/index');
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (!empty($json['rows']) && is_array($json['rows'])) {
                $n = 0;
                foreach ($json['rows'] as $row) {
                    if ($n >= 2000) {
                        break;
                    }
                    if (!is_array($row) || empty($row['name'])) {
                        continue;
                    }
                    $name = strtolower(trim((string)$row['name']));
                    if (!AddonSecureInstaller::isValidName($name)) {
                        continue;
                    }
                    $onlineaddons[$name] = [
                        'name' => $name,
                        'title' => $this->sanitizeLegacyText(isset($row['title']) ? $row['title'] : $name, 120),
                        'intro' => $this->sanitizeLegacyText(isset($row['intro']) ? $row['intro'] : '', 500),
                        'author' => $this->sanitizeLegacyText(isset($row['author']) ? $row['author'] : '', 64),
                        'version' => $this->sanitizeLegacyText(isset($row['version']) ? $row['version'] : '', 32),
                        'image' => $this->sanitizeLegacyImageUrl(isset($row['image']) ? $row['image'] : ''),
                        'price' => $this->sanitizeLegacyText(isset($row['price']) ? $row['price'] : '0.00', 16),
                        'category_id' => isset($row['category_id']) ? (int)$row['category_id'] : 0,
                    ];
                    $n++;
                }
            }
        }
        Cache::set($key, $onlineaddons, 600);
        return $onlineaddons;
    }

    protected function fetchLegacyCatalogBody($url)
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if ($host !== 'api.maccms.com') {
            return false;
        }
        if (!function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MacCMS-AddonLegacyCatalog/1.0');
        if ($scheme === 'https') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }
        if (defined('CURLOPT_MAXFILESIZE')) {
            curl_setopt($ch, CURLOPT_MAXFILESIZE, 2097152);
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);

        if ($errno !== 0 || $body === false || $httpCode < 200 || $httpCode >= 300) {
            return false;
        }
        if ($primaryIp !== '' && !$this->legacyIpIsPublic($primaryIp)) {
            return false;
        }
        if (!is_string($body) || strlen($body) > 2097152) {
            return false;
        }
        return $body;
    }

    protected function legacyIpIsPublic($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (strpos($ip, '169.254.') === 0) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    protected function sanitizeLegacyText($text, $max = 200)
    {
        $s = trim(strip_tags((string)$text));
        $s = str_replace(["\0", "\r", "\n"], '', $s);
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max);
        }
        return substr($s, 0, $max);
    }

    protected function sanitizeLegacyImageUrl($url)
    {
        $u = trim((string)$url);
        if ($u === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $u)) {
            if (strlen($u) > 500 || preg_match('#^(javascript|data|vbscript):#i', $u)) {
                return '';
            }
            return $u;
        }
        if (isset($u[0]) && $u[0] === '/' && !(isset($u[1]) && $u[1] === '/')) {
            if (strlen($u) > 500 || preg_match('#^(javascript|data|vbscript):#i', $u)) {
                return '';
            }
            return $u;
        }
        return '';
    }

    public function cloudCatalog()
    {
        $cloud = new \app\common\util\AddonCloudService();
        if (!$cloud->isEnabled()) {
            return json(['total' => 0, 'rows' => [], 'enabled' => 0, 'error' => 'disabled', 'mock' => 0]);
        }
        $res = $cloud->fetchCatalog(false);
        $items = $cloud->enrichItems($res['items']);
        if (count($items) > 500) {
            $items = array_slice($items, 0, 500);
        }
        $search = htmlspecialchars(strip_tags((string)$this->request->get('search', '')));
        if ($search !== '') {
            $items = array_values(array_filter($items, function ($v) use ($search) {
                if (!is_array($v)) {
                    return false;
                }
                return stripos((string)$v['name'], $search) !== false
                    || stripos((string)(isset($v['title']) ? $v['title'] : ''), $search) !== false
                    || stripos((string)(isset($v['intro']) ? $v['intro'] : ''), $search) !== false;
            }));
        }
        foreach ($items as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            unset($items[$i]['package_url'], $items[$i]['package_hash'], $items[$i]['signature']);
            if (isset($items[$i]['image'])) {
                $items[$i]['image'] = $this->sanitizeLegacyImageUrl($items[$i]['image']);
            }
        }
        $items = array_values($items);
        $isMock = (isset($res['error']) && $res['error'] === 'mock') ? 1 : 0;
        return json([
            'total' => count($items),
            'rows' => $items,
            'enabled' => 1,
            'error' => $res['error'],
            'mock' => $isMock,
        ]);
    }

    public function cloudRefresh()
    {
        if (!$this->request->isPost()) {
            return $this->error(lang('param_err'));
        }
        $post = $this->request->post();
        $validate = validate('app\\common\\validate\\Token', [], false, false);
        if (!$validate->check($post)) {
            $t = token('__token__');
            return $this->error($validate->getError() ?: lang('param_err'), null, ['__token__' => $t]);
        }
        $cloud = new \app\common\util\AddonCloudService();
        if (!$cloud->isEnabled()) {
            $t = token('__token__');
            return $this->error(lang('admin/addon/cloud_disabled'), null, ['__token__' => $t]);
        }
        $adminId = !empty($this->_admin['admin_id']) ? (int)$this->_admin['admin_id'] : 0;
        $adminIp = $this->request->ip();
        $rl = $cloud->checkRateLimit($adminId, $adminIp);
        if ($rl['ok'] !== true) {
            $t = token('__token__');
            return $this->error($rl['msg'], null, ['__token__' => $t]);
        }
        $cloud->hitRateLimit($adminId, $adminIp);
        $res = $cloud->fetchCatalog(true);
        $t = token('__token__');
        if ($res['error'] !== '' && empty($res['items'])) {
            return $this->error(lang('admin/addon/cloud_refresh_fail', [$res['error']]), null, ['__token__' => $t]);
        }
        return $this->success(lang('admin/addon/cloud_refresh_ok', [count($res['items'])]), null, [
            '__token__' => $t,
            'count' => count($res['items']),
            'error' => $res['error'],
        ]);
    }

    public function cloudInstall()
    {
        if (!$this->request->isPost()) {
            return $this->error(lang('param_err'));
        }
        $post = $this->request->post();
        $validate = validate('app\\common\\validate\\Token', [], false, false);
        if (!$validate->check($post)) {
            $t = token('__token__');
            return $this->error($validate->getError() ?: lang('param_err'), null, ['__token__' => $t]);
        }
        $id = input('post.id/s', '');
        $adminId = 0;
        if (!empty($this->_admin['admin_id'])) {
            $adminId = (int)$this->_admin['admin_id'];
        }
        $cloud = new \app\common\util\AddonCloudService();
        $res = $cloud->installById($id, $adminId, $this->request->ip());
        $t = token('__token__');
        if (empty($res['code'])) {
            return $this->error(isset($res['msg']) ? $res['msg'] : lang('admin/addon/op_fail'), null, ['__token__' => $t]);
        }
        return $this->success($res['msg'], null, array_merge(is_array($res['data']) ? $res['data'] : [], ['__token__' => $t]));
    }

    public function uninstall()
    {
        if (!$this->request->isPost()) {
            return $this->error(lang('param_err'));
        }
        $post = $this->request->post();
        $validate = validate('app\\common\\validate\\Token', [], false, false);
        if (!$validate->check($post)) {
            $t = token('__token__');
            return $this->error($validate->getError() ?: lang('param_err'), null, ['__token__' => $t]);
        }
        $name = $this->addonNameFromInput();
        if ($name === '') {
            $t = token('__token__');
            return $this->error(lang('admin/addon/path_err'), null, ['__token__' => $t]);
        }
        $force = (int)input('force/d', 0);
        $bak = AddonSecureInstaller::backupAddon($name);
        if ($bak === false) {
            $t = token('__token__');
            return $this->error(lang('admin/addon/uninstall_backup_fail'), null, ['__token__' => $t]);
        }
        try {
            Service::uninstall($name, $force);
            $t = token('__token__');
            return $this->success(lang('uninstall_ok'), null, ['__token__' => $t]);
        } catch (AddonException $e) {
            if ($bak && !is_dir(root_path('addons') . $name)) {
                AddonSecureInstaller::restoreAddon($name, $bak);
            }
            $t = token('__token__');
            return $this->error(lang('admin/addon/uninstall_fail'), null, ['__token__' => $t]);
        } catch (Exception $e) {
            if ($bak && !is_dir(root_path('addons') . $name)) {
                AddonSecureInstaller::restoreAddon($name, $bak);
            }
            $t = token('__token__');
            return $this->error(lang('admin/addon/uninstall_fail'), null, ['__token__' => $t]);
        }
    }

    public function state()
    {
        if (!$this->request->isPost()) {
            return $this->error(lang('param_err'));
        }
        $post = $this->request->post();
        $validate = validate('app\\common\\validate\\Token', [], false, false);
        if (!$validate->check($post)) {
            $t = token('__token__');
            return $this->error($validate->getError() ?: lang('param_err'), null, ['__token__' => $t]);
        }
        $name = $this->addonNameFromInput();
        if ($name === '') {
            $t = token('__token__');
            return $this->error(lang('admin/addon/path_err'), null, ['__token__' => $t]);
        }
        $action = input('action/s', '');
        $force = (int)input('force/d', 0);
        if ($action !== 'enable' && $action !== 'disable') {
            $t = token('__token__');
            return $this->error(lang('param_err'), null, ['__token__' => $t]);
        }
        try {
            if ($action === 'enable') {
                Service::enable($name, $force);
            } else {
                Service::disable($name, $force);
            }
            Cache::delete('__menu__');
            $t = token('__token__');
            return $this->success(lang('opt_ok'), null, ['__token__' => $t]);
        } catch (AddonException $e) {
            $t = token('__token__');
            return $this->error(lang('admin/addon/op_fail'), null, ['__token__' => $t]);
        } catch (Exception $e) {
            $t = token('__token__');
            return $this->error(lang('admin/addon/op_fail'), null, ['__token__' => $t]);
        }
    }

    public function local()
    {
        if (!$this->request->isPost()) {
            return $this->error(lang('param_err'));
        }
        $param = input();
        $validate = validate('app\\common\\validate\\Token', [], false, false);
        if (!$validate->check($param)) {
            $t = token('__token__');
            return $this->error($validate->getError() ?: lang('param_err'), null, ['__token__' => $t]);
        }

        $file = $this->request->file('file');
        if (empty($file)) {
            $t = token('__token__');
            return $this->error(lang('admin/addon/upload_empty'), null, ['__token__' => $t]);
        }

        $err = UploadCheck::checkError($file, ['zip'], 10240000);
        if ($err !== '') {
            $t = token('__token__');
            return $this->error($err, null, ['__token__' => $t]);
        }

        $addonTmpDir = runtime_path() . 'addons' . DS;
        if (!is_dir($addonTmpDir)) {
            @mkdir($addonTmpDir, 0755, true);
        }

        $saveName = uniqid() . '.zip';
        try {
            $moved = $file->move($addonTmpDir, $saveName);
        } catch (\Throwable $e) {
            Log::error('addon upload move failed name=' . $saveName . ' err=' . $e->getMessage());
            $t = token('__token__');
            return $this->error(lang('admin/addon/upload_fail'), null, ['__token__' => $t]);
        }

        $tmpFile = $moved->getPathname();
        $stageDir = $addonTmpDir . 'stage_' . pathinfo($moved->getFilename(), PATHINFO_FILENAME);
        if (is_dir($stageDir)) {
            AddonSecureInstaller::purgeDir($stageDir);
        }

        try {
            $extracted = AddonSecureInstaller::extractZipSafe($tmpFile, $stageDir);
            @unlink($tmpFile);
            if (empty($extracted['ok'])) {
                AddonSecureInstaller::purgeDir($stageDir);
                $t = token('__token__');
                return $this->error($extracted['msg'], null, ['__token__' => $t]);
            }

            $infoFile = $stageDir . DS . 'info.ini';
            if (!is_file($infoFile)) {
                AddonSecureInstaller::purgeDir($stageDir);
                $t = token('__token__');
                return $this->error(lang('admin/addon/lack_config_err'), null, ['__token__' => $t]);
            }

            $ini = @parse_ini_file($infoFile, false, INI_SCANNER_TYPED) ?: [];
            $name = isset($ini['name']) ? (string)$ini['name'] : '';
            if ($name === '' || !AddonSecureInstaller::isValidName($name)) {
                AddonSecureInstaller::purgeDir($stageDir);
                $t = token('__token__');
                return $this->error(lang('admin/addon/name_empty_err'), null, ['__token__' => $t]);
            }

            $sig = AddonSecureInstaller::verifyPackageSignature($stageDir);
            if (empty($sig['ok'])) {
                AddonSecureInstaller::purgeDir($stageDir);
                $t = token('__token__');
                return $this->error($sig['msg'], null, ['__token__' => $t]);
            }

            $mainClassFile = $stageDir . DS . ucfirst($name) . '.php';
            if (!is_file($mainClassFile)) {
                AddonSecureInstaller::purgeDir($stageDir);
                $t = token('__token__');
                return $this->error(lang('admin/addon/lack_class_err'), null, ['__token__' => $t]);
            }

            $newAddonDir = root_path('addons') . $name . DS;
            if (is_dir($newAddonDir)) {
                AddonSecureInstaller::purgeDir($stageDir);
                $t = token('__token__');
                return $this->error(lang('admin/addon/haved_err'), null, ['__token__' => $t]);
            }

            $cloud = new \app\common\util\AddonCloudService();
            $lock = $cloud->acquireInstallLock($name);
            if ($lock === false) {
                AddonSecureInstaller::purgeDir($stageDir);
                $t = token('__token__');
                return $this->error(lang('admin/addon/cloud_busy'), null, ['__token__' => $t]);
            }

            try {
                if (!@rename($stageDir, $newAddonDir)) {
                    Dir::copyDir($stageDir, $newAddonDir);
                    AddonSecureInstaller::purgeDir($stageDir);
                    if (!is_dir($newAddonDir) || !is_file($newAddonDir . 'info.ini')) {
                        if (is_dir($newAddonDir)) {
                            AddonSecureInstaller::purgeDir($newAddonDir);
                        }
                        $t = token('__token__');
                        return $this->error(lang('admin/addon/extract_dir_fail'), null, ['__token__' => $t]);
                    }
                }

                try {
                    Service::check($name);
                    $addonInfo = addon_info($name);
                    if (!empty($addonInfo['state'])) {
                        $addonInfo['state'] = 0;
                        addon_save_info($name, $addonInfo);
                    }

                    $class = addon_class($name);
                    if ($class && class_exists($class)) {
                        $addon = new $class();
                        $addon->install();
                    }
                    Service::importsql($name);
                    Service::refresh();

                    $addonInfo = addon_info($name);
                    $addonInfo['config'] = addon_config($name) ? 1 : 0;
                    $t = token('__token__');
                    $addonInfo['__token__'] = $t;
                    return $this->success(lang('install_ok'), null, $addonInfo);
                } catch (Exception $e) {
                    if (is_dir($newAddonDir)) {
                        AddonSecureInstaller::purgeDir($newAddonDir);
                    }
                    $t = token('__token__');
                    return $this->error(lang('admin/addon/install_rollback'), null, ['__token__' => $t]);
                }
            } finally {
                $cloud->releaseInstallLock($lock);
            }
        } catch (Exception $e) {
            @unlink($tmpFile);
            if (is_dir($stageDir)) {
                AddonSecureInstaller::purgeDir($stageDir);
            }
            $t = token('__token__');
            return $this->error(lang('admin/addon/op_fail'), null, ['__token__' => $t]);
        }
    }

    public function add()
    {
        $this->assign('title', lang('local_setup'));
        return $this->fetch('addon/add');
    }

    public function upgrade()
    {
        if (!$this->request->isPost()) {
            return $this->error(lang('param_err'));
        }
        $post = $this->request->post();
        $validate = validate('app\\common\\validate\\Token', [], false, false);
        if (!$validate->check($post)) {
            $t = token('__token__');
            return $this->error($validate->getError() ?: lang('param_err'), null, ['__token__' => $t]);
        }
        $id = input('post.id/s', '');
        if ($id === '') {
            return $this->error(lang('admin/addon/cloud_use_catalog'));
        }
        $adminId = 0;
        if (!empty($this->_admin['admin_id'])) {
            $adminId = (int)$this->_admin['admin_id'];
        }
        $cloud = new \app\common\util\AddonCloudService();
        $res = $cloud->installById($id, $adminId, $this->request->ip());
        $t = token('__token__');
        if (empty($res['code'])) {
            return $this->error(isset($res['msg']) ? $res['msg'] : lang('admin/addon/op_fail'), null, ['__token__' => $t]);
        }
        return $this->success($res['msg'], null, array_merge(is_array($res['data']) ? $res['data'] : [], ['__token__' => $t]));
    }

    protected function addonNameFromInput()
    {
        $name = input('name/s', '');
        $name = strtolower(trim($name));
        if (!AddonSecureInstaller::isValidName($name)) {
            return '';
        }
        return $name;
    }

    protected function validateAddonConfigRows($config, $params)
    {
        $allowedTypes = [
            'string', 'text', 'number', 'datetime', 'array',
            'checkbox', 'radio', 'select', 'selects',
            'image', 'images', 'file', 'files', 'bool',
        ];

        foreach ($config as $k => $v) {
            if (!is_array($v) || empty($v['name'])) {
                return ['ok' => false, 'msg' => lang('admin/addon/config_schema_err')];
            }
            $field = (string)$v['name'];
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                return ['ok' => false, 'msg' => lang('admin/addon/config_schema_err')];
            }
            $type = isset($v['type']) ? (string)$v['type'] : 'string';
            if (!in_array($type, $allowedTypes, true)) {
                return ['ok' => false, 'msg' => lang('admin/addon/config_type_err', [$type])];
            }
            $label = (isset($v['title']) && (string)$v['title'] !== '') ? (string)$v['title'] : $field;

            $rule = isset($v['rule']) ? (string)$v['rule'] : '';
            $required = (strpos($rule, 'required') !== false);
            $hasParam = array_key_exists($field, $params);

            if (!$hasParam) {
                if ($required) {
                    return ['ok' => false, 'msg' => lang('admin/addon/config_required', [$label])];
                }
                if (in_array($type, ['checkbox', 'selects'], true)) {
                    $config[$k]['value'] = '';
                }
                continue;
            }

            $raw = $params[$field];
            if ($type === 'array') {
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $raw = is_array($decoded) ? $decoded : [];
                }
                if (!is_array($raw)) {
                    return ['ok' => false, 'msg' => lang('admin/addon/config_invalid', [$label])];
                }
                $config[$k]['value'] = $raw;
                continue;
            }

            if (is_array($raw)) {
                $vals = [];
                foreach ($raw as $one) {
                    $one = is_scalar($one) ? (string)$one : '';
                    if ($one === '') {
                        continue;
                    }
                    $vals[] = $one;
                }
                if ($required && empty($vals)) {
                    return ['ok' => false, 'msg' => lang('admin/addon/config_required', [$label])];
                }
                if (in_array($type, ['checkbox', 'selects', 'radio', 'select'], true)) {
                    $options = isset($v['content']) && is_array($v['content']) ? $v['content'] : [];
                    foreach ($vals as $one) {
                        if (!array_key_exists($one, $options)) {
                            return ['ok' => false, 'msg' => lang('admin/addon/config_option_err', [$label])];
                        }
                    }
                }
                $config[$k]['value'] = implode(',', $vals);
                continue;
            }

            $value = (string)$raw;
            if ($required && trim($value) === '') {
                return ['ok' => false, 'msg' => lang('admin/addon/config_required', [$label])];
            }

            if ($type === 'number' && $value !== '' && !is_numeric($value)) {
                return ['ok' => false, 'msg' => lang('admin/addon/config_invalid', [$label])];
            }
            if ($type === 'bool') {
                $value = ($value === '1' || $value === 'true' || $value === 'on') ? '1' : '0';
            }
            if (in_array($type, ['select', 'radio'], true) && $value !== '') {
                $options = isset($v['content']) && is_array($v['content']) ? $v['content'] : [];
                if (!array_key_exists($value, $options)) {
                    return ['ok' => false, 'msg' => lang('admin/addon/config_option_err', [$label])];
                }
            }
            if (strlen($value) > 65535) {
                return ['ok' => false, 'msg' => lang('admin/addon/config_invalid', [$label])];
            }
            $config[$k]['value'] = $value;
        }

        return ['ok' => true, 'msg' => 'ok', 'config' => $config];
    }
}
