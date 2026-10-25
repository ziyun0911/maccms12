<?php
namespace app\common\addon;

use ReflectionClass;
use ReflectionMethod;
use think\facade\Log;

/**
 * 插件钩子分发：按插件公开方法名（驼峰转下划线）匹配钩子。
 */
class AddonManager
{
    /** @var array|null name => Addon|null */
    protected static $enabled = null;

    /** @var array hook => [[object, method], ...] */
    protected static $handlers = [];

    public static function enabled(): array
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        self::$enabled = [];
        foreach (addon_list() as $name => $info) {
            if (empty($info['state'])) {
                continue;
            }
            $class = addon_class($name);
            if ($class === '' || !class_exists($class)) {
                continue;
            }
            try {
                self::$enabled[$name] = new $class();
            } catch (\Throwable $e) {
                Log::error('[addon] load ' . $name . ' failed: ' . $e->getMessage());
            }
        }

        return self::$enabled;
    }

    public static function toMethod(string $hook): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $hook))));
    }

    /**
     * 分发钩子；参数以引用传给插件方法（兼容 view_filter 这类原地修改），
     * 修改后的参数作为返回值交给调用方。
     * @return mixed 处理后的参数
     */
    public static function dispatch(string $hook, $params = null)
    {
        $method = self::toMethod($hook);
        foreach (self::handlers($hook) as $handler) {
            try {
                $handler[0]->{$handler[1]}($params);
            } catch (\Throwable $e) {
                Log::error('[addon] hook ' . $hook . ' failed: ' . $e->getMessage());
            }
        }
        return $params;
    }

    protected static function handlers(string $hook): array
    {
        if (isset(self::$handlers[$hook])) {
            return self::$handlers[$hook];
        }

        $method = self::toMethod($hook);
        $handlers = [];
        foreach (self::enabled() as $addon) {
            if (!method_exists($addon, $method)) {
                continue;
            }
            try {
                $ref = new ReflectionMethod($addon, $method);
            } catch (\Throwable $e) {
                continue;
            }
            if ($ref->isStatic() || $ref->getDeclaringClass()->getName() === Addon::class) {
                continue;
            }
            $handlers[] = [$addon, $method];
        }

        self::$handlers[$hook] = $handlers;
        return $handlers;
    }

    public static function reset(): void
    {
        self::$enabled = null;
        self::$handlers = [];
    }
}
