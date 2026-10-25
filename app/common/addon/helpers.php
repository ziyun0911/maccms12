<?php

use app\common\addon\AddonManager;

/**
 * 插件信息 / 配置 / 钩子辅助函数（TP8 原生实现）。
 */

if (!function_exists('addon_info')) {
    function addon_info(string $name, bool $refresh = false): array
    {
        $name = strtolower(trim($name));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return [];
        }
        if (!$refresh && isset($GLOBALS['__addon_info_cache'][$name])) {
            return $GLOBALS['__addon_info_cache'][$name];
        }

        $file = root_path('addons') . $name . DIRECTORY_SEPARATOR . 'info.ini';
        if (!is_file($file)) {
            return [];
        }
        $info = @parse_ini_file($file, false, INI_SCANNER_TYPED);
        if (!is_array($info)) {
            return [];
        }
        $info['name']  = $name;
        $info['state'] = isset($info['state']) ? (int)$info['state'] : 0;
        $info['install'] = 1;

        $GLOBALS['__addon_info_cache'][$name] = $info;
        return $info;
    }
}

if (!function_exists('addon_list')) {
    function addon_list(bool $refresh = false): array
    {
        if (!$refresh && isset($GLOBALS['__addon_list_cache'])) {
            return $GLOBALS['__addon_list_cache'];
        }

        $list = [];
        foreach (glob(root_path('addons') . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                continue;
            }
            $info = addon_info($name, $refresh);
            if (!empty($info)) {
                $list[$name] = $info;
            }
        }
        ksort($list);

        $GLOBALS['__addon_list_cache'] = $list;
        return $list;
    }
}

if (!function_exists('addon_full_config')) {
    function addon_full_config(string $name): array
    {
        $name = strtolower(trim($name));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return [];
        }
        if (isset($GLOBALS['__addon_config_cache'][$name])) {
            return $GLOBALS['__addon_config_cache'][$name];
        }

        $rows = [];
        $base = root_path('addons') . $name . DIRECTORY_SEPARATOR;
        $schemaFile = $base . 'config.php';
        if (is_file($schemaFile)) {
            $schema = include $schemaFile;
            if (is_array($schema)) {
                foreach ($schema as $key => $row) {
                    if (!is_array($row)) {
                        $row = ['name' => (string)$key, 'value' => $row];
                    } elseif (!isset($row['name'])) {
                        $row['name'] = (string)$key;
                    }
                    $rows[] = $row;
                }
            }
        }

        $valuesFile = $base . 'config.values.php';
        if (is_file($valuesFile)) {
            $values = include $valuesFile;
            if (is_array($values)) {
                foreach ($rows as &$row) {
                    $field = (string)$row['name'];
                    if (array_key_exists($field, $values)) {
                        $row['value'] = $values[$field];
                    }
                }
                unset($row);
            }
        }

        $GLOBALS['__addon_config_cache'][$name] = $rows;
        return $rows;
    }
}

if (!function_exists('addon_config')) {
    function addon_config(string $name): array
    {
        $config = [];
        foreach (addon_full_config($name) as $row) {
            if (is_array($row) && isset($row['name'])) {
                $config[(string)$row['name']] = $row['value'] ?? null;
            }
        }
        return $config;
    }
}

if (!function_exists('addon_save_full_config')) {
    function addon_save_full_config(string $name, array $config): bool
    {
        $name = strtolower(trim($name));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return false;
        }
        $values = [];
        foreach ($config as $row) {
            if (is_array($row) && isset($row['name'])) {
                $values[(string)$row['name']] = $row['value'] ?? null;
            }
        }
        $file = root_path('addons') . $name . DIRECTORY_SEPARATOR . 'config.values.php';
        $content = "<?php\n\nreturn " . var_export($values, true) . ";\n";
        if (@file_put_contents($file, $content) === false) {
            return false;
        }
        unset($GLOBALS['__addon_config_cache'][$name]);
        return true;
    }
}

if (!function_exists('addon_save_info')) {
    function addon_save_info(string $name, array $info): bool
    {
        $name = strtolower(trim($name));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return false;
        }
        $name = $info['name'] ?? $name;
        $lines = [];
        foreach ($info as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$key) || is_array($value) || is_object($value)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $value = str_replace(["\r", "\n"], ' ', (string)$value);
            if ($value !== '' && preg_match('/[=\[\]";#]/', $value)) {
                $value = '"' . addcslashes($value, '"\\') . '"';
            }
            $lines[] = $key . ' = ' . $value;
        }
        $file = root_path('addons') . $name . DIRECTORY_SEPARATOR . 'info.ini';
        if (@file_put_contents($file, implode("\n", $lines) . "\n") === false) {
            return false;
        }
        unset($GLOBALS['__addon_info_cache'][$name], $GLOBALS['__addon_list_cache']);
        return true;
    }
}

if (!function_exists('addon_class')) {
    function addon_class(string $name): string
    {
        $name = strtolower(trim($name));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return '';
        }
        $dir = root_path('addons') . $name . DIRECTORY_SEPARATOR;
        foreach ([ucfirst($name), $name, str_replace(' ', '', ucwords(str_replace('_', ' ', $name)))] as $short) {
            if (is_file($dir . $short . '.php')) {
                return 'addons\\' . $name . '\\' . $short;
            }
        }
        return 'addons\\' . $name . '\\' . ucfirst($name);
    }
}

if (!function_exists('addon_url')) {
    function addon_url(string $name, array $params = []): string
    {
        $path = trim($name, '/');
        $entry = defined('IN_FILE') ? rtrim(IN_FILE, '/') : '/index.php';
        $url = $entry . '/addons/' . $path;
        if (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        return $url;
    }
}

if (!function_exists('addon_hook')) {
    /**
     * 分发插件钩子。$params 传入副本，插件对参数的修改通过返回值带回。
     * @return mixed
     */
    function addon_hook(string $hook, $params = null)
    {
        return AddonManager::dispatch($hook, $params);
    }
}
