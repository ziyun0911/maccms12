<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 前台与后台 CSS、后台 JS 合包。命令名沿用 front:bundle 没改，因为它已经写进了
 * 前台 bundle 的横幅注释里（改名的话每张派生产物都得重建一遍才自洽）。
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
 * 后台 CSS 合包（admin.bundle.css，源文件相对 static/css/）走同一套清单：六张表按
 * admin_style → tailwindcssOutput → admin_index_shell → admin_doc_shell → theme_config_ux
 * → update 排列，就是后台各页原先 <link> 的加载次序。
 *   - admin_doc_shell.css 的颜色全部写成 var(--shell-*)，而这些令牌定义在
 *     admin_index_shell.css 的 :root 里；两张表必须同包，doc_shell 不能拆回独立 <link>，
 *     否则说明文档/API 文档页的 var() 解析不到值。
 *   - admin_style.css 里有 url(../images/signin_bg.png)，是相对自身解析的，所以 bundle
 *     和源文件必须同在 static/css/ 下。
 *   - admin_publish_config.css 暂不入包：它由 system/config.html 在 bundle 之后单独加载，
 *     层叠位置在六张表之外，收进包要另做冲突核查。
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

    /** 后台 CSS 合包，源文件相对 static/css/，顺序即层叠顺序 */
    private const BUNDLES_ADMIN_CSS = [
        'admin.bundle.css' => [
            'admin_style.css',
            'tailwindcssOutput.css',
            'admin_index_shell.css',
            'admin_doc_shell.css',
            'theme_config_ux.css',
            'update.css',
        ],
    ];

    /** 后台包的横幅：额外交代 doc_shell 与 index_shell 的 :root 耦合，别改回两张表 */
    private const BANNER_ADMIN_CSS = <<<'TXT'
/*
 * %s — 后台样式合包（派生产物，请勿手改）
 * 构建：php think front:bundle
 * 分区顺序不可调整：后出现的分区覆盖前面的同名规则，
 * 与合并前「后台各页 <link> 依次加载」的顺序保持一致。
 * 源文件仍在 static/css/ 下，改样式改源文件再重建。
 * admin_doc_shell.css 的颜色用 var(--shell-*)，令牌定义在 admin_index_shell.css 的
 * :root 里，两张表必须留在同一个包内。
 */
TXT;

    protected function configure()
    {
        $this->setName('front:bundle')
            ->setDescription('Build front-end / admin CSS and admin JS bundles from their sources')
            ->addOption('check', null, Option::VALUE_NONE, '只校验现有 bundle 是否与源文件一致，不写盘');
    }

    protected function execute(Input $input, Output $output)
    {
        $cssDir = rtrim(str_replace('\\', '/', root_path('template/default/asset/css')), '/') . '/';
        $jsDir = rtrim(str_replace('\\', '/', root_path('static/js')), '/') . '/';
        $adminCssDir = rtrim(str_replace('\\', '/', root_path('static/css')), '/') . '/';
        $check = (bool) $input->getOption('check');
        $drift = 0;

        $jobs = [
            ['dir' => $cssDir, 'kind' => 'css', 'bundles' => self::BUNDLES],
            ['dir' => $jsDir, 'kind' => 'js', 'bundles' => self::BUNDLES_JS],
            ['dir' => $adminCssDir, 'kind' => 'css', 'bundles' => self::BUNDLES_ADMIN_CSS, 'banner' => self::BANNER_ADMIN_CSS],
        ];

        foreach ($jobs as $job) {
            foreach ($job['bundles'] as $bundle => $parts) {
                foreach ($parts as $p) {
                    if (!is_file($job['dir'] . $p)) {
                        $output->writeln('<error>missing source: ' . $p . '</error>');
                        return 1;
                    }
                }

                $code = $this->buildBundle($job['dir'], $bundle, $parts, $job['kind'], $job['banner'] ?? null);
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
     * @param ?string $banner 横幅模板（含 %s 占包名），不传按 kind 取默认
     */
    private function buildBundle(string $dir, string $bundle, array $parts, string $kind, ?string $bannerTpl = null): string
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

        $default = $isCss ? <<<TXT
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
        $banner = $bannerTpl ?? $default;

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
