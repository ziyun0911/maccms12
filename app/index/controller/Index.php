<?php
namespace app\index\controller;

use app\common\util\PublishPage;

class Index extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function assign_publish_permanent()
    {
        $rawT = isset($GLOBALS['config']['site']['site_publish_permanent_text']) ? trim((string) $GLOBALS['config']['site']['site_publish_permanent_text']) : '';
        $rawU = isset($GLOBALS['config']['site']['site_publish_permanent_url']) ? (string) $GLOBALS['config']['site']['site_publish_permanent_url'] : '';
        $url = PublishPage::sanitizeUrl($rawU);
        $text = $rawT;
        if ($text === '' && $url !== '') {
            $text = $url;
        }
        $show = ($text !== '' || $url !== '');
        $this->assign('publish_has_permanent', $show ? 1 : 0);
        $this->assign('publish_permanent_text', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $this->assign('publish_permanent_url', htmlspecialchars($url, ENT_QUOTES, 'UTF-8'));
    }

    protected function assign_publish_i18n()
    {
        $this->assign('publish_i18n_json', json_encode([
            'copyButton' => lang('index/publish_group/copy_button'),
            'copied' => lang('index/publish_group/copied'),
            'copyFail' => lang('index/publish_group/copy_fail'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
    }

    protected function assign_publish_meta($pageTitle)
    {
        $siteName = isset($GLOBALS['config']['site']['site_name']) ? trim((string) $GLOBALS['config']['site']['site_name']) : '';
        $this->assign('publish_html_lang', PublishPage::htmlLangAttr());
        $this->assign('publish_logo_url', htmlspecialchars(PublishPage::logoUrl(), ENT_QUOTES, 'UTF-8'));
        $this->assign('publish_page_title', htmlspecialchars(PublishPage::pageTitle($siteName, (string) $pageTitle), ENT_QUOTES, 'UTF-8'));
    }

    protected function assign_publish_partials()
    {
        $dir = APP_PATH . 'index' . DS . 'view' . DS . 'site_publish' . DS;
        $this->assign('publish_inc_permanent', $dir . '_partial_permanent.html');
        $this->assign('publish_inc_footer', $dir . '_partial_footer.html');
        $this->assign('publish_inc_browser_tips', $dir . '_partial_browser_tips.html');
    }

    protected function fetch_site_publish_view($name, $pageTitle = '')
    {
        $allowed = ['publish', 'publish_group'];
        if (!in_array($name, $allowed, true)) {
            abort(404, lang('page_not_found'));
        }
        if ($pageTitle === '') {
            $pageTitle = lang('index/publish/title');
        }
        PublishPage::sendNoStoreHeaders();
        $this->assign_publish_i18n();
        $this->assign_publish_meta($pageTitle);
        $this->assign_publish_partials();
        return $this->fetch(APP_PATH . 'index/view/site_publish/' . $name . '.html');
    }

    protected function assign_publish_enter_href()
    {
        $this->assign('publish_enter_href', (string) PublishPage::siteHomeUrl());
    }

    public function index()
    {
        $gate = !empty($GLOBALS['config']['site']['site_publish_status'])
            && (string) $GLOBALS['config']['site']['site_publish_status'] === '1';
        if ($gate && PublishPage::isFrontDoor()) {
            PublishPage::sendNoStoreHeaders();
            if (PublishPage::hasEnteredCookie()) {
                return $this->label_fetch('index/index');
            }

            $gcfg = isset($GLOBALS['config']['site']['site_publish_groups']) ? $GLOBALS['config']['site']['site_publish_groups'] : '';
            $groups = PublishPage::parseGroups($gcfg);

            if (count($groups) > 0) {
                $groupRows = [];
                foreach ($groups as $g) {
                    $groupRows[] = [
                        'id' => $g['id'],
                        'title' => htmlspecialchars($g['title'], ENT_QUOTES, 'UTF-8'),
                        'href' => (string) PublishPage::groupUrl($g['id']),
                    ];
                }
                $this->assign('publish_has_groups', 1);
                $this->assign('publish_groups', $groupRows);
                $this->assign_publish_permanent();
                $this->assign_publish_enter_href();
                return $this->fetch_site_publish_view('publish');
            }

            $links = PublishPage::parseLinks($GLOBALS['config']['site']['site_publish_links']);
            if (count($links) < 1) {
                $links = [];
            }
            foreach ($links as &$row) {
                $row['name'] = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
            }
            unset($row);
            $this->assign('publish_has_groups', 0);
            $this->assign('publish_links', $links);
            $this->assign_publish_permanent();
            $this->assign_publish_enter_href();
            return $this->fetch_site_publish_view('publish');
        }
        return $this->label_fetch('index/index');
    }

    public function publish_group()
    {
        PublishPage::sendNoStoreHeaders();
        $id = input('id', '', 'trim');
        $gcfg = isset($GLOBALS['config']['site']['site_publish_groups']) ? $GLOBALS['config']['site']['site_publish_groups'] : '';
        $groups = PublishPage::parseGroups($gcfg);
        $group = PublishPage::findGroupById($groups, $id);
        if ($group === null) {
            abort(404, lang('page_not_found'));
        }
        $this->assign('publish_back_href', (string) url('index/index'));
        $this->assign('publish_group_title', htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8'));
        $this->assign('publish_group_hint', htmlspecialchars($group['hint'], ENT_QUOTES, 'UTF-8'));
        $urlsOut = [];
        foreach ($group['urls'] as $u) {
            $urlsOut[] = [
                'url' => $u,
                'url_html' => htmlspecialchars($u, ENT_QUOTES, 'UTF-8'),
            ];
        }
        $this->assign('publish_group_urls', $urlsOut);
        $this->assign_publish_permanent();
        return $this->fetch_site_publish_view('publish_group', $group['title']);
    }

    public function home()
    {
        PublishPage::setEnteredCookie();
        return PublishPage::wrapThemeHomeHtml($this->label_fetch('index/index'));
    }

    public function ai_chat()
    {
        return $this->label_fetch('index/ai_chat');
    }
}
