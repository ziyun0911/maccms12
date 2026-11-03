<?php
namespace app\index\controller;

class Rss extends Base
{
    private const URLSET_TPL = 'rss/urlset';

    public function index()
    {
        return $this->xml($this->label_fetch('rss/index'));
    }

    public function baidu()
    {
        return $this->xml($this->label_fetch(self::URLSET_TPL));
    }

    public function google()
    {
        return $this->xml($this->label_fetch('rss/google'));
    }

    public function so()
    {
        return $this->xml($this->label_fetch(self::URLSET_TPL));
    }

    public function sogou()
    {
        return $this->xml($this->label_fetch(self::URLSET_TPL));
    }

    public function bing()
    {
        return $this->xml($this->label_fetch(self::URLSET_TPL));
    }

    public function sm()
    {
        return $this->xml($this->label_fetch(self::URLSET_TPL));
    }

    protected function xml($html)
    {
        return response($html, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }
}
