<?php
namespace app\common\util;

class PublishPage
{
    public static function isFrontDoor()
    {
        if (!defined('ENTRANCE') || ENTRANCE !== 'index') {
            return false;
        }
        if (strtolower(request()->controller()) !== 'index' || strtolower(request()->action()) !== 'index') {
            return false;
        }
        $param = mac_param_url();
        if ((int) $param['page'] !== 1) {
            return false;
        }
        if ((int) $param['ajax'] !== 0) {
            return false;
        }
        $intFilterKeys = ['tid', 'rid', 'pid', 'sid', 'nid'];
        $blockKeys = ['id', 'ids', 'wd', 'tid', 'rid', 'pid', 'sid', 'nid', 'class', 'type', 'file', 'name', 'en', 'state', 'area', 'year', 'lang', 'letter', 'actor', 'director', 'tag', 'order', 'by', 'url', 'sex', 'version', 'blood', 'starsign', 'domain'];
        foreach ($blockKeys as $k) {
            if (!isset($param[$k])) {
                continue;
            }
            $v = $param[$k];
            if (in_array($k, $intFilterKeys, true)) {
                if ((int) $v !== 0) {
                    return false;
                }
                continue;
            }
            if ($v !== '' && $v !== null) {
                return false;
            }
        }
        return true;
    }

    public static function cookieName()
    {
        return 'mac_publish_entered';
    }

    public static function hasEnteredCookie()
    {
        return (string) cookie(self::cookieName()) === '1';
    }

    public static function setEnteredCookie()
    {
        cookie(self::cookieName(), '1', ['expire' => 31536000, 'path' => '/']);
    }

