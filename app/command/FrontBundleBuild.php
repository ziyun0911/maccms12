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
 *
 * JS 合包（js/ 段）同一条规矩：清单顺序即执行顺序，源文件留在 static/js/ 不动。
 * 只收「vendor 在前、本页封装在后、两者相邻、后面才跟内联 <script>」的成对引用：
 *   - admin-monitor.bundle.js  = uplot.min.js + monitor-charts.js
 *   - admin-welcome.bundle.js  = apexcharts.min.js + hs-apexcharts-helpers.js
 * analytics/index.html 只引 apexcharts.min.js、不引 helpers，所以它继续用源文件，
 * 不因合包多下 12 KB。
 *
 * 不能进包的 static/js：
 *   - layui.js 靠 document.currentScript 反推 lay/modules 基路径，改名合包后动态模块全 404；
 *   - playerconfig.js、tj.js 由后台 @fwrite 回写（Base.php/System.php），addons.js 是插件
 *     bootstrap.js 的合并目标，analytics_beacon.js 由 All.php 在 </body> 前注入并配套一个
 *     window.MAC_ANA_SS 内联全局 —— 都是生成物或注入物，合进包就断了回写路径。
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

    /** JS 合包，源文件相对 static/js/，顺序即执行顺序 */
    private const BUNDLES_JS = [
        'admin-monitor.bundle.js' => [
            'uplot.min.js',
            'monitor-charts.js',
        ],
        'admin-welcome.bundle.js' => [
            'apexcharts.min.js',
            'hs-apexcharts-helpers.js',
        ],
    ];

    protected function configure()
    {
        $this->setName('front:bundle')
            ->setDescription('Build front-end CSS/JS bundles from template and static sources')
            ->addOption('check', null, Option::VALUE_NONE, '只校验现有 bundle 是否与源文件一致，不写盘');
    }

    protected function execute(Input $input, Output $output)
    {
        $cssDir = rtrim(str_replace('\\', '/', root_path('template/default/asset/css')), '/') . '/';
        $jsDir = rtrim(str_replace('\\', '/', root_path('static/js')), '/') . '/';
        $check = (bool) $input->getOption('check');
        $drift = 0;

        $jobs = [
            ['dir' => $cssDir, 'kind' => 'css', 'bundles' => self::BUNDLES],
            ['dir' => $jsDir, 'kind' => 'js', 'bundles' => self::BUNDLES_JS],
        ];

        foreach ($jobs as $job) {
            foreach ($job['bundles'] as $bundle => $parts) {
                foreach ($parts as $p) {
                    if (!is_file($job['dir'] . $p)) {
                        $output->writeln('<error>missing source: ' . $p . '</error>');
                        return 1;
                    }
                }

                $code = $this->buildBundle($job['dir'], $bundle, $parts, $job['kind']);
                $target = $job['dir'] . $bundle;

                if ($check) {
                    $same = is_file($target) && file_get_contents($target) === $code;
                    $output->writeln(($same ? '<info>ok  </info>' : '<error>drift</error>') . ' ' . $bundle);
                    if (!$same) {
                        $drift++;
                    }
                    continue;
                }

                file_put_contents($target, $code);
                $src = 0;
                foreach ($parts as $p) {
                    $src += filesize($job['dir'] . $p);
                }
                $output->writeln(sprintf(
                    '<info>built</info> %-26s %7d B  <- %d 分区（源 %7d B，省 %d 次请求）',
                    $bundle,
                    strlen($code),
                    count($parts),
                    $src,
                    count($parts) - 1
                ));
            }
        }

        if ($check && $drift > 0) {
            $output->writeln('<error>' . $drift . " 个 bundle 与源文件不一致，跑 php think front:bundle 重建</error>");
            return 1;
        }

        return 0;
    }

    /**
     * @param string $dir   源文件目录绝对路径，尾斜杠
     * @param array  $parts 分区源文件名
     * @param string $kind  css|js，决定注释风格与 @charset 处理
     */
    private function buildBundle(string $dir, string $bundle, array $parts, string $kind): string
    {
        $isCss = $kind === 'css';
        $hasCharset = false;
        if ($isCss) {
            foreach ($parts as $p) {
                if (strpos(file_get_contents($dir . $p), '@charset') === 0) {
                    $hasCharset = true;
                    break;
                }
            }
        }

        $body = '';
        foreach ($parts as $i => $p) {
            $code = file_get_contents($dir . $p);
            $code = preg_replace('/^\xEF\xBB\xBF/', '', $code);
            if ($isCss) {
                $code = preg_replace('/^@charset\s+["\'][^"\']+["\']\s*;?\s*/i', '', $code);
                $marker = '/* ── ';
            } else {
                // 源文件可能以无分号的表达式结尾，也可能整份是 // 注释收尾；
                // 补一个空语句当分隔闸，后面再接下一个分区
                $code = rtrim($code) . ';';
                $marker = '// ── ';
            }
            $pad = str_repeat('─', max(2, 56 - strlen($p)));
            $body .= $isCss
                ? "\n" . $marker . $this->partition($i, $p, $pad) . " */\n" . rtrim($code) . "\n"
                : "\n" . $marker . $this->partition($i, $p, $pad) . "\n" . rtrim($code) . "\n";
        }

        $banner = $isCss ? <<<TXT
/*
 * %s — 前台样式合包（派生产物，请勿手改）
 * 构建：php think front:bundle
 * 分区顺序不可调整：后出现的分区覆盖前面的同名规则，
 * 与合并前「模板 <link> 依次加载」的顺序保持一致。
 * 源文件仍在 asset/css/ 下，改样式改源文件再重建。
 */
TXT
            : <<<TXT
/*
 * %s — 后台脚本合包（派生产物，请勿手改）
 * 构建：php think front:bundle
 * 分区顺序不可调整：vendor 必须排在它的项目封装之前，
 * 与合并前「模板 <script> 依次加载」的执行顺序保持一致。
 * 源文件仍在 static/js/ 下，改脚本改源文件再重建。
 */
TXT;

        return ($hasCharset ? '@charset "utf-8";' : '')
            . sprintf($banner, $bundle)
            . $body;
    }

    /** 分区标题行，CSS 走块注释、JS 走行注释，两者共用同一段文字 */
    private function partition(int $i, string $p, string $pad): string
    {
        return str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 原 ' . $p . ' ' . $pad;
    }
}
