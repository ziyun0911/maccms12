<?php
namespace app\index\controller;


class Rss extends Base
{
    public function index()
    {
        return $this->xml($this->label_fetch('rss/index'));
    }

    public function baidu()
    {
        return $this->xml($this->label_fetch('rss/baidu'));
    }

    public function google()
    {
        return $this->xml($this->label_fetch('rss/google'));
    }

    public function so()
    {
        return $this->xml($this->label_fetch('rss/so'));
    }

    public function sogou()
    {
        return $this->xml($this->label_fetch('rss/sogou'));
    }

    public function bing()
    {
        return $this->xml($this->label_fetch('rss/bing'));
    }

    public function sm()
    {
        return $this->xml($this->label_fetch('rss/sm'));
    }

    protected function xml($html)
    {
        return response($html, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

}
