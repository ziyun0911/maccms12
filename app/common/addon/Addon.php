<?php
namespace app\common\addon;

/**
 * 插件基类：提供插件信息、配置与目录定位。
 */
abstract class Addon
{
    /** @var array 插件信息（info.ini） */
    public $info = [];

    /** @var string 插件目录（含末尾分隔符） */
    public $addon_path = '';

    /** @var string 插件目录别名 */
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

    /**
     * @param string|null $name 为空时返回全部信息
     * @return mixed
     */
    public function getInfo($name = null)
    {
        if ($name === null) {
            return $this->info;
        }
        return $this->info[$name] ?? null;
    }

    /**
     * @param string|null $name 为空时返回全部配置项的值
     * @return mixed
     */
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
