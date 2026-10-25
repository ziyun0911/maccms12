<?php
// +----------------------------------------------------------------------
// | 苹果CMS v12 —— 前台入口 (ThinkPHP 8)
// | 苹果CMS v12：基于最新 ThinkPHP 8 框架重写的 maccms
// +----------------------------------------------------------------------
namespace think;

header('Content-Type:text/html;charset=utf-8');

// 检测 PHP 环境（TP8 要求 PHP >= 8.0.2）
if (version_compare(PHP_VERSION, '8.0.2', '<')) {
    die('PHP版本需要 >= 8.0.2，请升级【PHP version requires >= 8.0.2，please upgrade】');
}

// ---- 基础常量 ----
define('MAC_VERSION', '2026.1025.0109');
define('DS', DIRECTORY_SEPARATOR);
define('EXT', '.php');
define('ROOT_PATH', __DIR__ . '/');
define('APP_PATH', __DIR__ . '/app/');
define('THINK_PATH', __DIR__ . '/vendor/topthink/framework/src/');
define('VENDOR_PATH', __DIR__ . '/vendor/');
define('MAC_START_TIME', microtime(true));

// ---- 入口与模块 ----
define('ENTRANCE', 'index');
define('BIND_MODULE', 'index');
define('MAC_COMM', __DIR__ . '/app/common/common/');
define('MAC_HOME_COMM', __DIR__ . '/app/index/common/');
define('MAC_ADMIN_COMM', __DIR__ . '/app/admin/common/');

// ---- 资源限制 ----
ini_set('max_execution_time', '0');
ini_set('memory_limit', '-1');

// ---- 入口文件 ----
$in_file = rtrim($_SERVER['SCRIPT_NAME'], '/');
if (substr($in_file, strlen($in_file) - 4) !== '.php') {
    $in_file = substr($in_file, 0, strpos($in_file, '.php')) . '.php';
}
define('IN_FILE', $in_file);

// ---- 安装锁检查 ----
if (!is_file(APP_PATH . 'data/install/install.lock')) {
    header("Location: ./install.php");
    exit;
}

// ---- GBK 路径兼容 ----
if (!empty($_SERVER['PATH_INFO']) && !mb_check_encoding($_SERVER['PATH_INFO'], 'utf-8')) {
    $_SERVER['PATH_INFO'] = mb_convert_encoding($_SERVER['PATH_INFO'], 'UTF-8', 'GBK');
}

// ---- 载入自动加载 ----
require __DIR__ . '/vendor/autoload.php';

// ---- 应用初始化 ----
$app = new App();
$app->initialize();

// ---- 绑定应用（多应用模式：index.php -> index 应用）----
$app->http->name('index');

// ---- 独立入口：剥离入口文件名得到 PATHINFO ----
// 例：/index.php                     -> ''
//     /index.php/vod/type/id/1.html  -> vod/type/id/1.html
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptName  = rtrim($_SERVER['SCRIPT_NAME'], '/');
if ($scriptName && strpos($requestPath, $scriptName) === 0) {
    $pathinfo = ltrim(substr($requestPath, strlen($scriptName)), '/');
} else {
    // 伪静态重写（/vod/type → index.php）：取请求路径自身为 pathinfo
    $pathinfo = ltrim($requestPath, '/');
}
$_SERVER['PATH_INFO'] = $pathinfo;
// 用 var_pathinfo（'s'）兼容参数固化 pathinfo：兼容 PHP 内置服务器（cli-server 被识别为 cli）
$_GET['s'] = $pathinfo;

// ---- 加载 app/extra/*.php 配置 ----
\mac_load_extra();

// ---- 执行 HTTP 应用并响应 ----
$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);
