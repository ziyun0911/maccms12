<?php
namespace app\install\controller;

use app\common\controller\All;
use think\facade\Db;
use think\facade\Lang;
use think\Request;

/**
 * 苹果CMS v12 —— 安装控制器（ThinkPHP 8）
 */
class Index extends All
{

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        // 仅安装脚本可进入
        if (!defined('BIND_MODULE') || BIND_MODULE != 'install') {
            header('HTTP/1.1 403 Forbidden');
            exit();
        }
        parent::__construct();
    }

    public function index($step = 0)
    {
        $langs = array_values(mac_lang_registry());
        $this->assign('langs', $langs);

        if (in_array(session('lang'), mac_lang_codes(), true)) {
            $lang = session('lang');
            Lang::setLangSet($lang);
            Lang::load(APP_PATH . 'lang/' . $lang . '.php', $lang);
        }

        switch ($step) {
            case 2:
                session('install_error', false);
                return self::step2();
                break;
            case 3:
                if (session('install_error')) {
                    return $this->error(lang('install/environment_failed'));
                }
                return self::step3();
                break;
            case 4:
                if (session('install_error')) {
                    return $this->error(lang('install/environment_failed'));
                }
                return self::step4();
                break;
            case 5:
                if (session('install_error')) {
                    return $this->error(lang('install/init_err'));
                }
                return self::step5();
                break;
            default:
                $param = input();
                $codes = mac_lang_codes();

                if (empty($param['lang']) || !in_array($param['lang'], $codes, true)) {
                    $param['lang'] = $codes[0] ?? '';
                }
                $lang = $param['lang'];
                Lang::setLangSet($lang);
                Lang::load(APP_PATH . 'lang/' . $lang . '.php', $lang);
                session('lang', $param['lang']);
                $this->assign('lang', $param['lang']);

                session('install_error', false);
                return $this->fetch('install@/index/index');
                break;
        }
    }

    /**
     * 第二步：环境检测
     * @return mixed
     */
    private function step2()
    {
        $data = [];
        $data['env'] = self::checkNnv();
        $data['dir'] = self::checkDir();
        $data['func'] = self::checkFunc();
        $this->assign('data', $data);
        return $this->fetch('install@index/step2');
    }

    /**
     * 第三步：初始化配置
     * @return mixed
     */
    private function step3()
    {
        $install_dir = $_SERVER["SCRIPT_NAME"];
        $install_dir = mac_substring($install_dir, strripos($install_dir, "/") + 1);
        $this->assign('install_dir', $install_dir);
        return $this->fetch('install@index/step3');
    }

    /**
     * 第四步：执行安装（连接检测 / 生成数据库配置）
     * @return mixed
     */
    private function step4()
    {
        if ($this->request->isPost()) {
            if (!is_writable(ROOT_PATH . 'config/database.php')) {
                return $this->error('[config/database.php]' . lang('install/write_read_err'));
            }
            $data = input('post.');
            $data['type'] = 'mysql';
            $rule = [
                'hostname|' . lang('install/server_address') => 'require',
                'hostport|' . lang('install/database_port') => 'require|number',
                'database|' . lang('install/database_name') => 'require',
                'username|' . lang('install/database_username') => 'require',
                'prefix|' . lang('install/database_pre') => 'require|regex:^[a-z0-9]{1,20}[_]{1}',
                'cover|' . lang('install/overwrite_database') => 'require|in:0,1',
            ];
            $validate = $this->validate($data, $rule);
            if (true !== $validate) {
                return $this->error($validate);
            }
            $cover = $data['cover'];
            unset($data['cover']);

            // TP8 的 Db::connect() 只接受连接名（字符串），不接受配置数组，故此处用 PDO 直连
            try {
                $pdo = self::pdoConnect($data);
            } catch (\Exception $e) {
                return $this->error(lang('install/database_connect_err') . '：' . $e->getMessage());
            }

            // 生成数据库配置文件（TP8 connections.mysql 结构）
            $res = self::mkDatabase($data);
            if ($res !== true) {
                return $res;
            }

            // 把刚写入的 config/database.php 热更新进框架 Config（TP8 think\Db 从框架 Config 读库配置，
            // 不能用 Db::setConfig(数组) 覆盖，否则会破坏其 Config 对象）
            $written = include ROOT_PATH . 'config/database.php';
            if (is_array($written)) {
                \think\facade\Config::set($written, 'database');
            }

            // 不覆盖检测是否已存在数据库
            if (!$cover) {
                $database = $data['database'];
                $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.schemata WHERE schema_name = ? LIMIT 1');
                $stmt->execute([$database]);
                if (!empty($stmt->fetch())) {
                    return $this->success(lang('install/database_name_haved'), '');
                }
            }
            // 创建数据库
            $dbQuoted = '`' . str_replace('`', '``', $data['database']) . '`';
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS {$dbQuoted} DEFAULT CHARACTER SET utf8mb4");
            } catch (\Exception $e) {
                return $this->error(lang('install/database_connect_err') . '：' . $e->getMessage());
            }

            return $this->success(lang('install/database_connect_ok'), '');
        } else {
            return $this->error(lang('install/access_denied'));
        }
    }

    /**
     * 用原始 PDO 建立数据库连接（TP8 Db::connect 只接受连接名）
     * @param array $cfg 含 hostname/hostport/username/password/database 的连接配置
     * @return \PDO
     */
    private function pdoConnect(array $cfg)
    {
        $host = isset($cfg['hostname']) ? $cfg['hostname'] : '127.0.0.1';
        $port = isset($cfg['hostport']) ? $cfg['hostport'] : '3306';
        $user = isset($cfg['username']) ? $cfg['username'] : '';
        $pass = isset($cfg['password']) ? $cfg['password'] : '';
        $db   = isset($cfg['database']) ? $cfg['database'] : '';
        // 连接测试与建库阶段目标库可能尚不存在，故不拼 dbname，仅连服务器
        $dsn  = "mysql:host={$host};port={$port};charset=utf8mb4";
        return new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE   => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT   => 5,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * 第五步：数据库安装
     * @return mixed
     */
    private function step5()
    {
        $account = input('post.account');
        $password = input('post.password');
        $install_dir = input('post.install_dir');
        $initdata = input('post.initdata');

        $config = self::readDbConfig();
        if (empty($config['database']) || empty($config['username'])) {
            return $this->error(lang('install/please_test_connect'));
        }
        // 把 config/database.php 热更新进框架 Config（think\Db 从框架 Config 读库配置），
        // 使后续 Db::execute / model 使用刚安装的库
        $dbFile = include ROOT_PATH . 'config/database.php';
        if (is_array($dbFile)) {
            \think\facade\Config::set($dbFile, 'database');
        }
        if (empty($account) || empty($password)) {
            return $this->error(lang('install/please_input_admin_name_pass'));
        }

        $rule = [
            'account|' . lang('install/admin_name') => 'require|alphaNum',
            'password|' . lang('install/admin_pass') => 'require|length:6,20',
        ];
        $validate = $this->validate(['account' => $account, 'password' => $password], $rule);
        if (true !== $validate) {
            return $this->error($validate);
        }
        if (empty($install_dir)) {
            $install_dir = '/';
        }
        $config_new = config('maccms');
        $config_new['app']['cache_flag'] = substr(md5(time()), 0, 10);
        $config_new['app']['lang'] = session('lang');

        $config_new['api']['vod']['status'] = 0;
        $config_new['api']['art']['status'] = 0;

        $config_new['interface']['status'] = 0;
        $config_new['interface']['pass'] = mac_get_rndstr(16);
        if (!isset($config_new['app']['api_jwt_secret']) || strlen(trim((string)$config_new['app']['api_jwt_secret'])) < 32) {
            $config_new['app']['api_jwt_secret'] = mac_get_rndstr(32);
        }
        $config_new['site']['install_dir'] = $install_dir;

        // AI 搜索反滥用配置：全新安装写入默认值
        if (!isset($config_new['ai_search']) || !is_array($config_new['ai_search'])) {
            $config_new['ai_search'] = [];
        }
        $aiAbuseFill = [
            'require_login'          => '1',
            'anon_captcha_after'     => '10',
            'daily_budget'           => '500',
            'llm_call_cap'           => '3',
            'circuit_fail_threshold' => '8',
            'circuit_hold_seconds'   => '1800',
        ];
        foreach ($aiAbuseFill as $ai_k => $ai_v) {
            if (!isset($config_new['ai_search'][$ai_k])) {
                $config_new['ai_search'][$ai_k] = $ai_v;
            }
        }
        if (!isset($config_new['trusted_proxies'])) {
            $config_new['trusted_proxies'] = '';
        }

        // 行为分析配置
        if (!isset($config_new['analytics']) || !is_array($config_new['analytics'])) {
            $config_new['analytics'] = [];
        }
        if (!isset($config_new['analytics']['server_track'])) {
            $config_new['analytics']['server_track'] = '0';
        }
        if (!isset($config_new['analytics']['track_region'])) {
            $config_new['analytics']['track_region'] = '0';
        }
        if (!isset($config_new['analytics']['quality_fresh_halflife_days'])) {
            $config_new['analytics']['quality_fresh_halflife_days'] = '30';
        }
        if (!isset($config_new['analytics']['quality_weights'])) {
            $config_new['analytics']['quality_weights'] = [
                'behavior' => '0.35',
                'interact' => '0.30',
                'complete' => '0.20',
                'fresh'    => '0.15',
            ];
        }
        if (!isset($config_new['analytics']['profile_window_days'])) {
            $config_new['analytics']['profile_window_days'] = '30';
        }
        if (!isset($config_new['analytics']['quality_rank_enabled'])) {
            $config_new['analytics']['quality_rank_enabled'] = '0';
        }

        // AI 内容标注配置
        if (!isset($config_new['ai_content']) || !is_array($config_new['ai_content'])) {
            $config_new['ai_content'] = [];
        }
        $aiContentFill = [
            'enabled'           => '0',
            'use_ai_search_credentials' => '0',
            'provider'          => 'openai',
            'model'             => 'gpt-4o-mini',
            'api_base'          => '',
            'api_key'           => '',
            'timeout'           => '30',
            'max_tokens'        => '800',
            'batch_size'        => '20',
            'auto_adopt_empty'  => '0',
        ];
        foreach ($aiContentFill as $ai_content_k => $ai_content_v) {
            if (!isset($config_new['ai_content'][$ai_content_k])) {
                $config_new['ai_content'][$ai_content_k] = $ai_content_v;
            }
        }

        // 监控与告警配置
        if (!isset($config_new['monitor']) || !is_array($config_new['monitor'])) {
            $config_new['monitor'] = [];
        }
        $monitorFill = [
            'enabled'              => '1',
            'req_metrics_enabled'  => '1',
            'req_sample_rate'      => '100',
            'slow_ms'              => '1000',
            'allow_shell'          => '0',
            'disk_mounts'          => '',
            'retain_min_days'      => '3',
            'retain_hour_days'     => '90',
            'heartbeat_url'        => '',
            'notify_user_ids'      => '',
            'alert_emails'         => '',
            'notify_budget_hour'   => '20',
            'notify_max_per_run'   => '5',
            'notify_time_budget_ms' => '8000',
            'webhook_allow_private' => '0',
            'access_track_enabled' => '0',
            'access_cc_threshold'  => '120',
            'access_err4_threshold' => '20',
            'access_track_max_ip'  => '300',
            'retain_access_days'   => '30',
            'ban_whitelist'        => '',
            'user_access_log_enabled'   => '1',
            'user_access_throttle_min'  => '10',
            'retain_user_access_days'   => '90',
            'user_access_anonymize_days' => '30',
            'webhook_url'          => '',
            'webhook_secret'       => '',
            'telegram_token'       => '',
            'telegram_chat_id'     => '',
            'dingtalk_token'       => '',
            'dingtalk_secret'      => '',
            'wecom_key'            => '',
            'serverchan_key'       => '',
        ];
        foreach ($monitorFill as $m_k => $m_v) {
            if (!isset($config_new['monitor'][$m_k])) {
                $config_new['monitor'][$m_k] = $m_v;
            }
        }
        if (!isset($config_new['monitor']['cron_token'])
            || strlen(trim((string)$config_new['monitor']['cron_token'])) < 16) {
            $config_new['monitor']['cron_token'] = mac_get_rndstr(32);
        }

        // PWA Web Push 配置
        if (!isset($config_new['push']) || !is_array($config_new['push'])) {
            $config_new['push'] = [];
        }
        $pushFill = [
            'enable'        => '0',
            'vapid_public'  => '',
            'vapid_private' => '',
            'vapid_subject' => '',
        ];
        foreach ($pushFill as $p_k => $p_v) {
            if (!isset($config_new['push'][$p_k])) {
                $config_new['push'][$p_k] = $p_v;
            }
        }

        // 更新程序配置文件
        $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
        if ($res === false) {
            return $this->error(lang('write_err_config'));
        }

        // 定时任务幂等注入（自包含，不依赖 common.php）
        {
            $_timming_file = APP_PATH . 'extra/timming.php';
            $_timming = config('timming');
            if (!is_array($_timming)) {
                $_timming = [];
            }
            $_timming_defaults = [
                'analytics_hour' => [
                    'id'      => 'analytics_hour', 'status'  => '1', 'name'    => 'analytics_hour',
                    'des'     => '运营统计小时聚合', 'file'    => 'analytics', 'param'   => 'mode=hour',
                    'weeks'   => '1,2,3,4,5,6,0', 'hours'   => '00,01,02,03,04,05,06,07,08,09,10,11,12,13,14,15,16,17,18,19,20,21,22,23', 'runtime' => 0,
                ],
                'analytics_day' => [
                    'id'      => 'analytics_day', 'status'  => '1', 'name'    => 'analytics_day',
                    'des'     => '运营统计日聚合', 'file'    => 'analytics', 'param'   => 'mode=day',
                    'weeks'   => '1,2,3,4,5,6,0', 'hours'   => '01', 'runtime' => 0,
                ],
                'notify_vip_expire' => [
                    'id'      => 'notify_vip_expire', 'status'  => '0', 'name'    => 'notify_vip_expire',
                    'des'     => 'VIP到期提醒通知', 'file'    => 'notify', 'param'   => 'days=3',
                    'weeks'   => '1,2,3,4,5,6,0', 'hours'   => '00,06,12,18', 'runtime' => 0,
                ],
                'vod_publish' => [
                    'id'      => 'vod_publish', 'status'  => '1', 'name'    => 'vod_publish',
                    'des'     => '视频定时上架', 'file'    => 'vodpublish', 'param'   => 'limit=200',
                    'weeks'   => '1,2,3,4,5,6,0', 'hours'   => '00,01,02,03,04,05,06,07,08,09,10,11,12,13,14,15,16,17,18,19,20,21,22,23', 'runtime' => 0,
                ],
                'content_ai_annotate' => [
                    'id'      => 'content_ai_annotate', 'status'  => '0', 'name'    => 'content_ai_annotate',
                    'des'     => 'AI内容标注批量生成', 'file'    => 'annotate', 'param'   => 'mid=1&limit=50',
                    'weeks'   => '1,2,3,4,5,6,0', 'hours'   => '02', 'runtime' => 0,
                ],
                'content_quality' => [
                    'id'      => 'content_quality', 'status'  => '0', 'name'    => 'content_quality',
                    'des'     => '内容质量分批量计算', 'file'    => 'content_quality', 'param'   => 'mid=1&limit=200&days=30',
                    'weeks'   => '1,2,3,4,5,6,0', 'hours'   => '03', 'runtime' => 0,
                ],
                'content_quality_art' => [
                    'id'      => 'content_quality_art', 'status'  => '0', 'name'    => 'content_quality_art',
                    'des'     => '内容质量分批量计算-文章', 'file'    => 'content_quality', 'param'   => 'mid=2&limit=200&days=30',
                    'weeks'   => '1,2,3,4,5,6,0', 'hours'   => '04', 'runtime' => 0,
                ],
                'user_profile' => [
                    'id'      => 'user_profile', 'status'  => '0', 'name'    => 'user_profile',
                    'des'     => '用户画像批量计算', 'file'    => 'user_profile', 'param'   => 'limit=200&days=30',
                    'weeks'   => '1,2,3,4,5,6,0', 'hours'   => '05', 'runtime' => 0,
                ],
                'user_access_purge' => [
                    'id'      => 'user_access_purge', 'status'  => '1', 'name'    => 'user_access_purge',
                    'des'     => '用户访问风控日志清理/匿名化', 'file'    => 'user_access_purge', 'param'   => 'max=5000',
                    'weeks'   => '1,2,3,4,5,6,0', 'hours'   => '00,01,02,03,04,05,06,07,08,09,10,11,12,13,14,15,16,17,18,19,20,21,22,23', 'runtime' => 0,
                ],
                'push_broadcast' => [
                    'id'       => 'push_broadcast', 'status'   => '1', 'name'     => 'push_broadcast',
                    'des'      => 'Web Push广播队列派发', 'file'     => 'pushbroadcast', 'param'    => 'batch=100&max=500',
                    'weeks'    => '1,2,3,4,5,6,0', 'hours'    => '00,01,02,03,04,05,06,07,08,09,10,11,12,13,14,15,16,17,18,19,20,21,22,23', 'interval' => 60, 'runtime'  => 0,
                ],
            ];
            $_timming_changed = false;
            foreach ($_timming_defaults as $_k => $_task) {
                if (!isset($_timming[$_k])) {
                    $_timming[$_k] = $_task;
                    $_timming_changed = true;
                }
            }
            if ($_timming_changed) {
                @chmod($_timming_file, 0644);
                file_put_contents($_timming_file, "<?php\nreturn " . var_export($_timming, true) . ';');
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($_timming_file, true);
                }
            }
            unset($_timming_file, $_timming, $_timming_defaults, $_timming_changed, $_k, $_task);
        }

        // 导入系统初始数据库结构
        $sql_file = APP_PATH . 'install/sql/install.sql';
        if (file_exists($sql_file)) {
            $sql = file_get_contents($sql_file);
            $sql_list = mac_parse_sql($sql, 0, ['mac_' => $config['prefix']]);
            if ($sql_list) {
                $sql_list = array_filter($sql_list);
                foreach ($sql_list as $v) {
                    try {
                        Db::execute($v);
                    } catch (\Exception $e) {
                        return $this->error(lang('install/sql_err') . $e);
                    }
                }
            }
        }
        // 初始化数据
        if ($initdata == '1') {
            $sql_file = APP_PATH . 'install/sql/initdata.sql';
            if (file_exists($sql_file)) {
                $sql = file_get_contents($sql_file);
                $sql_list = mac_parse_sql($sql, 0, ['mac_' => $config['prefix']]);
                if ($sql_list) {
                    $sql_list = array_filter($sql_list);
                    foreach ($sql_list as $v) {
                        try {
                            Db::execute($v);
                        } catch (\Exception $e) {
                            return $this->error(lang('install/init_data_err') . $e);
                        }
                    }
                }
            }
        }

        // 注册管理员账号
        $data = [
            'admin_name' => $account,
            'admin_pwd' => $password,
            'admin_status' => 1,
        ];
        $res = (new \app\common\model\Admin())->saveData($data);
        if (!$res['code'] > 1) {
            return $this->error(lang('install/admin_name_err') . '：' . $res['msg']);
        }
        file_put_contents(APP_PATH . 'data/install/install.lock', date('Y-m-d H:i:s'));

        // 获取站点根目录
        $root_dir = request()->baseFile();
        $root_dir = preg_replace(['/install.php$/'], [''], $root_dir);
        return $this->success(lang('install/is_ok'), $root_dir . 'admin.php');
    }

    /**
     * 环境检测
     * @return array
     */
    private function checkNnv()
    {
        $items = [
            'os'  => [lang('install/os'), lang('install/not_limited'), 'Windows/Unix', PHP_OS, 'ok'],
            'php' => [lang('install/php'), '8.0', '8.0及以上', PHP_VERSION, 'ok'],
        ];
        if (version_compare($items['php'][3], $items['php'][1], '<')) {
            $items['php'][4] = 'no';
            session('install_error', true);
        }
        return $items;
    }

    /**
     * 目录权限检查
     * @return array
     */
    private function checkDir()
    {
        $items = [
            ['file', ROOT_PATH . 'config/database.php', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['file', ROOT_PATH . 'config/app.php', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', APP_PATH . 'extra', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', APP_PATH . 'data/backup', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', APP_PATH . 'data/update', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', ROOT_PATH . 'runtime', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
            ['dir', ROOT_PATH . 'upload', lang('install/read_and_write'), lang('install/read_and_write'), 'ok'],
        ];
        foreach ($items as &$v) {
            if ($v[0] == 'dir') {
                if (!is_writable($v[1])) {
                    if (is_dir($v[1])) {
                        $v[3] = lang('install/not_writable');
                        $v[4] = 'no';
                    } else {
                        $v[3] = lang('install/not_found');
                        $v[4] = 'no';
                    }
                    session('install_error', true);
                }
            } else {
                if (!is_writable($v[1])) {
                    $v[3] = lang('install/not_writable');
                    $v[4] = 'no';
                    session('install_error', true);
                }
            }
        }
        return $items;
    }

    /**
     * 函数及扩展检查
     * @return array
     */
    private function checkFunc()
    {
        $items = [
            ['pdo', lang('install/support'), 'yes', lang('install/class')],
            ['pdo_mysql', lang('install/support'), 'yes', lang('install/model')],
            ['zip', lang('install/support'), 'yes', lang('install/model')],
            ['fileinfo', lang('install/support'), 'yes', lang('install/model')],
            ['curl', lang('install/support'), 'yes', lang('install/model')],
            ['xml', lang('install/support'), 'yes', lang('install/function')],
            ['file_get_contents', lang('install/support'), 'yes', lang('install/function')],
            ['mb_strlen', lang('install/support'), 'yes', lang('install/function')],
        ];

        foreach ($items as &$v) {
            if (('类' == $v[3] && !class_exists($v[0]))
                || (lang('install/model') == $v[3] && !extension_loaded($v[0]))
                || (lang('install/function') == $v[3] && !function_exists($v[0]))) {
                $v[1] = lang('install/not_support');
                $v[2] = 'no';
                session('install_error', true);
            }
        }

        return $items;
    }

    /**
     * 读取已生成的数据库配置（config/database.php）
     * @return array
     */
    private function readDbConfig()
    {
        $file = ROOT_PATH . 'config/database.php';
        if (!is_file($file)) {
            return [];
        }
        $cfg = include $file;
        if (!is_array($cfg)) {
            return [];
        }
        if (isset($cfg['connections']['mysql']) && is_array($cfg['connections']['mysql'])) {
            return $cfg['connections']['mysql'];
        }
        return $cfg;
    }

    /**
     * 生成数据库配置文件（TP8 connections.mysql 结构）
     * @param array $data
     * @return mixed true 成功，否则返回错误响应
     */
    private function mkDatabase(array $data)
    {
        $code = <<<INFO
<?php
// +----------------------------------------------------------------------
// | 苹果CMS v12 —— 数据库配置（由安装程序生成）
// +----------------------------------------------------------------------
return [
    // 默认使用的数据库连接配置
    'default'         => 'mysql',
    // 数据库连接配置信息
    'connections'     => [
        'mysql' => [
            // 数据库类型
            'type'            => 'mysql',
            // 服务器地址
            'hostname'        => '{$data['hostname']}',
            // 数据库名
            'database'        => '{$data['database']}',
            // 用户名
            'username'        => '{$data['username']}',
            // 密码
            'password'        => '{$data['password']}',
            // 端口
            'hostport'        => '{$data['hostport']}',
            // 连接dsn
            'dsn'             => '',
            // 数据库连接参数
            'params'          => [],
            // 数据库编码默认采用utf8mb4
            'charset'         => 'utf8mb4',
            // 数据库表前缀
            'prefix'          => '{$data['prefix']}',
            // 数据库调试模式
            'debug'           => false,
            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => 0,
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => false,
            // 读写分离后 主服务器数量
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',
            // 是否严格检查字段是否存在
            'fields_strict'   => false,
            // 数据集返回类型
            'resultset_type'  => 'array',
            // 自动写入时间戳字段
            'auto_timestamp'  => false,
            // 时间字段取出后的默认时间格式
            'datetime_format' => 'Y-m-d H:i:s',
            // 是否需要进行SQL性能分析
            'sql_explain'     => false,
            // Builder类
            'builder'         => '',
            // Query类
            'query'           => '\\\\app\\\\common\\\\db\\\\Query',
        ],
    ],
];
INFO;
        file_put_contents(ROOT_PATH . 'config/database.php', $code);
        // 判断写入是否成功
        $config = include ROOT_PATH . 'config/database.php';
        $mysql = isset($config['connections']['mysql']) ? $config['connections']['mysql'] : [];
        if (empty($mysql['database']) || $mysql['database'] != $data['database']) {
            return $this->error('[config/database.php]' . lang('write_err_database'));
        }
        return true;
    }
}
