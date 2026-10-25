<?php
namespace app\index\controller;

use app\common\util\SearchService;

class Manga extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        return $this->label_fetch('manga/index');
    }

    public function type()
    {
        $info = $this->label_type();
        return $this->label_fetch(mac_tpl_fetch('manga', $info['type_tpl'], 'type'));
    }

    public function show()
    {
        $this->check_show();
        $info = $this->label_type();
        return $this->label_fetch(mac_tpl_fetch('manga', $info['type_tpl_list'], 'show'));
    }

    public function search()
    {
        $param = mac_param_url();
        $this->check_search($param);
        SearchService::logFromParam(12, $param);
        $this->label_search($param);
        return $this->label_fetch('manga/search');
    }

    public function detail()
    {
        $info = $this->label_manga_detail();
        return $this->label_fetch('manga/detail');
    }

    public function play()
    {
        $info = $this->label_manga_detail([], 0, true);
        return $this->label_fetch('manga/play');
    }
}

