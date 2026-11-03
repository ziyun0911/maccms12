<?php
namespace app\command;

use app\common\util\BlocklistRules;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class BlocklistSync extends Command
{
    protected function configure()
    {
        $this->setName('blocklist:sync')
            ->setDescription('Sync the internal-file blocklist regions in .htaccess / nginx / IIS / router.php')
            ->addOption('check', null, Option::VALUE_NONE, '只校验围栏区块是否与单一来源一致，不写盘');
    }

    protected function execute(Input $input, Output $output)
    {
        $check = (bool) $input->getOption('check');
        $bad = 0;

        foreach (BlocklistRules::check($check === false) as $entry) {
            $state = $entry['status'];
            if (!$check && $state === 'drift') {
                $state = $entry['changed'] ? 'synced' : 'ok';
            }
            $output->writeln(
                (in_array($state, ['ok', 'synced'], true) ? '<info>' . str_pad($state, 6) . '</info>' : '<error>' . str_pad($state, 6) . '</error>')
                . ' ' . $entry['path'] . $this->detail($entry, $check)
            );
            if (!in_array($state, ['ok', 'synced'], true)) {
                $bad++;
            }
        }

        if ($bad > 0) {
            $output->writeln('<error>' . $bad . ($check
                ? " 个文件的拦截区块与单一来源不一致，跑 php think blocklist:sync 重写"
                : " 个文件的拦截区块没写回，检查围栏标记是否成对") . '</error>');
            return 1;
        }

        return 0;
    }

    private function detail(array $entry, bool $check): string
    {
        $parts = [];
        foreach ($entry['regions'] as $name => $state) {
            if ($state === 'ok' || ($state === 'drift' && !$check)) {
                continue;
            }
            $parts[] = $name . '=' . $state;
        }

        return $parts ? ' [' . implode(', ', $parts) . ']' : '';
    }
}
