<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class FrontBundleBuild extends Command
{
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

    private const BUNDLES_JS = [
        'admin-info.bundle.js' => [
            'jquery.jscolor.js',
            'ai_seo.js',
        ],
        'admin-monitor.bundle.js' => [
            'uplot.min.js',
            'monitor-charts.js',
        ],
        'admin-welcome.bundle.js' => [
            'apexcharts.min.js',
            'hs-apexcharts-helpers.js',
        ],
    ];

    private const BUNDLES_ADMIN_CSS = [
        'admin.bundle.css' => [
            'admin_style.css',
            'tailwindcssOutput.css',
            'admin_index_shell.css',
            'admin_doc_shell.css',
            'theme_config_ux.css',
            'update.css',
            'admin_publish_config.css',
        ],
    ];

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

    public static function jobs(): array
    {
        $cssDir = rtrim(str_replace('\\', '/', root_path('template/default/asset/css')), '/') . '/';
        $jsDir = rtrim(str_replace('\\', '/', root_path('static/js')), '/') . '/';
        $adminCssDir = rtrim(str_replace('\\', '/', root_path('static/css')), '/') . '/';

        return [
            ['dir' => $cssDir, 'kind' => 'css', 'bundles' => self::BUNDLES],
            ['dir' => $jsDir, 'kind' => 'js', 'bundles' => self::BUNDLES_JS],
            ['dir' => $adminCssDir, 'kind' => 'css', 'bundles' => self::BUNDLES_ADMIN_CSS, 'banner' => self::BANNER_ADMIN_CSS],
        ];
    }

    public static function inspect(): array
    {
        $rows = [];
        foreach (self::jobs() as $job) {
            foreach ($job['bundles'] as $bundle => $parts) {
                $missing = [];
                $src = 0;
                foreach ($parts as $p) {
                    if (!is_file($job['dir'] . $p)) {
                        $missing[] = $p;
                        continue;
                    }
                    $src += filesize($job['dir'] . $p);
                }
                $target = $job['dir'] . $bundle;
                $code = $missing ? '' : self::buildBundle($job['dir'], $bundle, $parts, $job['kind'], $job['banner'] ?? null);
                $rows[] = [
                    'bundle'  => $bundle,
                    'path'    => $target,
                    'parts'   => $parts,
                    'missing' => $missing,
                    'code'    => $code,
                    'size'    => strlen($code),
                    'src'     => $src,
                    'exists'  => is_file($target),
                    'ok'      => !$missing && is_file($target) && file_get_contents($target) === $code,
                ];
            }
        }

        return $rows;
    }

    protected function execute(Input $input, Output $output)
    {
        $check = (bool) $input->getOption('check');
        $drift = 0;

        foreach (self::inspect() as $row) {
            if (!empty($row['missing'])) {
                $output->writeln('<error>missing source: ' . implode(', ', $row['missing']) . '</error>');
                return 1;
            }

            if ($check) {
                $output->writeln(($row['ok'] ? '<info>ok  </info>' : '<error>drift</error>') . ' ' . $row['bundle']);
                if (!$row['ok']) {
                    $drift++;
                }
                continue;
            }

            file_put_contents($row['path'], $row['code']);
            $output->writeln(sprintf(
                '<info>built</info> %-26s %7d B  <- %d 分区（源 %7d B，省 %d 次请求）',
                $row['bundle'],
                $row['size'],
                count($row['parts']),
                $row['src'],
                count($row['parts']) - 1
            ));
        }

        if ($check && $drift > 0) {
            $output->writeln('<error>' . $drift . " 个 bundle 与源文件不一致，跑 php think front:bundle 重建</error>");
            return 1;
        }

        return 0;
    }

    public static function buildBundle(string $dir, string $bundle, array $parts, string $kind, ?string $bannerTpl = null): string
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
                $code = rtrim($code) . ';';
                $marker = '// ── ';
            }
            $pad = str_repeat('─', max(2, 56 - strlen($p)));
            $body .= $isCss
                ? "\n" . $marker . self::partition($i, $p, $pad) . " */\n" . rtrim($code) . "\n"
                : "\n" . $marker . self::partition($i, $p, $pad) . "\n" . rtrim($code) . "\n";
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

    private static function partition(int $i, string $p, string $pad): string
    {
        return str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 原 ' . $p . ' ' . $pad;
    }
}
