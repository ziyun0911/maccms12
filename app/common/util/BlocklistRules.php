<?php
namespace app\common\util;

class BlocklistRules
{
    public const DIRS = ['说明文档', 'runtime', 'vendor', 'config', 'app', 'extend', 'bin'];
    public const EXTENSIONS = ['md', 'sql', 'log', 'lock', 'wpm', 'sh', 'ps1', 'py'];
    public const BACKUP_SUFFIXES = ['bak', 'old', 'orig'];
    public const ROOT_FILES = ['composer.json', 'composer.lock', 'think', '.user.ini', 'router.php'];
    public const DOT_EXEMPT = 'well-known';

    public const FENCE = 'maccms:blocklist';

    public static function targets(): array
    {
        return [
            '.htaccess' => ['apache', ['rewrite', 'files', 'files-legacy']],
            '说明文档/伪静态规则/.htaccess' => ['apache', ['rewrite', 'files', 'files-legacy']],
            '说明文档/伪静态规则/maccms.conf' => ['nginx', ['locations']],
            '说明文档/伪静态规则/web.config' => ['iis', ['rules', 'security']],
            'router.php' => ['php', ['lists']],
        ];
    }

    public static function snippets(string $flavor): array
    {
        switch ($flavor) {
            case 'apache':
                return ['rewrite' => self::apacheRewrite(), 'files' => self::apacheFiles(true), 'files-legacy' => self::apacheFiles(false)];
            case 'nginx':
                return ['locations' => self::nginxLocations()];
            case 'iis':
                return ['rules' => self::iisRules(), 'security' => self::iisSecurity()];
            case 'php':
                return ['lists' => self::phpLists()];
        }

        return [];
    }

    public static function open(string $flavor, string $name): string
    {
        return self::prefix($flavor, '>>> ' . self::FENCE . ':' . $name . ' >>>');
    }

    public static function close(string $flavor, string $name): string
    {
        return self::prefix($flavor, '<<< ' . self::FENCE . ':' . $name . ' <<<');
    }

    private static function prefix(string $flavor, string $body): string
    {
        if ($flavor === 'iis') {
            return '<!-- ' . $body . ' -->';
        }

        return ($flavor === 'php' ? '//' : '#') . ' ' . $body;
    }

    public static function check(bool $write = false): array
    {
        $result = [];
        $root = rtrim(str_replace('\\', '/', root_path()), '/') . '/';
        foreach (self::targets() as $path => $spec) {
            [$flavor, $names] = $spec;
            $file = $root . $path;
            $entry = ['path' => $path, 'flavor' => $flavor, 'status' => 'ok', 'regions' => [], 'changed' => false];
            if (!is_file($file)) {
                $entry['status'] = 'missing-file';
                $result[] = $entry;
                continue;
            }
            if (!is_writable($file)) {
                $entry['status'] = 'unwritable';
                $result[] = $entry;
                continue;
            }
            $content = str_replace("\r\n", "\n", (string)file_get_contents($file));
            $new = $content;
            $snippets = self::snippets($flavor);
            foreach ($names as $name) {
                $open = self::open($flavor, $name);
                $close = self::close($flavor, $name);
                $want = $open . "\n" . ($snippets[$name] ?? '') . "\n" . $close;
                $pos = strpos($new, $open);
                if ($pos === false) {
                    $entry['status'] = 'no-fence';
                    $entry['regions'][$name] = 'no-fence';
                    continue;
                }
                $end = strpos($new, $close, $pos);
                if ($end === false) {
                    $entry['status'] = 'broken-fence';
                    $entry['regions'][$name] = 'broken-fence';
                    continue;
                }
                $have = substr($new, $pos, $end + strlen($close) - $pos);
                if ($have !== $want) {
                    $entry['regions'][$name] = 'drift';
                    if ($entry['status'] === 'ok') {
                        $entry['status'] = 'drift';
                    }
                } else {
                    $entry['regions'][$name] = 'ok';
                }
                $new = substr($new, 0, $pos) . $want . substr($new, $end + strlen($close));
            }
            $entry['changed'] = $new !== $content;
            if ($write && $entry['changed']) {
                if (file_put_contents($file, $new) === false) {
                    $entry['status'] = 'unwritable';
                }
            }
            $result[] = $entry;
        }

        return $result;
    }

    private static function apacheRewrite(): string
    {
        $dirs = implode('|', self::DIRS);
        $out = "# ---- 内部目录与隐藏文件（mod_rewrite）----\n";
        $out .= "<IfModule mod_rewrite.c>\n";
        $out .= "    RewriteEngine On\n";
        $out .= "    # 服务端专用目录：任何直接访问一律 403\n";
        $out .= '    RewriteRule ^(' . $dirs . ')(/|$) - [F,L]' . "\n";
        $out .= '    # 隐藏文件/目录，保留 .' . self::DOT_EXEMPT . "（HTTPS 证书 HTTP-01 校验要用）\n";
        $out .= '    RewriteRule (^|/)\.(?!' . self::DOT_EXEMPT . ') - [F,L]' . "\n";
        $out .= '</IfModule>';

        return $out;
    }

