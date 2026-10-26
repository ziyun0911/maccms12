<?php
namespace app\common\addon;

use ReflectionClass;
use ReflectionMethod;
use think\facade\Cache;
use think\facade\Db;

class Service
{
    public static function check(string $name): array
    {
        $name = strtolower(trim($name));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new AddonException('invalid addon name');
        }
        $dir = root_path('addons') . $name;
        if (!is_dir($dir)) {
            throw new AddonException('addon not found');
        }
        $infoFile = $dir . DIRECTORY_SEPARATOR . 'info.ini';
        if (!is_file($infoFile)) {
            throw new AddonException('info.ini not found');
        }
        $info = parse_ini_file($infoFile, false, INI_SCANNER_TYPED);
        if (!is_array($info) || empty($info['name']) || strtolower((string)$info['name']) !== $name) {
            throw new AddonException('addon info mismatch');
        }
        $class = addon_class($name);
        if ($class === '' || !class_exists($class)) {
            throw new AddonException('addon class not found');
        }
        return $info;
    }

    public static function install(string $name): bool
    {
        self::check($name);
        $class = addon_class($name);
        $addon = new $class();
        if (method_exists($addon, 'install') && $addon->install() === false) {
            throw new AddonException('addon install failed');
        }
        self::importsql($name);
        self::refresh();
        return true;
    }

    public static function uninstall(string $name, bool $force = false): bool
    {
        $name = strtolower(trim($name));
        $class = addon_class($name);
        if ($class !== '' && class_exists($class)) {
            $addon = new $class();
            if (method_exists($addon, 'uninstall') && $addon->uninstall() === false && !$force) {
                throw new AddonException('addon uninstall failed');
            }
        }

        $dir = root_path('addons') . $name;
        if (is_dir($dir)) {
            self::removeDir($dir);
        }
        AddonManager::reset();
        self::refresh();
        return true;
    }

    public static function enable(string $name, bool $force = false): bool
    {
        $info = addon_info($name);
        if (empty($info)) {
            throw new AddonException('addon not found');
        }
        $class = addon_class($name);
        if ($class === '' || !class_exists($class)) {
            throw new AddonException('addon class not found');
        }
        $addon = new $class();
        if (method_exists($addon, 'enable') && $addon->enable() === false && !$force) {
            throw new AddonException('addon enable failed');
        }
        $info['state'] = 1;
        addon_save_info($name, $info);
        AddonManager::reset();
        self::refresh();
        return true;
    }

    public static function disable(string $name, bool $force = false): bool
    {
        $info = addon_info($name);
        if (empty($info)) {
            throw new AddonException('addon not found');
        }
        $class = addon_class($name);
        if ($class !== '' && class_exists($class)) {
            $addon = new $class();
            if (method_exists($addon, 'disable') && $addon->disable() === false && !$force) {
                throw new AddonException('addon disable failed');
            }
        }
        $info['state'] = 0;
        addon_save_info($name, $info);
        AddonManager::reset();
        self::refresh();
        return true;
    }

    public static function importsql(string $name): void
    {
        $file = root_path('addons') . $name . DIRECTORY_SEPARATOR . 'install.sql';
        if (!is_file($file)) {
            return;
        }
        $sql = file_get_contents($file);
        $sql = str_replace('__PREFIX__', (string) config('database.connections.mysql.prefix'), $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            Db::execute($statement);
        }
    }

    public static function refresh(): bool
    {
        $hooks = [];
        foreach (AddonManager::enabled() as $name => $addon) {
            $ref = new ReflectionClass($addon);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->getDeclaringClass()->getName() === Addon::class) {
                    continue;
                }
                if (in_array(strtolower($method->getName()), ['install', 'uninstall', 'enable', 'disable', 'upgrade'], true)) {
                    continue;
                }
                $hook = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $method->getName()));
                $hooks[$hook][] = $name;
            }
        }
        ksort($hooks);

        $config = [
            'autoload' => true,
            'hooks'    => $hooks,
            'route'    => [],
        ];
        $file = app()->getBasePath() . 'extra' . DIRECTORY_SEPARATOR . 'addons.php';
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (@file_put_contents($file, $content) === false) {
            throw new AddonException('write addons config failed');
        }

        Cache::delete('hooks');
        Cache::delete('addons');
        return true;
    }

    protected static function removeDir(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
