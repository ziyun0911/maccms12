<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 前台 CSS 合包。
 *
 * 源文件留在 template/default/asset/css/ 不动，bundle 是派生产物：改样式改源文件，
 * 然后重跑 `php think front:bundle`。清单顺序即层叠顺序，后出现的分区覆盖前面的同名规则，
 * 与合并前「模板里 <link> 依次加载」的顺序保持一致。
 *
 * 分区划分依据是属性级冲突核查（不是文件名归类）：
 *   - black.css 与 public-head-main.css 有 13 处同选择器同属性不同值，其中 7 处不带 !important，
 *     谁赢取决于加载顺序；black.css 又靠 disabled 属性整表切换，所以它必须继续单独成表，
 *     夹在 head 段与 tail 段中间原位不动。
 *   - tail 的五个文件两两之间共享选择器为 0，先后无所谓，因此前台区和用户中心共用同一份 tail；
 *     它整体排在 black.css 之后、管理员注入块之前，与合并前各 <link> 的位置关系一致。
 *   - auth-ui.css 不入包：它在 public/include.html 里排在 {if theme.head.text} 管理员自定义
 *     head 注入块之后，是唯一有意压过注入样式的表；卷进 tail 会让站点自注入的 CSS 反过来赢它。
 *   - head.css / foot.css 由 public/head.html、public/foot.html 在 <body> 内加载，
 *     排在页面级 CSS 之后，合进 <head> 段会反转层叠，故不参与本合包。
 *   - 用户中心没有 head bundle：user-head-early.css 自己就带 @font-face 与 icon-* 字形类，
 *     而 iconfont-alicdn.css 本来只在前台 include 里加载，把它并进用户区会给用户页
 *     多出一套 .iconfont 规则。用户区头段已是单张表，直接引源文件即可。
 */
class FrontBundleBuild extends Command
{
    /** bundle 文件名 => 分区源文件（相对 asset/css/，顺序即层叠顺序） */
    private const BUNDLES = [
        'front-head.bundle.css' => [
            'iconfont-alicdn.css',
            'public-head-early.css',
        ],
        'front-tail.bundle.css' => [
            'notify-bell.css',
            'user-profile-card.css',
            'public-head-main.css',
            'mac-pop-sheets.css',
            'social-unread.css',
        ],
    ];

    protected function configure()
    {
        $this->setName('front:bundle')
            ->setDescription('Build front-end CSS bundles from template/default/asset/css sources')
            ->addOption('check', null, Option::VALUE_NONE, '只校验现有 bundle 是否与源文件一致，不写盘');
    }

    protected function execute(Input $input, Output $output)
    {
        $dir = rtrim(str_replace('\\', '/', root_path('template/default/asset/css')), '/') . '/';
        $check = (bool) $input->getOption('check');
        $drift = 0;

        foreach (self::BUNDLES as $bundle => $parts) {
            foreach ($parts as $p) {
                if (!is_file($dir . $p)) {
                    $output->writeln('<error>missing source: ' . $p . '</error>');
                    return 1;
                }
            }

            $css = $this->buildBundle($dir, $bundle, $parts);
            $target = $dir . $bundle;

            if ($check) {
                $same = is_file($target) && file_get_contents($target) === $css;
                $output->writeln(($same ? '<info>ok  </info>' : '<error>drift</error>') . ' ' . $bundle);
                if (!$same) {
                    $drift++;
                }
                continue;
            }

            file_put_contents($target, $css);
            $src = 0;
            foreach ($parts as $p) {
                $src += filesize($dir . $p);
            }
            $output->writeln(sprintf(
                '<info>built</info> %-26s %7d B  <- %d 分区（源 %7d B，省 %d 次请求）',
                $bundle,
                strlen($css),
                count($parts),
                $src,
                count($parts) - 1
            ));
        }

        if ($check && $drift > 0) {
            $output->writeln('<error>' . $drift . " 个 bundle 与源文件不一致，跑 php think front:bundle 重建</error>");
            return 1;
        }

        return 0;
    }

    /**
     * @param string $dir   asset/css/ 绝对路径，尾斜杠
     * @param array  $parts 分区源文件名
     */
    private function buildBundle(string $dir, string $bundle, array $parts): string
    {
        $hasCharset = false;
        foreach ($parts as $p) {
            if (strpos(file_get_contents($dir . $p), '@charset') === 0) {
                $hasCharset = true;
                break;
            }
        }

        $body = '';
        foreach ($parts as $i => $p) {
            $css = file_get_contents($dir . $p);
            $css = preg_replace('/^\xEF\xBB\xBF/', '', $css);
            $css = preg_replace('/^@charset\s+["\'][^"\']+["\']\s*;?\s*/i', '', $css);
            $body .= "\n/* ── " . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)
                . ' 原 ' . $p . ' ' . str_repeat('─', max(2, 56 - strlen($p))) . " */\n"
                . rtrim($css) . "\n";
        }

        $banner = <<<'TXT'
/*
 * %s — 前台样式合包（派生产物，请勿手改）
 * 构建：php think front:bundle
 * 分区顺序不可调整：后出现的分区覆盖前面的同名规则，
 * 与合并前「模板 <link> 依次加载」的顺序保持一致。
 * 源文件仍在 asset/css/ 下，改样式改源文件再重建。
 */
TXT;

        return ($hasCharset ? '@charset "utf-8";' : '')
            . sprintf($banner, $bundle)
            . $body;
    }
}
