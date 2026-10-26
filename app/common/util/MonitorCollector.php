<?php
namespace app\common\util;

use think\facade\Db;

class MonitorCollector
{
    public static function collect()
    {
        $metrics = [];
        $skipped = [];

        if (!self::runtimeWritable()) {
            $skipped['runtime'] = 'runtime_path()/monitor is not writable by the web process — '
                . 'request metrics and abnormal-access tracking are silently disabled. '
                . 'Check ownership (a CLI script run as root may have created it).';
        }

        self::collectCpu($metrics, $skipped);
        self::collectLoad($metrics, $skipped);
        self::collectMemory($metrics, $skipped);
        self::collectDisk($metrics, $skipped);
        self::collectUptime($metrics, $skipped);
        self::collectFpm($metrics, $skipped);
        self::collectOpcache($metrics, $skipped);
        self::collectDb($metrics, $skipped);

        return ['metrics' => $metrics, 'skipped' => $skipped];
    }

    public static function capabilities()
    {
        $caps = [];
        $caps['proc_stat']   = @is_readable('/proc/stat');
        $caps['cgroup_v2']   = @is_readable('/sys/fs/cgroup/cpu.stat');
        $caps['cgroup_v1']   = @is_readable('/sys/fs/cgroup/cpuacct/cpuacct.usage');
        $caps['loadavg']     = function_exists('sys_getloadavg');
        $caps['meminfo']     = @is_readable('/proc/meminfo');
        $caps['uptime']      = @is_readable('/proc/uptime');
        $caps['fpm']         = function_exists('fpm_get_status');
        $caps['opcache']     = function_exists('opcache_get_status');
        $caps['disk']        = function_exists('disk_free_space');
        $caps['cpu_scope']   = self::cpuScope();
        $caps['runtime_writable'] = self::runtimeWritable();
        return $caps;
    }