    public static function sendNoStoreHeaders()
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');
    }

    public static function siteHomeUrl()
    {
        $cfg = isset($GLOBALS['config']) && is_array($GLOBALS['config']) ? $GLOBALS['config'] : [];
        if (empty($cfg)) {
            $cfg = config('maccms');
            $cfg = is_array($cfg) ? $cfg : [];
        }
        $suffix = isset($cfg['path']['suffix']) ? trim((string) $cfg['path']['suffix'], " \t\n\r\0\x0B.") : 'html';
        if ($suffix === '') {
            $suffix = 'html';
        }
        $dotExt = '.' . $suffix;
        $macPath = defined('MAC_PATH') ? (string) MAC_PATH : '/';
        if ($macPath === '' || $macPath[0] !== '/') {
            $macPath = '/' . ltrim($macPath, '/');
        }
        $base = rtrim($macPath, '/');
        $rewrite = isset($cfg['rewrite']['status']) ? (int) $cfg['rewrite']['status'] : 0;
        $routeOn = !empty($cfg['rewrite']['route_status']);

        if ($routeOn) {
            $pathSeg = 'sitehome' . $dotExt;
        } else {
            $pathSeg = 'index/home' . $dotExt;
        }

        if ($rewrite === 0 || !$routeOn) {
            $url = ($base === '' ? '' : $base) . '/index.php/' . $pathSeg;
        } else {
            $url = ($base === '' ? '' : $base . '/') . $pathSeg;
        }
        $url = preg_replace('#([^:])//+#', '$1/', $url);
        if (!empty($cfg['rewrite']['suffix_hide']) && (int) $cfg['rewrite']['suffix_hide'] === 1) {
            $tail = $dotExt;
            $len = strlen($tail);
            if ($len > 0 && substr($url, -$len) === $tail) {
                $url = substr($url, 0, -$len) . '/';
            }
        }
        return $url;
    }

    public static function documentBaseHref()
    {
        $macPath = defined('MAC_PATH') ? (string) MAC_PATH : '/';
        if ($macPath === '' || $macPath[0] !== '/') {
            $macPath = '/' . ltrim($macPath, '/');
        }
        $path = rtrim($macPath, '/') . '/';
        if ($path === '//') {
            $path = '/';
        }
        $domain = '';
        if (function_exists('request') && request()) {
            $domain = (string) request()->domain();
        }
        if ($domain === '' && !empty($GLOBALS['config']['site']['site_url'])) {
            $host = trim((string) $GLOBALS['config']['site']['site_url']);
            $scheme = (!empty($GLOBALS['http_type']) ? (string) $GLOBALS['http_type'] : 'http://');
            $domain = rtrim($scheme, ':/') . '://' . ltrim($host, '/');
        }
        return $domain !== '' ? $domain . $path : $path;
    }

    public static function wrapThemeHomeHtml($html)
    {
        $html = (string) $html;
        if ($html === '' || stripos($html, '<base ') !== false) {
            return $html;
        }
        $baseHref = self::documentBaseHref();
        if ($baseHref === '') {
            return $html;
        }
        $tag = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">';
        $replaced = preg_replace('/<head(\b[^>]*)>/i', '<head$1>' . $tag, $html, 1, $count);
        return ($count > 0) ? $replaced : $html;
    }

    public static function groupUrl($slug)
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $slug);
        if ($slug === '') {
            return '';
        }
        $cfg = isset($GLOBALS['config']) && is_array($GLOBALS['config']) ? $GLOBALS['config'] : [];
        if (empty($cfg)) {
            $cfg = config('maccms');
            $cfg = is_array($cfg) ? $cfg : [];
        }
        $suffix = isset($cfg['path']['suffix']) ? trim((string) $cfg['path']['suffix'], " \t\n\r\0\x0B.") : 'html';
        if ($suffix === '') {
            $suffix = 'html';
        }
        $dotExt = '.' . $suffix;
        $macPath = defined('MAC_PATH') ? (string) MAC_PATH : '/';
        if ($macPath === '' || $macPath[0] !== '/') {
            $macPath = '/' . ltrim($macPath, '/');
        }
        $base = rtrim($macPath, '/');
        $rewrite = isset($cfg['rewrite']['status']) ? (int) $cfg['rewrite']['status'] : 0;
        $routeOn = !empty($cfg['rewrite']['route_status']);

        if ($routeOn) {
            $pathSeg = 'publish-' . $slug . $dotExt;
        } else {
            $pathSeg = 'index/publish_group/id/' . $slug . $dotExt;
        }

        if ($rewrite === 0) {
            $url = ($base === '' ? '' : $base) . '/index.php/' . $pathSeg;
        } else {
            $url = ($base === '' ? '' : $base . '/') . $pathSeg;
        }
        $url = preg_replace('#([^:])//+#', '$1/', $url);
        if (!empty($cfg['rewrite']['suffix_hide']) && (int) $cfg['rewrite']['suffix_hide'] === 1) {
            $tail = $dotExt;
            $len = strlen($tail);
            if ($len > 0 && substr($url, -$len) === $tail) {
                $url = substr($url, 0, -$len) . '/';
            }
        }
        return $url;
    }

    public static function parseLinks($raw)
    {
        $raw = (string) $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = array_filter(array_map('trim', explode("\n", $raw)), 'strlen');
        $out = [];
        foreach ($lines as $line) {
            if (count($out) >= 10) {
                break;
            }
            $parts = explode('|', $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            $name = trim($parts[0]);
            $url = trim($parts[1]);
            if ($name === '' || $url === '') {
                continue;
            }
            $url = self::sanitizeUrl($url);
            if ($url === '') {
                continue;
            }
            $out[] = ['name' => $name, 'url' => $url];
        }
        return $out;
    }

    public static function sanitizeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $url = mac_filter_xss($url);
        $lower = strtolower($url);
        if (strpos($lower, 'javascript:') === 0 || strpos($lower, 'data:') === 0) {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (isset($url[0]) && ($url[0] === '/' || $url[0] === '.')) {
            return $url;
        }
        return '';
    }

    public static function parseGroups($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            if (count($out) >= 20) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (string) $row['id'] : '';
            if (!preg_match('/^[a-z0-9_-]{1,32}$/', $id)) {
                continue;
            }
            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            if ($title === '' || mb_strlen($title, 'UTF-8') > 80) {
                continue;
            }
            $hint = isset($row['hint']) ? trim((string) $row['hint']) : '';
            if (mb_strlen($hint, 'UTF-8') > 500) {
                $hint = mb_substr($hint, 0, 500, 'UTF-8');
            }
            $urlsIn = isset($row['urls']) && is_array($row['urls']) ? $row['urls'] : [];
            $urls = [];
            foreach ($urlsIn as $u) {
                if (count($urls) >= 30) {
                    break;
                }
                if (is_array($u) && isset($u['url'])) {
                    $u = $u['url'];
                }
                $clean = self::sanitizeUrl((string) $u);
                if ($clean !== '') {
                    $urls[] = $clean;
                }
            }
            if (count($urls) < 1) {
                continue;
            }
            $out[] = ['id' => $id, 'title' => $title, 'hint' => $hint, 'urls' => $urls];
        }
        return $out;
    }

    public static function buildGroupsJsonFromUiPost($rows)
    {
        if (!is_array($rows)) {
            return '';
        }
        $rowsList = [];
        foreach ($rows as $k => $row) {
            if ($k === '__sent' || $k === '_sent') {
                continue;
            }
            if (is_array($row)) {
                $rowsList[] = $row;
            }
        }
        $built = [];
        foreach ($rowsList as $row) {
            if (count($built) >= 20) {
                break;
            }
            $id = isset($row['id']) ? trim((string) $row['id']) : '';
            if (!preg_match('/^[a-z0-9_-]{1,32}$/', $id)) {
                continue;
            }
            $title = isset($row['title']) ? trim((string) $row['title']) : '';
            if ($title === '' || mb_strlen($title, 'UTF-8') > 80) {
                continue;
            }
            $hint = isset($row['hint']) ? trim((string) $row['hint']) : '';
            if (mb_strlen($hint, 'UTF-8') > 500) {
                $hint = mb_substr($hint, 0, 500, 'UTF-8');
            }
            $urlsIn = isset($row['urls']) ? $row['urls'] : [];
            if (!is_array($urlsIn)) {
                $urlsIn = [$urlsIn];
            }
            $urls = [];
            foreach ($urlsIn as $u) {
                if (count($urls) >= 30) {
                    break;
                }
                $clean = self::sanitizeUrl(trim((string) $u));
                if ($clean !== '') {
                    $urls[] = $clean;
                }
            }
            if (count($urls) < 1) {
                continue;
            }
            $built[] = ['id' => $id, 'title' => $title, 'hint' => $hint, 'urls' => $urls];
        }
        if (count($built) < 1) {
            return '';
        }

        return json_encode($built, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function findGroupById(array $groups, $id)
    {
        $id = (string) $id;
        if ($id === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $id)) {
            return null;
        }
        foreach ($groups as $g) {
            if ($g['id'] === $id) {
                return $g;
            }
        }
        return null;
    }

    public static function htmlLangAttr()
    {
        $lang = mac_site_lang();
        $map = [
            'zh-cn' => 'zh-CN',
            'zh-tw' => 'zh-TW',
            'en-us' => 'en',
            'ja-jp' => 'ja',
            'ko-kr' => 'ko',
            'ru-ru' => 'ru',
        ];
        if (isset($map[$lang])) {
            return $map[$lang];
        }
        $parts = explode('-', $lang, 2);
        if (count($parts) === 2 && $parts[1] !== '') {
            return $parts[0] . '-' . strtoupper($parts[1]);
        }
        return $parts[0];
    }

    public static function logoUrl()
    {
        $raw = isset($GLOBALS['config']['site']['site_logo']) ? trim((string) $GLOBALS['config']['site']['site_logo']) : '';
        if ($raw !== '') {
            $url = mac_url_img($raw);
            if ($url !== '') {
                return $url;
            }
        }
        return MAC_PATH . 'static/images/logo.jpg';
    }

    public static function pageTitle($siteName, $pageTitle)
    {
        $siteName = trim((string) $siteName);
        $pageTitle = trim((string) $pageTitle);
        if ($siteName === '') {
            return $pageTitle;
        }
        if ($pageTitle === '') {
            return $siteName;
        }
        return $siteName . ' - ' . $pageTitle;
    }

    public static function isPublishGateEnabled()
    {
        $site = [];
        if (isset($GLOBALS['config']['site']) && is_array($GLOBALS['config']['site'])) {
            $site = $GLOBALS['config']['site'];
        } else {
            $maccms = config('maccms');
            if (is_array($maccms) && isset($maccms['site']) && is_array($maccms['site'])) {
                $site = $maccms['site'];
            }
        }
        return !empty($site['site_publish_status']) && (string) $site['site_publish_status'] === '1';
    }

    public static function routeTarget($entry)
    {
        if (!is_array($entry) || !isset($entry[0])) {
            return '';
        }
        return trim((string) $entry[0]);
    }

    public static function mergePublishRoutes(array $route)
    {
        $defaults = [
            'sitehome' => ['index/home', [], []],
            'publish-<id>' => ['index/publish_group', [], []],
        ];
        $gate = self::isPublishGateEnabled();

        if ($gate) {
            $prepend = [];
            foreach ($defaults as $key => $def) {
                if (!isset($route[$key])) {
                    $prepend[$key] = $def;
                }
            }
            if ($prepend !== []) {
                $route = $prepend + $route;
            }
            return $route;
        }

        foreach ($defaults as $key => $def) {
            if (!isset($route[$key])) {
                continue;
            }
            if (self::routeTarget($route[$key]) === self::routeTarget($def)) {
                unset($route[$key]);
            }
        }
        return $route;
    }
}
