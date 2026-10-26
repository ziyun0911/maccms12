<?php
namespace app\admin\controller;

class Doc extends Base
{
    const DOC_DIR = '说明文档';

    const ROOT_FILES = 'README.md';

    const MAX_SIZE = 1048576;

    protected static $allowExt = ['txt', 'md', 'conf', 'ini', 'config'];

    public function index()
    {
        $list = $this->fileList();
        $this->assign('groups', $this->groupList($list));
        $this->assign('total', count($list));
        $this->assign('title', lang('admin/doc/title'));
        return $this->fetch('admin@doc/index');
    }

    public function info()
    {
        $param = input();
        $item = $this->resolve(isset($param['id']) ? $param['id'] : '');
        if ($item === null) {
            return $this->error(lang('admin/doc/not_found'));
        }

        $truncated = false;
        if ($item['size'] > self::MAX_SIZE) {
            $fp = @fopen($item['path'], 'rb');
            $content = $fp !== false ? (string)fread($fp, self::MAX_SIZE) : '';
            if ($fp !== false) {
                fclose($fp);
            }
            $truncated = true;
        } else {
            $content = (string)@file_get_contents($item['path']);
        }

        $this->assign('doc', $item);
        $this->assign('content', $content);
        $this->assign('truncated', $truncated);
        $this->assign('max_size', $this->formatSize(self::MAX_SIZE));
        $this->assign('title', $item['name'] . ' - ' . lang('admin/doc/title'));
        return $this->fetch('admin@doc/info');
    }

    protected function fileList()
    {
        $base = rtrim(root_path(), '/\\');
        $list = [];

        $dir = $base . DIRECTORY_SEPARATOR . self::DOC_DIR;
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || !$this->extAllowed($file->getBasename())) {
                    continue;
                }
                $list[] = $this->item($file->getPathname(), $base);
            }
        }

        $readme = $base . DIRECTORY_SEPARATOR . self::ROOT_FILES;
        if (is_file($readme)) {
            $list[] = $this->item($readme, $base);
        }

        usort($list, function ($a, $b) {
            return strcmp($a['rel'], $b['rel']);
        });
        return $list;
    }

    protected function groupList($list)
    {
        $groups = [];
        foreach ($list as $item) {
            $dir = $item['dir'];
            if (!isset($groups[$dir])) {
                $groups[$dir] = [
                    'dir'   => $dir,
                    'label' => $dir === '' ? lang('admin/doc/group_root') : $dir,
                    'files' => [],
                ];
            }
            $groups[$dir]['files'][] = $item;
        }
        foreach ($groups as $k => $v) {
            $groups[$k]['count'] = count($v['files']);
        }
        return array_values($groups);
    }

    protected function resolve($id)
    {
        if (!is_string($id) || $id === '' || strpos($id, "\0") !== false) {
            return null;
        }
        $id = str_replace('\\', '/', $id);
        if ($id[0] === '/' || strpos($id, ':') !== false) {
            return null;
        }
        $segments = explode('/', $id);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        if (!$this->extAllowed($segments[count($segments) - 1])) {
            return null;
        }

        $base = rtrim(root_path(), '/\\');
        $real = realpath($base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
        if ($real === false || !is_file($real)) {
            return null;
        }

        $docsReal = realpath($base . DIRECTORY_SEPARATOR . self::DOC_DIR);
        $readmeReal = realpath($base . DIRECTORY_SEPARATOR . self::ROOT_FILES);
        $inDocs = $docsReal !== false && strpos($real, $docsReal . DIRECTORY_SEPARATOR) === 0;
        if (!$inDocs && $real !== $readmeReal) {
            return null;
        }

        return $this->item($real, $base);
    }

    protected function item($path, $base)
    {
        $rel = str_replace('\\', '/', substr($path, strlen($base) + 1));
        $dir = strpos($rel, '/') === false ? '' : dirname($rel);
        return [
            'rel' => $rel,
            'name' => basename($rel),
            'dir' => $dir,
            'path' => $path,
            'size' => filesize($path),
            'size_text' => $this->formatSize(filesize($path)),
            'mtime' => filemtime($path),
        ];
    }

    protected function extAllowed($name)
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, self::$allowExt, true);
    }

    protected function formatSize($bytes)
    {
        $bytes = max(0, intval($bytes));
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 2) . ' MB';
    }
}
