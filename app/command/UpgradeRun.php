<?php
namespace app\command;

use app\common\util\Upgrades;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class UpgradeRun extends Command
{
    protected function configure()
    {
        $this->setName('upgrade:run')
            ->setDescription('Apply app/data/upgrade/*.sql in name order, recording each in mac_db_version')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只列出将要执行的脚本与语句数，不碰数据库');
    }

    protected function execute(Input $input, Output $output)
    {
        $dry = (bool) $input->getOption('dry-run');
        $dir = str_replace('\\', '/', root_path()) . 'app/data/upgrade';

        if (!is_dir(Upgrades::dir())) {
            $output->writeln('<error>目录不存在: ' . $dir . '</error>');
            return 1;
        }

        try {
            $res = Upgrades::run($dry);
        } catch (\Throwable $e) {
            $output->writeln('<error>数据库不可用: ' . preg_replace('/\s+/', ' ', $e->getMessage()) . '</error>');
            return 1;
        }

        foreach ($res['log'] as [$state, $name, $msg]) {
            $tag = match ($state) {
                'applied', 'would-run' => '<info>' . str_pad($state, 9) . '</info>',
                'fail' => '<error>' . str_pad($state, 9) . '</error>',
                default => str_pad($state, 9),
            };
            $output->writeln($tag . ' ' . $name . ($msg === '' ? '' : '  ' . $msg));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '%s %d，跳过 %d，失败 %d%s',
            $dry ? '待执行' : '已执行',
            $dry ? count(array_filter($res['log'], static fn($l) => $l[0] === 'would-run')) : $res['applied'],
            $res['skipped'],
            $res['failed'],
            $res['failed'] > 0 ? '；该脚本未登记，修好后重跑即可（已登记的不会重复执行）' : ''
        ));

        return $res['failed'] > 0 ? 1 : 0;
    }
}
