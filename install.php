<?php
namespace think;

header('Content-Type:text/html;charset=utf-8');

if (version_compare(PHP_VERSION, '8.0.2', '<')) {
    die('PHP版本需要 >= 8.0.2，请升级【PHP version requires >= 8.0.2，please upgrade】');
}

define('MAC_VERSION', '2026.1025.0109');
define('DS', DIRECTORY_SEPARATOR);
define('EXT', '.php');
define('ROOT_PATH', __DIR__ . '/');
define('APP_PATH', __DIR__ . '/app/');
define('THINK_PATH', __DIR__ . '/vendor/topthink/framework/src/');
define('VENDOR_PATH', __DIR__ . '/vendor/');
define('MAC_START_TIME', microtime(true));

define('ENTRANCE', 'install');
define('BIND_MODULE', 'install');
define('MAC_COMM', __DIR__ . '/app/common/common/');
define('MAC_HOME_COMM', __DIR__ . '/app/index/common/');
define('MAC_ADMIN_COMM', __DIR__ . '/app/admin/common/');

ini_set('max_execution_time', '0');
ini_set('memory_limit', '-1');

$in_file = rtrim($_SERVER['SCRIPT_NAME'], '/');
if (substr($in_file, strlen($in_file) - 4) !== '.php') {
    $in_file = substr($in_file, 0, strpos($in_file, '.php')) . '.php';
}
define('IN_FILE', $in_file);

if (is_file(APP_PATH . 'data/install/install.lock')) {
    echo '如需重新安装请删除【To re install, please remove】 >>> /app/data/install/install.lock';
    exit;
}

if (!is_writable('./runtime')) {
    echo '请开启[runtime]目录的读写权限【Please turn on the read and write permissions of the [runtime] folder】';
    exit;
}

if (!empty($_SERVER['PATH_INFO']) && !mb_check_encoding($_SERVER['PATH_INFO'], 'utf-8')) {
    $_SERVER['PATH_INFO'] = mb_convert_encoding($_SERVER['PATH_INFO'], 'UTF-8', 'GBK');
}

require __DIR__ . '/vendor/autoload.php';

$app = new App();
$app->initialize();

$app->http->name('install');

$requestPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptName  = rtrim($_SERVER['SCRIPT_NAME'], '/');
if ($scriptName && strpos($requestPath, $scriptName) === 0) {
    $pathinfo = ltrim(substr($requestPath, strlen($scriptName)), '/');
} else {
    $pathinfo = '';
}
$_SERVER['PATH_INFO'] = $pathinfo;
$_GET['s'] = $pathinfo;

\mac_load_extra();

$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);