    public static function runtimeWritable()
    {
        try {
            $dir = rtrim(runtime_path(), '/\\') . DIRECTORY_SEPARATOR . 'monitor';

            if (!is_dir($dir)) {
                return is_writable(rtrim(runtime_path(), '/\\'));
            }
            return is_writable($dir);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function cpuScope()
    {
        if (@is_readable('/sys/fs/cgroup/cpu.stat')) {
            return 'cgroup2';
        }
        if (@is_readable('/sys/fs/cgroup/cpuacct/cpuacct.usage')) {
            return 'cgroup1';
        }
        if (@is_readable('/proc/stat')) {
            return 'host';
        }
        return 'none';
    }

    private static function collectCpu(array &$metrics, array &$skipped)
    {
        try {
            $scope = self::cpuScope();
            if ($scope === 'none') {
                $skipped['sys.cpu'] = 'no /proc/stat and no cgroup cpu accounting';
                return;
            }

            $now = microtime(true);
            $cur = null;

            if ($scope === 'host') {
                $raw = @file_get_contents('/proc/stat');
                $cur = self::parseProcStat($raw);
                if ($cur === null) {
                    $skipped['sys.cpu'] = '/proc/stat unparsable';
                    return;
                }
                $cur['mode'] = 'jiffies';
            } else {
                $usec = self::readCgroupCpuUsec($scope);
                if ($usec === null) {
                    $skipped['sys.cpu'] = 'cgroup cpu usage unreadable';
                    return;
                }
                $cur = ['mode' => 'usec', 'usage_usec' => $usec, 'wall' => $now];
            }

            $prevRaw = MonitorState::getVal('cpu.prev', '');
            $prev = $prevRaw !== '' ? json_decode($prevRaw, true) : null;
            MonitorState::set('cpu.prev', intval($now), json_encode($cur));

            if (!is_array($prev) || !isset($prev['mode']) || $prev['mode'] !== $cur['mode']) {
                $skipped['sys.cpu'] = 'first sample, need one more interval';
                return;
            }

            $pct = self::cpuPctFromDelta($prev, $cur);
            if ($pct === null) {
                $skipped['sys.cpu'] = 'delta not positive (clock or counter reset)';
                return;
            }

            $metrics[] = ['k' => 'sys.cpu.pct', 't' => MonitorMetric::TYPE_GAUGE, 'v' => $pct];
            if ($scope === 'host' && self::looksContainerized()) {
                $skipped['sys.cpu.scope'] = 'host-level (containerized, no cgroup cpu.stat)';
            }
        } catch (\Throwable $e) {
            $skipped['sys.cpu'] = 'error: ' . $e->getMessage();
        }
    }

    private static function readCgroupCpuUsec($scope)
    {
        if ($scope === 'cgroup2') {
            $raw = @file_get_contents('/sys/fs/cgroup/cpu.stat');
            if ($raw === false) {
                return null;
            }
            if (preg_match('/^usage_usec\s+(\d+)/m', $raw, $m)) {
                return floatval($m[1]);
            }
            return null;
        }
        $raw = @file_get_contents('/sys/fs/cgroup/cpuacct/cpuacct.usage');
        if ($raw === false) {
            return null;
        }
        $ns = trim($raw);
        if (!preg_match('/^\d+$/', $ns)) {
            return null;
        }
        return floatval($ns) / 1000.0;
    }

    private static function looksContainerized()
    {
        return @is_file('/.dockerenv') || @is_dir('/sys/fs/cgroup/memory');
    }

    public static function parseProcStat($content)
    {
        if (!is_string($content) || $content === '') {
            return null;
        }
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') !== 0) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            array_shift($parts);
            if (count($parts) < 5) {
                return null;
            }
            $total = 0;
            foreach ($parts as $p) {
                if ($p === '' || !is_numeric($p)) {
                    continue;
                }
                $total += intval($p);
            }
            $idle = intval($parts[3]) + intval($parts[4]);
            if ($total <= 0) {
                return null;
            }
            return ['total' => $total, 'idle' => $idle];
        }
        return null;
    }

    public static function cpuPctFromDelta(array $prev, array $cur)
    {
        $mode = isset($cur['mode']) ? $cur['mode'] : 'jiffies';

        if ($mode === 'usec') {
            if (!isset($prev['usage_usec'], $prev['wall'], $cur['usage_usec'], $cur['wall'])) {
                return null;
            }
            $wallDelta = floatval($cur['wall']) - floatval($prev['wall']);
            $usageDelta = floatval($cur['usage_usec']) - floatval($prev['usage_usec']);
            if ($wallDelta <= 0 || $usageDelta < 0) {
                return null;
            }
            $ncpu = self::cpuCount();
            $pct = ($usageDelta / ($wallDelta * 1000000.0 * $ncpu)) * 100.0;
            return round(min(100.0, max(0.0, $pct)), 2);
        }

        if (!isset($prev['total'], $prev['idle'], $cur['total'], $cur['idle'])) {
            return null;
        }
        $totalDelta = intval($cur['total']) - intval($prev['total']);
        $idleDelta = intval($cur['idle']) - intval($prev['idle']);
        if ($totalDelta <= 0) {
            return null;
        }
        $pct = 100.0 * (1.0 - ($idleDelta / $totalDelta));
        return round(min(100.0, max(0.0, $pct)), 2);
    }

    private static function cpuCount()
    {
        $raw = @file_get_contents('/proc/cpuinfo');
        if (is_string($raw) && $raw !== '') {
            $n = preg_match_all('/^processor\s*:/m', $raw);
            if ($n > 0) {
                return $n;
            }
        }
        return 1;
    }

    private static function collectLoad(array &$metrics, array &$skipped)
    {
        try {
            if (!function_exists('sys_getloadavg')) {
                $skipped['sys.load'] = 'sys_getloadavg() unavailable';
                return;
            }
            $load = sys_getloadavg();
            if (!is_array($load) || count($load) < 3) {
                $skipped['sys.load'] = 'sys_getloadavg() returned unexpected value';
                return;
            }
            $metrics[] = ['k' => 'sys.load1', 't' => MonitorMetric::TYPE_GAUGE, 'v' => round(floatval($load[0]), 2)];
            $metrics[] = ['k' => 'sys.load5', 't' => MonitorMetric::TYPE_GAUGE, 'v' => round(floatval($load[1]), 2)];
            $metrics[] = ['k' => 'sys.load15', 't' => MonitorMetric::TYPE_GAUGE, 'v' => round(floatval($load[2]), 2)];
        } catch (\Throwable $e) {
            $skipped['sys.load'] = 'error: ' . $e->getMessage();
        }
    }

    public static function parseMemInfo($content)
    {
        if (!is_string($content) || $content === '') {
            return null;
        }
        $want = [
            'MemTotal'     => 'total_kb',
            'MemAvailable' => 'avail_kb',
            'MemFree'      => 'free_kb',
            'SwapTotal'    => 'swap_total_kb',
            'SwapFree'     => 'swap_free_kb',
        ];
        $out = [];
        foreach (explode("\n", $content) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            if (!isset($want[$name])) {
                continue;
            }
            if (preg_match('/(\d+)/', substr($line, $pos + 1), $m)) {
                $out[$want[$name]] = intval($m[1]);
            }
        }
        if (empty($out['total_kb'])) {
            return null;
        }
        if (!isset($out['avail_kb']) && isset($out['free_kb'])) {
            $out['avail_kb'] = $out['free_kb'];
        }
        if (!isset($out['avail_kb'])) {
            return null;
        }
        return $out;
    }

    private static function collectMemory(array &$metrics, array &$skipped)
    {
        try {
            $raw = @file_get_contents('/proc/meminfo');
            $mem = self::parseMemInfo($raw);
            if ($mem === null) {
                $skipped['sys.mem'] = '/proc/meminfo unreadable or unparsable';
                return;
            }
            $total = $mem['total_kb'];
            $avail = $mem['avail_kb'];
            $usedPct = $total > 0 ? (100.0 * ($total - $avail) / $total) : 0.0;

            $metrics[] = ['k' => 'sys.mem.used_pct', 't' => MonitorMetric::TYPE_GAUGE, 'v' => round($usedPct, 2)];
            $metrics[] = ['k' => 'sys.mem.avail_mb', 't' => MonitorMetric::TYPE_GAUGE, 'v' => round($avail / 1024.0, 2)];

            if (!empty($mem['swap_total_kb'])) {
                $st = $mem['swap_total_kb'];
                $sf = isset($mem['swap_free_kb']) ? $mem['swap_free_kb'] : $st;
                $swapPct = 100.0 * ($st - $sf) / $st;
                $metrics[] = ['k' => 'sys.swap.used_pct', 't' => MonitorMetric::TYPE_GAUGE, 'v' => round($swapPct, 2)];
            }
        } catch (\Throwable $e) {
            $skipped['sys.mem'] = 'error: ' . $e->getMessage();
        }
    }

    private static function collectDisk(array &$metrics, array &$skipped)
    {
        try {
            if (!function_exists('disk_free_space')) {
                $skipped['sys.disk'] = 'disk_free_space() unavailable';
                return;
            }
            foreach (self::diskMounts() as $mount) {
                $free = @disk_free_space($mount);
                $total = @disk_total_space($mount);
                if ($free === false || $total === false || $total <= 0) {
                    $skipped['sys.disk|' . $mount] = 'unreadable (open_basedir?)';
                    continue;
                }
                $usedPct = 100.0 * ($total - $free) / $total;
                $metrics[] = [
                    'k' => 'sys.disk.used_pct|' . $mount,
                    't' => MonitorMetric::TYPE_GAUGE,
                    'v' => round($usedPct, 2),
                ];
                $metrics[] = [
                    'k' => 'sys.disk.free_mb|' . $mount,
                    't' => MonitorMetric::TYPE_GAUGE,
                    'v' => round($free / 1048576.0, 2),
                ];
            }
        } catch (\Throwable $e) {
            $skipped['sys.disk'] = 'error: ' . $e->getMessage();
        }
    }

    private static function diskMounts()
    {
        $mounts = ['/'];
        $cfg = self::config();
        $extra = isset($cfg['disk_mounts']) ? trim((string)$cfg['disk_mounts']) : '';
        if ($extra !== '') {
            foreach (explode(',', $extra) as $one) {
                $one = trim($one);
                if ($one !== '' && strpos($one, '/') === 0 && @is_dir($one)) {
                    $mounts[] = $one;
                }
            }
        }
        return array_values(array_unique($mounts));
    }

    private static function collectUptime(array &$metrics, array &$skipped)
    {
        try {
            $raw = @file_get_contents('/proc/uptime');
            if ($raw === false || !preg_match('/^([\d.]+)/', trim($raw), $m)) {
                $skipped['sys.uptime'] = '/proc/uptime unreadable';
                return;
            }
            $metrics[] = ['k' => 'sys.uptime_sec', 't' => MonitorMetric::TYPE_GAUGE, 'v' => round(floatval($m[1]), 0)];
        } catch (\Throwable $e) {
            $skipped['sys.uptime'] = 'error: ' . $e->getMessage();
        }
    }

    private static function collectFpm(array &$metrics, array &$skipped)
    {
        try {
            if (!function_exists('fpm_get_status')) {
                $skipped['php.fpm'] = 'fpm_get_status() unavailable (not running under PHP-FPM)';
                return;
            }
            $st = fpm_get_status();
            if (!is_array($st)) {
                $skipped['php.fpm'] = 'fpm_get_status() returned false (pm.status_path not configured?)';
                return;
            }
            $map = [
                'active-processes'       => 'php.fpm.active',
                'idle-processes'         => 'php.fpm.idle',
                'listen-queue'           => 'php.fpm.queue',
                'max-children-reached'   => 'php.fpm.max_children_reached',
                'slow-requests'          => 'php.fpm.slow_req',
            ];
            foreach ($map as $src => $key) {
                if (!isset($st[$src])) {
                    continue;
                }
                $metrics[] = ['k' => $key, 't' => MonitorMetric::TYPE_GAUGE, 'v' => floatval($st[$src])];
            }
        } catch (\Throwable $e) {
            $skipped['php.fpm'] = 'error: ' . $e->getMessage();
        }
    }

    private static function collectOpcache(array &$metrics, array &$skipped)
    {
        try {
            if (!function_exists('opcache_get_status')) {
                $skipped['php.opcache'] = 'opcache_get_status() unavailable';
                return;
            }
            $st = @opcache_get_status(false);
            if (!is_array($st) || empty($st['opcache_enabled'])) {
                $skipped['php.opcache'] = 'opcache disabled or restricted (opcache.restrict_api?)';
                return;
            }
            if (isset($st['opcache_statistics']['opcache_hit_rate'])) {
                $metrics[] = [
                    'k' => 'php.opcache.hit_pct',
                    't' => MonitorMetric::TYPE_GAUGE,
                    'v' => round(floatval($st['opcache_statistics']['opcache_hit_rate']), 2),
                ];
            }
            if (isset($st['memory_usage']['used_memory'], $st['memory_usage']['free_memory'])) {
                $used = floatval($st['memory_usage']['used_memory']);
                $free = floatval($st['memory_usage']['free_memory']);
                $totalMem = $used + $free;
                if ($totalMem > 0) {
                    $metrics[] = [
                        'k' => 'php.opcache.mem_used_pct',
                        't' => MonitorMetric::TYPE_GAUGE,
                        'v' => round(100.0 * $used / $totalMem, 2),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $skipped['php.opcache'] = 'error: ' . $e->getMessage();
        }
    }

    private static function collectDb(array &$metrics, array &$skipped)
    {
        try {
            $rows = Db::query("SHOW GLOBAL STATUS WHERE `Variable_name` IN ('Threads_connected','Threads_running','Slow_queries','Questions','Uptime')");
            if (empty($rows)) {
                $skipped['db'] = 'SHOW GLOBAL STATUS returned nothing (insufficient privilege?)';
                return;
            }
            $st = [];
            foreach ($rows as $row) {
                $st[$row['Variable_name']] = floatval($row['Value']);
            }

            if (isset($st['Threads_connected'])) {
                $metrics[] = ['k' => 'db.threads_connected', 't' => MonitorMetric::TYPE_GAUGE, 'v' => $st['Threads_connected']];
            }
            if (isset($st['Threads_running'])) {
                $metrics[] = ['k' => 'db.threads_running', 't' => MonitorMetric::TYPE_GAUGE, 'v' => $st['Threads_running']];
            }

            $uptime = isset($st['Uptime']) ? $st['Uptime'] : 0.0;
            $prevRaw = MonitorState::getVal('db.prev', '');
            $prev = $prevRaw !== '' ? json_decode($prevRaw, true) : null;

            $cur = [
                'uptime'       => $uptime,
                'slow_queries' => isset($st['Slow_queries']) ? $st['Slow_queries'] : 0.0,
                'questions'    => isset($st['Questions']) ? $st['Questions'] : 0.0,
            ];
            MonitorState::set('db.prev', time(), json_encode($cur));

            $restarted = !is_array($prev)
                || !isset($prev['uptime'])
                || floatval($cur['uptime']) < floatval($prev['uptime']);

            foreach (['slow_queries' => 'db.slow_queries', 'questions' => 'db.questions'] as $f => $key) {
                if ($restarted) {
                    $metrics[] = ['k' => $key, 't' => MonitorMetric::TYPE_COUNTER, 'v' => 0];
                    continue;
                }
                $delta = floatval($cur[$f]) - floatval($prev[$f]);
                if ($delta < 0) {
                    $delta = 0;
                }
                $metrics[] = ['k' => $key, 't' => MonitorMetric::TYPE_COUNTER, 'v' => $delta];
            }

            $var = Db::query("SHOW GLOBAL VARIABLES WHERE `Variable_name` = 'slow_query_log'");
            $slowOn = 0;
            if (!empty($var) && isset($var[0]['Value'])) {
                $slowOn = (strtoupper((string)$var[0]['Value']) === 'ON') ? 1 : 0;
            }
            $metrics[] = ['k' => 'db.slow_log_on', 't' => MonitorMetric::TYPE_GAUGE, 'v' => $slowOn];
        } catch (\Throwable $e) {
            $skipped['db'] = 'error: ' . $e->getMessage();
        }
    }

    private static function config()
    {
        return isset($GLOBALS['config']['monitor']) && is_array($GLOBALS['config']['monitor'])
            ? $GLOBALS['config']['monitor'] : [];
    }
}
