<?php
namespace app\common\addon;

abstract class Addon
{
    public $info = [];

    public $addon_path = '';

    public $addon_dir = '';

    public function __construct()
    {
        $name = $this->getName();
        $this->addon_path = root_path('addons') . $name . DIRECTORY_SEPARATOR;
        $this->addon_dir  = $this->addon_path;

        $info = addon_info($name);
        if (!empty($info)) {
            $this->info = array_merge($this->info, $info);
        }
    }

    public function getName(): string
    {
        $parts = explode('\\', static::class);
        return isset($parts[1]) ? strtolower($parts[1]) : '';
    }

    public function getInfo($name = null)
    {
        if ($name === null) {
            return $this->info;
        }
        return $this->info[$name] ?? null;
    }

    public function getConfig($name = null, $default = null)
    {
        $config = addon_config($this->getName());
        if ($name === null) {
            return $config;
        }
        return array_key_exists($name, $config) ? $config[$name] : $default;
    }

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }
}
