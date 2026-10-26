<?php
namespace app\common\util;
class Dir {
    private $_values = array();
    public $error = "";

    public function __construct($path = '', $pattern = '*') {
        if (!$path) return false;
        if (substr($path, -1) != "/") $path .= "/";
        $this->listFile($path, $pattern);
    }

    public static function create($path, $mode = 0755) {
      if(is_dir($path)) return TRUE;
      $path = str_replace("\\", "/", $path);
      if(substr($path, -1) != '/') $path = $path.'/';
      $temp = explode('/', $path);
      $cur_dir = '';
      $max = count($temp) - 1;
      for($i=0; $i<$max; $i++) {
        $cur_dir .= $temp[$i].'/';
        if (@is_dir($cur_dir)) continue;
        @mkdir($cur_dir, $mode, true);
        @chmod($cur_dir, $mode);
      }
      return is_dir($path);
    }

    public static function listFile($pathname, $pattern = '*') {
        static $_listDirs = array();
        $guid = md5($pathname . $pattern);
        if (!isset($_listDirs[$guid])) {
            $dir = array();
            $list = glob($pathname . $pattern);
            foreach ($list as $i => $file) {
                $dir[$i]['filename'] = preg_replace('/^.+[\\\\\\/]/', '', $file);
                $dir[$i]['pathname'] = realpath($file);
                $dir[$i]['owner'] = fileowner($file);
                $dir[$i]['perms'] = fileperms($file);
                $dir[$i]['inode'] = fileinode($file);
                $dir[$i]['group'] = filegroup($file);
                $dir[$i]['path'] = dirname($file);
                $dir[$i]['atime'] = fileatime($file);
                $dir[$i]['ctime'] = filectime($file);
                $dir[$i]['size'] = filesize($file);
                $dir[$i]['type'] = filetype($file);
                $dir[$i]['ext'] = is_file($file) ? strtolower(substr(strrchr(basename($file), '.'), 1)) : '';
                $dir[$i]['mtime'] = filemtime($file);
                $dir[$i]['isDir'] = is_dir($file);
                $dir[$i]['isFile'] = is_file($file);
                $dir[$i]['isLink'] = is_link($file);
                $dir[$i]['isReadable'] = is_readable($file);
                $dir[$i]['isWritable'] = is_writable($file);
            }
            $cmp_func = function ($a, $b) {
                $k = 'isDir';
                if ($a[$k] == $b[$k]) {
                    return 0;
                }
                return $a[$k] > $b[$k] ? -1 : 1;
            };
            usort($dir, $cmp_func);
            $this->_values = $dir;
            $_listDirs[$guid] = $dir;
        } else {
            $this->_values = $_listDirs[$guid];
        }
    }

    public static function current($arr) {
        if (!is_array($arr)) {
            return false;
        }
        return current($arr);
    }

    public static function getATime() {
        $current = $this->current($this->_values);
        return $current['atime'];
    }

    public static function getCTime() {
        $current = $this->current($this->_values);
        return $current['ctime'];
    }

    public static function getChildren() {
        $current = $this->current($this->_values);
        if ($current['isDir']) {
            return new Dir($current['pathname']);
        }
        return false;
    }

    public static function getFilename() {
        $current = $this->current($this->_values);
        return $current['filename'];
    }

    public static function getGroup() {
        $current = $this->current($this->_values);
        return $current['group'];
    }

    public static function getInode() {
        $current = $this->current($this->_values);
        return $current['inode'];
    }

    public static function getMTime() {
        $current = $this->current($this->_values);
        return $current['mtime'];
    }

    function getOwner() {
        $current = $this->current($this->_values);
        return $current['owner'];
    }

    public static function getPath() {
        $current = $this->current($this->_values);
        return $current['path'];
    }

    public static function getPathname() {
        $current = $this->current($this->_values);
        return $current['pathname'];
    }

    public static function getPerms() {
        $current = $this->current($this->_values);
        return $current['perms'];
    }

    public static function getSize() {
        $current = $this->current($this->_values);
        return $current['size'];
    }

    public static function getType() {
        $current = $this->current($this->_values);
        return $current['type'];
    }

    public static function isDir() {
        $current = $this->current($this->_values);
        return $current['isDir'];
    }

    public static function isFile() {
        $current = $this->current($this->_values);
        return $current['isFile'];
    }

    public static function isLink() {
        $current = $this->current($this->_values);
        return $current['isLink'];
    }

    public static function isExecutable() {
        $current = $this->current($this->_values);
        return $current['isExecutable'];
    }

    public static function isReadable() {
        $current = $this->current($this->_values);
        return $current['isReadable'];
    }

    public static function getIterator() {
        return new ArrayObject($this->_values);
    }

    public static function toArray() {
        return $this->_values;
    }

    public static function isEmpty($directory) {
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != "..") {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }

    public static function getList($directory) {
        $scandir = scandir($directory);
        $dir = [];
        foreach ($scandir as $k => $v) {
            if ($v == '.' || $v == '..') {
                continue;
            }
            $dir[] = $v;
        }
        return $dir;
    }

    public static function delDir($directory, $subdir = true) {
        if (is_dir($directory) == false) {
            return false;
        }
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != "..") {
                is_dir("$directory/$file") ?
                                Dir::delDir("$directory/$file") :
                                @unlink("$directory/$file");
            }
        }
        if (readdir($handle) == false) {
            closedir($handle);
            rmdir($directory);
        }
    }

    public static function del($directory) {
        if (is_dir($directory) == false) {
            return false;
        }
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != ".." && is_file("$directory/$file")) {
                unlink("$directory/$file");
            }
        }
        closedir($handle);
    }

    public static function copyDir($source, $destination) {
        if (is_dir($source) == false) {
            return false;
        }
        if (is_dir($destination) == false) {
            mkdir($destination, 0755);
        }
        $handle = opendir($source);
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                if (is_dir("$source/$file")) {
                    Dir::copyDir("$source/$file", "$destination/$file");
                } else {
                    copy("$source/$file", "$destination/$file");
                }
            }
        }
        closedir($handle);
    }
}

?>