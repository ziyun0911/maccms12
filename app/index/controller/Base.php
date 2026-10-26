<?php
namespace app\index\controller;

use app\common\controller\All;
use ip_limit\IpLocationQuery;
class Base extends All
{
    var $_group;
    var $_user;

    public function __construct()
    {
        parent::__construct();
        
        $this->check_ip_limit();
        $this->check_site_status();
        $this->init_content_lang();
        $this->label_maccms();
        $this->check_browser_jump();
        $this->label_user();
    }

    protected function init_content_lang()
    {
        $lang = mac_get_content_lang();
        \app\common\model\ContentLang::setCurrent($lang);
        $this->assign('content_lang', $lang);
        $this->assign('content_lang_default', mac_content_lang_default());
        $this->assign('content_lang_list', mac_content_lang_allow_list());
        $items = mac_front_lang_list();
        $this->assign('content_lang_items', $items);
        $ui = '';
        foreach ($items as $item) {
            if (!empty($item['active'])) {
                $ui = $item['ui'];
                break;
            }
        }
        $this->assign('content_lang_ui', $ui);
    }

    protected function check_ip_limit()
    {
        $mainland_ip_limit = $GLOBALS['config']['site']['mainland_ip_limit'] ?? "0";

        if ($mainland_ip_limit == "0") {
            return;
        }
        
        $user_ip = mac_get_client_ip();
        try {
            $ipQuery = new IpLocationQuery();
            $country_code = $ipQuery->queryProvince($user_ip);
            if ($mainland_ip_limit == "1") {
                if ($country_code === "") {
                    echo $this->fetch('public/close');
                    die;
                }
            } elseif ($mainland_ip_limit == "2") {
                if ($country_code !== "") {
                    echo $this->fetch('public/close');
                    die;
                }
            }
        } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
            return;
        } catch (\Exception $e) {
            return;
        }
    }

    public function _empty()
    {
        header("HTTP/1.0 404 Not Found");
        echo  '<script>setTimeout(function (){location.href="'.MAC_PATH.'";},'.(2000).');</script>';
        $msg = lang('page_not_found');
        abort(404,$msg);
        exit;
    }

    protected function check_show($aj=0)
    {
        if($GLOBALS['config']['app']['show'] ==0){
            echo $this->error(lang('show_close'));
            exit;
        }
        if($GLOBALS['config']['app']['show_verify'] ==1 && $aj==0){
            if(empty(session('show_verify'))){
                mac_no_cahche();
                $this->assign('type','show');
                echo $this->label_fetch('public/verify');
                exit;
            }
        }
    }

    protected function check_ajax()
    {
        if($GLOBALS['config']['app']['ajax_page'] ==0){
            echo $this->error(lang('ajax_close'));
            exit;
        }
    }

    protected function check_search($param,$aj=0)
    {
        if($GLOBALS['config']['app']['search'] ==0){
            echo $this->error(lang('search_close'));
            exit;
        }
        $page = isset($param['page']) ? (int)$param['page'] : 1;
        if ($page === 1 && mac_get_time_span("last_searchtime") < $GLOBALS['config']['app']['search_timespan']) {
            echo $this->error(lang('search_frequently')."".$GLOBALS['config']['app']['search_timespan']."".lang('seconds'));
            exit;
        }
        if($GLOBALS['config']['app']['search_verify'] ==1 && $aj ==0){
            if(empty(session('search_verify'))){
                mac_no_cahche();
                $this->assign('type','search');
                echo $this->label_fetch('public/verify');
                exit;
            }
        }
    }

    protected function check_site_status()
    {
        if ($GLOBALS['config']['site']['site_status'] == 0) {
            $this->assign('close_tip',$GLOBALS['config']['site']['site_close_tip']);
            echo $this->fetch('public/close');
            die;
        }
    }

    protected function check_browser_jump()
    {
        if (ENTRANCE=='index' && $GLOBALS['config']['app']['browser_junmp'] == 1) {
            $agent = $_SERVER['HTTP_USER_AGENT'];
            if(strpos($agent, 'QQ/')||strpos($agent, 'MicroMessenger')!==false){
                echo $this->fetch('public/browser');
                die;
            }
        }
    }
}