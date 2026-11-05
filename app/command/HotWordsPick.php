<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;

class HotWordsPick extends Command
{
    private const SUFFIX = '/\s+(HD|BD|TC|TS|4K|1080P|720P|HD中字|正片|完结|预告片|抢先版|更新至\S+).*$/iu';
    private const UNSUIT = '/[()（）:：!！?？,，.。\/|、\[\]{}\-—\d]/u';

    protected function configure()
    {
        $this->setName('hotwords:pick')
            ->setDescription('按点播热度榜抽取热门搜索词，可写回 app/extra/maccms.php 的 app.search_hot')
            ->addOption('total', null, Option::VALUE_OPTIONAL, '最终词数，15-20 为宜', '18')
            ->addOption('keep', null, Option::VALUE_OPTIONAL, '保留现有列表的前几个词', '5')
            ->addOption('scan', null, Option::VALUE_OPTIONAL, '扫描热度榜行数', '600')
            ->addOption('apply', null, Option::VALUE_NONE, '写回 app/extra/maccms.php');
    }

    protected function execute(Input $input, Output $output)
    {
        if (!defined('APP_PATH')) {
            define('APP_PATH', rtrim(str_replace('\\', '/', root_path()), '/') . '/app/');
        }
        \mac_load_extra();

        $total = max(1, intval($input->getOption('total')));
        $keep = max(0, intval($input->getOption('keep')));
        $scan = max(50, intval($input->getOption('scan')));

        $cur = $this->words((string)Config::get('maccms.app.search_hot', ''));
        $kept = array_slice($cur, 0, min($keep, count($cur)));
        $pool = $this->pool($scan, $kept);

        $added = array_slice($pool, 0, max(0, $total - count($kept)));
        $all = array_merge($kept, $added);

        $output->writeln('kept(' . count($kept) . '): ' . implode(',', $kept));
        $output->writeln('pool=' . count($pool) . ' added=' . count($added) . ' total=' . count($all));
        $output->writeln(implode(',', $all));

        if (!$input->getOption('apply')) {
            $output->writeln('未写入，加 --apply 生效');
            return 0;
        }

        if (count($all) < $total) {
            $output->writeln('WARN: 候选池只凑到 ' . count($all) . ' 个词');
        }

        $file = APP_PATH . 'extra/maccms.php';
        $config = is_file($file) ? include $file : null;
        if (!is_array($config) || !isset($config['app']) || !is_array($config['app'])) {
            $output->writeln('FAIL: 读不到 ' . $file . ' 的 app 段');
            return 1;
        }

        $config['app']['search_hot'] = implode(',', $all);
        $res = \mac_arr2file($file, $config);
        if ($res === false) {
            $output->writeln('FAIL: 写入失败，检查 app/extra 是否可写');
            return 1;
        }

        $output->writeln('written: ' . $file . ' (' . $res . ' bytes)');
        return 0;
    }

    private function words(string $csv): array
    {
        $out = [];
        foreach (preg_split('/[,，\s]+/u', $csv) as $w) {
            $w = trim($w);
            if ($w !== '' && !in_array($w, $out, true)) {
                $out[] = $w;
            }
        }
        return $out;
    }

    private function pool(int $scan, array $skip): array
    {
        $rows = Db::name('vod')
            ->field('vod_name,vod_hits')
            ->where('vod_status', 1)
            ->where('vod_recycle_time', 0)
            ->order('vod_hits DESC')
            ->limit($scan)
            ->select()
            ->toArray();

        $cand = [];
        foreach ($rows as $r) {
            $name = trim((string)$r['vod_name']);
            $name = preg_replace(self::SUFFIX, '', $name);
            $len = mb_strlen($name, 'UTF-8');
            if ($name === '' || $len < 2 || $len > 10) {
                continue;
            }
            if (preg_match(self::UNSUIT, $name) || preg_match('/^\W+$/u', $name)) {
                continue;
            }
            if (in_array($name, $skip, true) || isset($cand[$name])) {
                continue;
            }
            $cand[$name] = true;
        }

        $keys = array_keys($cand);
        shuffle($keys);
        return $keys;
    }
}
