<?php
namespace app\command;

use app\common\util\BlocklistRules;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class Doctor extends Command
{
    private const REQUIRED_EXT = ['pdo_mysql', 'curl', 'mbstring', 'xml'];
    private const OPTIONAL_EXT = ['gd', 'zip', 'openssl', 'zlib'];
    private const PHP_MIN = '8.0.2';
    private const ON_DEMAND_TABLES = ['tmpart', 'tmpmanga', 'tmpwebsite', 'vod_repeat'];

    protected function configure()
    {
        $this->setName('doctor')
            ->setDescription('Check the runtime, files, database, version gate, derived assets and security toggles')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出，供监控脚本消费');
    }

    protected function execute(Input $input, Output $output)
    {
        if (!defined('APP_PATH')) {
            define('APP_PATH', $this->root() . 'app/');
        }
        \mac_load_extra();

        $sections = [
            '运行环境' => $this->env(),
            '目录与文件' => $this->filesystem(),
            '数据库' => $this->database(),
            '版本与闸门' => $this->version(),
            '派生产物' => $this->artifacts(),
            '安全体检' => $this->security(),
        ];

        $fail = 0;
        $warn = 0;
        $total = 0;
        foreach ($sections as $rows) {
            foreach ($rows as $row) {
                $total++;
                if ($row['level'] === 'fail') {
                    $fail++;
                } elseif ($row['level'] === 'warn') {
                    $warn++;
                }
            }
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'sections' => $sections,
                'summary'  => ['total' => $total, 'fail' => $fail, 'warn' => $warn],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return $fail > 0 ? 1 : 0;
        }

        $output->writeln('maccms12 自检  PHP ' . PHP_VERSION . '  ' . rtrim($this->root(), '/'));
        foreach ($sections as $title => $rows) {
            $output->writeln('');
            $output->writeln('<comment>[' . $title . ']</comment>');
            foreach ($rows as $row) {
                $output->writeln('  ' . $this->tag($row['level']) . ' '
                    . $row['name'] . str_repeat(' ', max(2, 27 - mb_strwidth($row['name'], 'UTF-8')))
                    . $row['value']
                    . ($row['hint'] === '' ? '' : '  ← ' . $row['hint']));
            }
        }
        $output->writeln('');
        $output->writeln($fail > 0
            ? '<error>汇总</error> ' . $total . ' 项：' . $fail . ' 项必须处理，' . $warn . ' 项建议处理'
            : '<info>汇总</info> ' . $total . ' 项：全部通过，' . $warn . ' 项建议处理');

        return $fail > 0 ? 1 : 0;
    }

    private function tag(string $level): string
    {
        return $level === 'ok'
            ? '<info>ok  </info>'
            : '<error>' . ($level === 'fail' ? 'FAIL' : 'WARN') . '</error>';
    }

    private function row(string $level, string $name, string $value, string $hint = ''): array
    {
        return ['level' => $level, 'name' => $name, 'value' => $value, 'hint' => $hint];
    }

    private function root(): string
    {
        return rtrim(str_replace('\\', '/', root_path()), '/') . '/';
    }

    private function env(): array
    {
        $rows = [];
        $rows[] = version_compare(PHP_VERSION, self::PHP_MIN, '>=')
            ? $this->row('ok', 'php', PHP_VERSION, '要求 >= ' . self::PHP_MIN)
            : $this->row('fail', 'php', PHP_VERSION, '要求 >= ' . self::PHP_MIN);

        foreach (self::REQUIRED_EXT as $e) {
            $rows[] = extension_loaded($e)
                ? $this->row('ok', '扩展 ' . $e, (string) $this->extVersion($e))
                : $this->row('fail', '扩展 ' . $e, '未加载', '核心功能依赖');
        }
        foreach (self::OPTIONAL_EXT as $e) {
            $rows[] = extension_loaded($e)
                ? $this->row('ok', '扩展 ' . $e, (string) $this->extVersion($e))
                : $this->row('warn', '扩展 ' . $e, '未加载', $this->extUse($e));
        }

        if (extension_loaded('gd')) {
            $info = gd_info();
            $rows[] = empty($info['FreeType Support'])
                ? $this->row('warn', 'gd 的 FreeType', '不支持', '验证码与文字海报需要')
                : $this->row('ok', 'gd 的 FreeType', '支持');
        }

        $upload = $this->bytes((string) ini_get('upload_max_filesize'));
        $post = $this->bytes((string) ini_get('post_max_size'));
        $rows[] = $post < $upload
            ? $this->row('warn', 'upload / post 上限', ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'), 'post_max_size 小于 upload_max_filesize，大图必然被截')
            : $this->row('ok', 'upload / post 上限', ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size'));
        $rows[] = $this->row('ok', 'memory_limit', (string) ini_get('memory_limit'));
        $rows[] = $this->row('ok', 'max_execution_time', (string) ini_get('max_execution_time'), '0 = 不限制');
        $rows[] = extension_loaded('opcache')
            ? $this->row('ok', 'opcache', (string) ini_get('opcache.enable'))
            : $this->row('warn', 'opcache', '未加载', '生产环境建议开启');

        return $rows;
    }

    private function extVersion(string $e)
    {
        $v = phpversion($e);
        return $v === false ? '' : $v;
    }

    private function extUse(string $e): string
    {
        $map = [
            'gd' => '封面处理、验证码、水印',
            'zip' => '插件包与在线升级解包',
            'openssl' => '审计日志字段加密',
            'zlib' => '资源包 gzip 与备份压缩',
        ];
        return $map[$e] ?? '部分功能受限';
    }

    private function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1' || $v === '0') {
            return PHP_INT_MAX;
        }
        $unit = strtoupper(substr($v, -1));
        $num = (float) $v;
        $mult = ['K' => 1024, 'M' => 1048576, 'G' => 1073741824];
        return isset($mult[$unit]) ? (int) ($num * $mult[$unit]) : (int) $num;
    }

    private function filesystem(): array
    {
        $root = $this->root();
        $targets = [
            ['app/extra', 'fail', '后台保存配置写这里'],
            ['app/data/backup', 'fail', '数据备份'],
            ['app/data/update', 'fail', '在线升级解压与暂存'],
            ['runtime', 'fail', '日志、缓存、模板编译'],
            ['upload', 'fail', '附件与图片'],
            ['config', 'warn', '仅安装与改库配置时需要写'],
            ['static/css', 'warn', 'php think front:bundle 写后台样式包'],
            ['static/js', 'warn', 'php think front:bundle 写后台脚本包'],
            ['template/default/asset/css', 'warn', 'php think front:bundle 写前台样式包'],
        ];

        $rows = [];
        foreach ($targets as [$path, $level, $use]) {
            $file = $root . $path;
            if (!is_dir($file)) {
                $rows[] = $this->row($level, $path, '目录不存在', $use);
                continue;
            }
            $rows[] = is_writable($file)
                ? $this->row('ok', $path, '可写')
                : $this->row($level, $path, '不可写', $use);
        }

        foreach (['index.php', 'admin.php', 'api.php', 'img.php'] as $f) {
            $rows[] = is_file($root . $f)
                ? $this->row('ok', $f, '存在')
                : $this->row('fail', $f, '缺失', '入口文件被删，对应端不可访问');
        }

        return $rows;
    }

    private function database(): array
    {
        $rows = [];
        $prefix = (string) config('database.connections.mysql.prefix', 'mac_');

        try {
            $ver = Db::query('SELECT VERSION() AS v');
            $rows[] = $this->row('ok', '连接', (string) ($ver[0]['v'] ?? 'unknown'), '前缀 ' . $prefix);
        } catch (\Throwable $e) {
            $rows[] = $this->row('fail', '连接', '失败', $this->shortErr($e->getMessage()));
            return $rows;
        }

        $sqlFile = $this->root() . 'app/install/sql/install.sql';
        if (!is_file($sqlFile)) {
            $rows[] = $this->row('fail', 'install.sql', '缺失', '无法核对表清单');
            return $rows;
        }

        preg_match_all('/CREATE TABLE[^`]*`([^`]+)`/i', (string) file_get_contents($sqlFile), $m);
        $want = [];
        foreach (array_unique($m[1]) as $t) {
            $want[] = $t;
        }
        sort($want);

        $have = [];
        foreach (Db::query('SHOW TABLES') as $r) {
            $v = array_values($r);
            $have[] = (string) $v[0];
        }
        sort($have);

        $wantLive = array_map(static fn($t) => str_replace('mac_', $prefix, $t), $want);
        $onDemand = array_map(static fn($t) => $prefix . $t, self::ON_DEMAND_TABLES);
        $missing = array_diff($wantLive, $have);
        $present = array_values(array_intersect($onDemand, $have));
        $extra = array_diff($have, $wantLive, $onDemand);

        $rows[] = empty($missing)
            ? $this->row('ok', '表齐全', count($wantLive) . '/' . count($have))
            : $this->row('fail', '表缺失', count($missing) . ' 张', implode(', ', array_slice($missing, 0, 8)));
        $rows[] = $this->row('ok', '按需表', count($present) . '/' . count($onDemand) . ' 已在库', '后台查重临时表，用到才建');
        $rows[] = empty($extra)
            ? $this->row('ok', '表外多余', '0 张')
            : $this->row('warn', '表外多余', count($extra) . ' 张', implode(', ', array_slice($extra, 0, 8)));

        return $rows;
    }

    private function version(): array
    {
        $root = $this->root();
        $rows = [];

        $versionFile = $root . 'app/extra/version.php';
        $version = is_file($versionFile) ? (array) include $versionFile : [];
        $rows[] = isset($version['code']) && $version['code'] !== ''
            ? $this->row('ok', 'version.code', (string) $version['code'])
            : $this->row('fail', 'version.code', '缺失', 'app/extra/version.php 不存在或没写 code');

        $updateFile = $root . 'app/admin/controller/Update.php';
        $expected = (string) ($version['update_hash'] ?? '');
        if (!is_file($updateFile)) {
            $rows[] = $this->row('fail', 'update_hash 闸门', 'Update.php 缺失');
        } elseif ($expected === '') {
            $rows[] = $this->row('warn', 'update_hash 闸门', '未设置', '后台升级页失去核心文件校验');
        } else {
            $actual = (string) md5_file($updateFile);
            $rows[] = $actual === $expected
                ? $this->row('ok', 'update_hash 闸门', substr($actual, 0, 8) . '… 匹配')
                : $this->row('fail', 'update_hash 闸门', '不匹配', '后台任意页都会白页；改了 Update.php 要重算哈希写回 version.php');
        }

        $lock = $root . 'app/data/install/install.lock';
        $installed = $this->tableExists('vod');
        if (is_file($lock)) {
            $rows[] = $this->row('ok', 'install.lock', '已放置', (string) @file_get_contents($lock));
        } elseif ($installed) {
            $rows[] = $this->row('fail', 'install.lock', '缺失', '库已建好但没有锁，install.php 可被重跑清库');
        } else {
            $rows[] = $this->row('warn', 'install.lock', '缺失', '站点尚未安装');
        }

        if ($installed) {
            try {
                $rows[] = $this->row('ok', 'vod 表行数', (string) Db::name('vod')->count());
            } catch (\Throwable $e) {
                $rows[] = $this->row('warn', 'vod 表行数', '读取失败', $this->shortErr($e->getMessage()));
            }
        }

        foreach ($this->upgrades() as $r) {
            $rows[] = $r;
        }

        return $rows;
    }

    private function upgrades(): array
    {
        $rows = [];
        $hasTable = \app\common\util\Upgrades::exists();
        $rows[] = $this->row('ok', 'db_version 记录表', $hasTable ? '已在库' : '未建',
            $hasTable ? '' : '第一次 php think upgrade:run 时自动建');

        $pending = 0;
        $abnormal = 0;
        foreach (\app\common\util\Upgrades::plan() as $f) {
            switch ($f['status']) {
                case 'pending':
                    $pending++;
                    $rows[] = $this->row('warn', $f['name'], '待执行', $f['stmt'] . ' 条语句；跑 php think upgrade:run');
                    break;
                case 'modified':
                    $abnormal++;
                    $rows[] = $this->row('warn', $f['name'], '已执行过但内容变了', '不要改历史脚本，新增一个文件');
                    break;
                case 'bad-name':
                    $abnormal++;
                    $rows[] = $this->row('fail', $f['name'], '文件名不合规', '要 YYYYMMDD_NN_小写下划线.sql');
                    break;
                default:
                    $rows[] = $this->row('ok', $f['name'], '已执行', $f['stmt'] . ' 条 @ ' . date('Y-m-d H:i', $f['applied']));
            }
        }

        $orphan = 0;
        foreach (array_keys(\app\common\util\Upgrades::recorded()) as $name) {
            if (!in_array($name, \app\common\util\Upgrades::files(), true)) {
                $orphan++;
            }
        }
        if ($orphan > 0) {
            $rows[] = $this->row('ok', '记录里的孤儿项', $orphan . ' 条', '脚本文件已删，记录保留作历史');
        }

        $summary = '无待执行';
        $level = 'ok';
        if ($pending > 0) {
            $summary = $pending . ' 项待执行';
            $level = 'warn';
        }
        if ($abnormal > 0) {
            $summary .= '，' . $abnormal . ' 项异常';
            $level = $level === 'ok' ? 'warn' : $level;
        }
        foreach ($rows as $r) {
            if ($r['level'] === 'fail') {
                $level = 'fail';
                break;
            }
        }
        array_unshift($rows, $this->row($level, '增量脚本', $summary));

        return $rows;
    }

    private function tableExists(string $name): bool
    {
        $prefix = (string) config('database.connections.mysql.prefix', 'mac_');
        try {
            foreach (Db::query('SHOW TABLES') as $r) {
                $v = array_values($r);
                if ((string) $v[0] === $prefix . $name) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    private function artifacts(): array
    {
        $rows = [];

        foreach (FrontBundleBuild::inspect() as $row) {
            if (!empty($row['missing'])) {
                $rows[] = $this->row('fail', $row['bundle'], '缺源文件', implode(', ', $row['missing']));
                continue;
            }
            $rows[] = $row['ok']
                ? $this->row('ok', $row['bundle'], $this->humanSize($row['size']), count($row['parts']) . ' 分区')
                : $this->row('fail', $row['bundle'], $row['exists'] ? '与源文件不一致' : '未生成', '跑 php think front:bundle');
        }

        foreach (BlocklistRules::check(false) as $entry) {
            $bad = [];
            foreach ($entry['regions'] as $name => $state) {
                if ($state !== 'ok') {
                    $bad[] = $name . '=' . $state;
                }
            }
            $rows[] = $entry['status'] === 'ok'
                ? $this->row('ok', $entry['path'], '拦截区块一致')
                : $this->row('fail', $entry['path'], $entry['status'], implode(', ', $bad) . '；跑 php think blocklist:sync');
        }

        return $rows;
    }

    private function security(): array
    {
        try {
            $report = \app\common\util\SecurityHealthCheck::run(config('maccms'));
        } catch (\Throwable $e) {
            return [$this->row('warn', '安全体检', '不可用', $this->shortErr($e->getMessage()))];
        }

        $rows = [];
        foreach ($report['items'] as $item) {
            $level = $item['level'] === 'red' ? 'fail' : ($item['level'] === 'green' ? 'ok' : 'warn');
            $detail = (string) ($item['detail'] ?? '');
            $rows[] = $this->row($level, (string) $item['id'], $detail === '' ? $item['level'] : $detail);
        }

        return $rows;
    }

    private function shortErr(string $msg): string
    {
        $msg = preg_replace('/\s+/', ' ', $msg);
        return mb_strlen($msg) > 120 ? mb_substr($msg, 0, 120) . '…' : $msg;
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
    }
}