    private static function apacheFiles(bool $v24): string
    {
        $patterns = [
            '\.(' . implode('|', self::EXTENSIONS) . ')$',
            '\.(' . implode('|', self::BACKUP_SUFFIXES) . ')([._][A-Za-z0-9-]*)?$',
            '^(' . implode('|', array_map([self::class, 'escRegex'], self::ROOT_FILES)) . ')$',
        ];
        $out = $v24
            ? "# ---- 内部后缀与根级清单文件（Apache 2.4）----\n<IfModule mod_authz_core.c>\n"
            : "# ---- 同上（Apache 2.2 回退写法）----\n<IfModule !mod_authz_core.c>\n";
        foreach ($patterns as $i => $p) {
            if ($v24 && $i === 1) {
                $out .= '    # 后缀式备份残留：.bak / .bak_cl / .bak_20261024 / .old / .orig 一律拦下' . "\n";
            }
            $out .= '    <FilesMatch "' . $p . '">' . "\n";
            $out .= $v24 ? "        Require all denied\n" : "        Order deny,allow\n        Deny from all\n";
            $out .= "    </FilesMatch>\n";
        }

        return rtrim($out, "\n") . "\n</IfModule>";
    }

    private static function nginxLocations(): string
    {
        $dirs = implode('|', self::DIRS);
        $ext = implode('|', self::EXTENSIONS);
        $bak = implode('|', self::BACKUP_SUFFIXES);
        $out = "location ^~ /." . self::DOT_EXEMPT . "/ {\n    allow all;\n}\n";
        $out .= 'location ~ ^/(' . $dirs . ')(/|$) {' . "\n    return 404;\n}\n";
        $out .= "location ~ /\\. {\n    return 404;\n}\n";
        $out .= 'location ~* \.(' . $ext . ')$ {' . "\n    return 404;\n}\n";
        $out .= "# 后缀式备份残留：.bak / .bak_cl / .bak_20261024 / .old / .orig\n";
        $out .= 'location ~* \.(' . $bak . ')([._].*)?$ {' . "\n    return 404;\n}\n";
        $root = [];
        foreach (self::ROOT_FILES as $f) {
            $root[] = 'location = /' . $f . ' { return 404; }';
        }
        $out .= implode("\n", $root);

        return $out;
    }

    private static function iisRules(): string
    {
        $bak = implode('|', self::BACKUP_SUFFIXES);
        $root = implode('|', array_map([self::class, 'escRegex'], self::ROOT_FILES));
        $t = "\t\t ";
        $out = $t . "<!-- 后缀式备份残留（.bak/.bak_cl/.bak_日期/.old/.orig）一律拒绝。\n";
        $out .= $t . "     requestFiltering 的 fileExtensions 只认最后一段后缀，覆盖不了\n";
        $out .= $t . "     x.bak_20261024 这类写法，所以用规则兜住；放在 OrgPage 之前。 -->\n";
        $out .= $t . '<rule name="DenyBackupFiles" stopProcessing="true">' . "\n";
        $out .= $t . '<match url="(?i).*\.(' . $bak . ')([._]|$)" />' . "\n";
        $out .= $t . '<action type="AbortRequest" reason="Backup file not accessible" />' . "\n";
        $out .= $t . '</rule>' . "\n";
        $out .= $t . "<!-- 隐藏文件/目录（保留 ." . self::DOT_EXEMPT . "）：requestFiltering 的 hiddenSegments\n";
        $out .= $t . "     匹配不到以点开头的名字，只能靠规则兜住；只拦段首是 . 的路径，\n";
        $out .= $t . "     /.well-known/acme-challenge/xxx 这类证书校验路径照常放行。 -->\n";
        $out .= $t . '<rule name="DenyDotFiles" stopProcessing="true">' . "\n";
        $out .= $t . '<match url="(^|/)\.(?!' . self::DOT_EXEMPT . ')" />' . "\n";
        $out .= $t . '<action type="AbortRequest" reason="Hidden file not accessible" />' . "\n";
        $out .= $t . '</rule>' . "\n";
        $out .= $t . "<!-- 根级清单文件：.lock 已被 fileExtensions 挡住，composer.json 与 think\n";
        $out .= $t . "     没有危险后缀，router.php 是 php -S 的开发路由，逐个点名拦；\n";
        $out .= $t . '     .json 不能整类拦，后台 Swagger 还要用 static/swagger/*.json。 -->' . "\n";
        $out .= $t . '<rule name="DenyRootFiles" stopProcessing="true">' . "\n";
        $out .= $t . '<match url="^(' . $root . ')$" />' . "\n";
        $out .= $t . '<action type="AbortRequest" reason="Internal file not accessible" />' . "\n";
        $out .= $t . '</rule>';

        return $out;
    }

    private static function iisSecurity(): string
    {
        $out = '                <hiddenSegments>' . "\n";
        foreach (self::DIRS as $d) {
            $out .= '                    <add segment="' . $d . '" />' . "\n";
        }
        $out .= "                </hiddenSegments>\n";
        $out .= "                <fileExtensions>\n";
        foreach (self::EXTENSIONS as $e) {
            $out .= '                    <add fileExtension=".' . $e . '" allowed="false" />' . "\n";
        }
        $out .= '                </fileExtensions>';

        return $out;
    }

    private static function phpLists(): string
    {
        return '$internalDirs = [' . self::quotedList(self::DIRS) . "];\n"
            . '$internalFiles = [' . self::quotedList(self::ROOT_FILES) . "];\n"
            . '$deniedExt = [' . self::quotedList(self::EXTENSIONS) . "];\n"
            . '$deniedBackupExt = [' . self::quotedList(self::BACKUP_SUFFIXES) . "];\n"
            . '$deniedDotExempt = \'' . self::DOT_EXEMPT . "';";
    }

    private static function quotedList(array $items): string
    {
        return implode(', ', array_map(static fn($v) => "'" . str_replace("'", "\\'", $v) . "'", $items));
    }

    private static function escRegex(string $v): string
    {
        return str_replace('.', '\.', $v);
    }
}
