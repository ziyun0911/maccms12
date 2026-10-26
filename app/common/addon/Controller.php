<?php
namespace app\common\addon;

use think\facade\View;

abstract class Controller
{
    protected $request;

    protected $addon;

    protected $noNeedLogin = [];
    protected $noNeedRight = [];

    public function __construct()
    {
        $this->request = request();
        $parts = explode('\\', static::class);
        $this->addon = isset($parts[1]) ? strtolower($parts[1]) : '';
    }

    public function _initialize()
    {
    }

    protected function fetch(string $template = '', array $vars = [])
    {
        $viewPath = root_path('addons') . $this->addon . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        View::config(['view_path' => $viewPath]);
        if (!isset($vars['title'])) {
            $vars['title'] = ucfirst($this->addon);
        }

        $file = $viewPath . str_replace(['/', ':'], DIRECTORY_SEPARATOR, $template) . '.html';
        if (is_file($file)) {
            $content = file_get_contents($file);
            if ($content !== false && preg_match('/\{(?:extend|include)\s+(?:name|file)="[A-Za-z0-9_]+@[^"]*"/i', $content)) {
                $html = View::display($this->resolveCrossModule($content, $viewPath), $vars);
                return addon_hook('view_filter', $html);
            }
        }

        $html = View::fetch($template, $vars);
        return addon_hook('view_filter', $html);
    }

    protected function resolveCrossModule(string $content, string $viewPath): string
    {
        return preg_replace_callback(
            '/\{(extend|include)\s+(name|file)="([A-Za-z0-9_]+)@([^"]+)"(\s*\/)?\}/i',
            function ($m) use ($viewPath) {
                $target = app()->getBasePath() . $m[3] . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR
                    . str_replace(['/', ':'], DIRECTORY_SEPARATOR, $m[4]);
                return '{' . $m[1] . ' ' . $m[2] . '="' . $this->relativePath($viewPath, $target) . '" /}';
            },
            $content
        );
    }

    private function relativePath(string $fromDir, string $to): string
    {
        $from = explode(DIRECTORY_SEPARATOR, trim(str_replace('/', DIRECTORY_SEPARATOR, $fromDir), DIRECTORY_SEPARATOR));
        $to   = explode(DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $to));

        while ($from && $to && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)) . implode('/', $to);
    }

    protected function assign($name, $value = null)
    {
        View::assign($name, $value);
        return $this;
    }

    protected function success($msg = '', $url = null, $data = null)
    {
        return json(['code' => 1, 'msg' => $msg, 'data' => $data, 'url' => $url]);
    }

    protected function error($msg = '', $url = null, $data = null)
    {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data, 'url' => $url]);
    }
}
