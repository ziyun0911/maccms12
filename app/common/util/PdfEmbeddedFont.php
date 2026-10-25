<?php
namespace app\common\util;

/**
 * 手写 PDF 报表用的 TrueType 内嵌字体（Type0 / Identity-H）。
 *
 * 为什么需要它：报表原先只认 PDF 的 14 个标准字体和 Adobe 预定义 CMap 两套方案。
 * Helvetica 是 WinAnsi 编码，没有西里尔字形；而俄文走「退回中文」的老路等于把
 * 俄文字母送去 Adobe-GB1 的 CMap 查表，能不能显形完全取决于阅读器的替换字体，
 * 属于不可复现的行为。要让俄文报表稳定可读，只能把带西里尔覆盖的 TTF 本身塞进 PDF。
 *
 * 中日韩仍然走 Adobe 预定义 CMap（本文件不负责）：那需要一套覆盖 CJK 的字体，
 * 常见发行版里并不存在，换成内嵌方案反而会让中日报表从「大概率能看」退化成「一定缺字」。
 *
 * 字体文件从哪来：先找项目自带的 static/font/（部署方自己放一份，行为可复现），
 * 再退回系统字体目录里已装好的 DejaVu / Liberation / Noto。候选必须真的覆盖
 * А 和 а 才算合格——Windows 下很多 .ttf 后缀的文件其实是 TTC 集合，
 * 而 FontFile2 只接受单个 sfnt，所以这类文件在探测阶段就被排除，不会写坏 PDF。
 *
 * 只做整文件嵌入，不做子集化：子集化要重算 loca/glyf 的复合字形引用，代码量翻倍，
 * 而一份几百 KB 的后台报表对 300KB 的字体不敏感。流用 FlateDecode 压缩。
 */
class PdfEmbeddedFont
{
    /** 判定字体可用性所用的码位：拉丁大写 + 俄文大写 + 俄文小写 + ё */
    const PROBE_CODEPOINTS = [0x0041, 0x0410, 0x0430, 0x0451];

    /** @var string */
    private $postScriptName = '';
    /** @var string */
    private $fontFile = '';
    /** @var int */
    private $unitsPerEm = 1000;
    /** @var int[] */
    private $bbox = [0, 0, 0, 0];
    /** @var int */
    private $ascent = 800;
    /** @var int */
    private $descent = -200;
    /** @var array */
    private $cmap = [];
    /** @var int */
    private $numGlyphs = 0;
    /** @var int */
    private $hmtxCount = 0;
    /** @var array */
    private $advanceWidths = [];
    /** @var int */
    private $lastAdvanceWidth = 0;
    /** @var array */
    private $gidCache = [];
    /** @var array */
    private $usedGids = [];

    /** @var self|null */
    private static $instance = null;
    /** @var bool */
    private static $resolved = false;

    /**
     * 解析并加载一个可用的内嵌字体；找不到返回 null（调用方负责降级）。
     * 同一次请求内复用，避免每页报表重新解析字体文件。
     */
    public static function resolve()
    {
        if (self::$resolved) {
            return self::$instance;
        }
        self::$resolved = true;
        foreach (self::candidatePaths() as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $font = self::load($path);
            if ($font !== null && $font->coversProbes()) {
                self::$instance = $font;
                return self::$instance;
            }
        }
        return null;
    }

    /**
     * 候选字体路径。项目自带优先，其次是常见发行版的字体目录。
     */
    private static function candidatePaths()
    {
        $paths = [];
        $local = (function_exists('root_path') ? root_path() : '') . 'static' . DIRECTORY_SEPARATOR . 'font' . DIRECTORY_SEPARATOR;
        foreach (['DejaVuSans.ttf', 'LiberationSans-Regular.ttf', 'NotoSans-Regular.ttf', 'FreeSans.ttf'] as $name) {
            $paths[] = $local . $name;
        }
        if (is_dir($local)) {
            foreach ((array)glob($local . '*.ttf') as $ttf) {
                $paths[] = $ttf;
            }
        }

        $win = 'C:/Windows/Fonts/';
        $paths[] = $win . 'DejaVuSans.ttf';
        $paths[] = $win . 'DejaVuSansMono.ttf';
        $paths[] = $win . 'DejaVuSansMono_0.ttf';
        $paths[] = $win . 'Arial Unicode.ttf';
        $paths[] = $win . 'Gulim.ttf';

        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/liberation-sans/LiberationSans-Regular.ttf',
            '/usr/share/fonts/google-noto-extras/NotoSans-Regular.ttf',
            '/usr/share/fonts/noto/NotoSans-Regular.ttf',
            '/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
        ] as $path) {
            $paths[] = $path;
        }
        foreach ((array)glob('/usr/share/fonts/truetype/*/*Sans*.ttf') as $ttf) {
            $paths[] = $ttf;
        }

        return $paths;
    }

    /**
     * 读取一个 sfnt 字体。TTC 集合（ttcf）直接拒绝：FontFile2 只能装单个字体。
     */
    public static function load($path)
    {
        $data = @file_get_contents($path);
        if (!is_string($data) || strlen($data) < 12) {
            return null;
        }
        $tag = substr($data, 0, 4);
        if ($tag === 'ttcf' || ($tag !== "\x00\x01\x00\x00" && $tag !== 'true' && $tag !== 'typ2')) {
            return null;
        }
        $font = new self();
        $font->fontFile = $data;

        $tables = [];
        $numTables = self::u16($data, 4);
        for ($i = 0; $i < $numTables; $i++) {
            $entry = 12 + $i * 16;
            if ($entry + 16 > strlen($data)) {
                return null;
            }
            $tables[substr($data, $entry, 4)] = [self::u32($data, $entry + 8), self::u32($data, $entry + 12)];
        }
        foreach (['head', 'hhea', 'hmtx', 'maxp', 'cmap'] as $required) {
            if (!isset($tables[$required])) {
                return null;
            }
        }

        $head = $tables['head'][0];
        $font->unitsPerEm = max(1, self::u16($data, $head + 18));
        $font->bbox = [
            self::s16($data, $head + 36), self::s16($data, $head + 38),
            self::s16($data, $head + 40), self::s16($data, $head + 42),
        ];
        $hhea = $tables['hhea'][0];
        $font->ascent = self::s16($data, $hhea + 4);
        $font->descent = self::s16($data, $hhea + 6);
        $font->hmtxCount = self::u16($data, $hhea + 34);
        $font->numGlyphs = self::u16($data, $tables['maxp'][0] + 4);

        $hmtx = $tables['hmtx'][0];
        for ($i = 0; $i < $font->hmtxCount; $i++) {
            $font->advanceWidths[$i] = self::u16($data, $hmtx + $i * 4);
        }
        if (!empty($font->advanceWidths)) {
            $font->lastAdvanceWidth = (int)end($font->advanceWidths);
        }

        $font->parseCmap($data, $tables['cmap'][0]);
        if (empty($font->cmap)) {
            return null;
        }

        $font->postScriptName = self::readPostScriptName($data, isset($tables['name']) ? $tables['name'][0] : 0, $path);

        return $font;
    }

    /**
     * 收集 fmt4（BMP）与 fmt12（全码位）两张子表；后者优先。
     */
    private function parseCmap($data, $offset)
    {
        $numSubtables = self::u16($data, $offset + 2);
        $fmt4 = 0;
        $fmt12 = 0;
        for ($i = 0; $i < $numSubtables; $i++) {
            $record = $offset + 4 + $i * 8;
            $platform = self::u16($data, $record);
            $encoding = self::u16($data, $record + 2);
            $sub = $offset + self::u32($data, $record + 4);
            $format = self::u16($data, $sub);
            $isUnicode = ($platform == 3 && ($encoding == 1 || $encoding == 10))
                || ($platform == 0 && ($encoding == 3 || $encoding == 4 || $encoding == 6))
                || ($platform == 0 && $encoding == 2);
            if (!$isUnicode) {
                continue;
            }
            if ($format == 12 && $sub > 0) {
                $fmt12 = $sub;
            } elseif ($format == 4 && $fmt4 == 0) {
                $fmt4 = $sub;
            }
        }
        if ($fmt4 > 0) {
            $segX2 = self::u16($data, $fmt4 + 6);
            $seg = (int)($segX2 / 2);
            $end = $fmt4 + 14 + $segX2;
            $start = $end + $segX2 + 2;
            $delta = $start + $segX2;
            $range = $delta + $segX2;
            if ($range + $segX2 <= strlen($data)) {
                $this->cmap['fmt4'] = [
                    'seg' => $seg,
                    'end' => $end,
                    'start' => $start,
                    'delta' => $delta,
                    'range' => $range,
                ];
            }
        }
        if ($fmt12 > 0) {
            $groups = self::u32($data, $fmt12 + 12);
            if ($groups > 0 && $fmt12 + 16 + $groups * 12 <= strlen($data)) {
                $this->cmap['fmt12'] = ['offset' => $fmt12 + 16, 'groups' => min($groups, 100000)];
            }
        }
    }

    /**
     * 码位 → 字形号。未收录返回 0（.notdef），由调用方决定丢弃还是留白。
     */
    public function glyphIndex($codepoint)
    {
        $codepoint = (int)$codepoint;
        if (isset($this->gidCache[$codepoint])) {
            return $this->gidCache[$codepoint];
        }
        $gid = 0;
        if (isset($this->cmap['fmt12'])) {
            $base = $this->cmap['fmt12']['offset'];
            $count = $this->cmap['fmt12']['groups'];
            for ($i = 0; $i < $count; $i++) {
                $group = $base + $i * 12;
                $start = self::u32($this->fontFile, $group);
                $end = self::u32($this->fontFile, $group + 4);
                if ($codepoint >= $start && $codepoint <= $end) {
                    $gid = self::u32($this->fontFile, $group + 8) + ($codepoint - $start);
                    break;
                }
                if ($start > $codepoint) {
                    break;
                }
            }
        }
        if ($gid === 0 && $codepoint <= 0xFFFF && isset($this->cmap['fmt4'])) {
            $c = $this->cmap['fmt4'];
            $data = $this->fontFile;
            for ($s = 0; $s < $c['seg']; $s++) {
                $end = self::u16($data, $c['end'] + $s * 2);
                if ($codepoint > $end) {
                    continue;
                }
                $start = self::u16($data, $c['start'] + $s * 2);
                if ($codepoint < $start) {
                    break;
                }
                $delta = self::u16($data, $c['delta'] + $s * 2);
                $rangeOffset = self::u16($data, $c['range'] + $s * 2);
                // idRangeOffset 是相对本条 idRangeOffset[s] 自身的字节偏移，且 0 表示 delta 直算。
                if ($rangeOffset === 0) {
                    $gid = ($codepoint + $delta) & 0xFFFF;
                } else {
                    $addr = $c['range'] + $s * 2 + $rangeOffset + ($codepoint - $start) * 2;
                    $gid = self::u16($data, $addr);
                    if ($gid !== 0) {
                        $gid = ($gid + $delta) & 0xFFFF;
                    }
                }
                break;
            }
        }
        if ($gid >= $this->numGlyphs) {
            $gid = 0;
        }
        $this->gidCache[$codepoint] = $gid;
        return $gid;
    }

    /**
     * 字形宽度换算到 PDF 的 1000 单位坐标系。
     */
    public function width1000($gid)
    {
        $gid = (int)$gid;
        if ($this->hmtxCount === 0) {
            return 500;
        }
        $units = isset($this->advanceWidths[$gid]) ? $this->advanceWidths[$gid] : $this->lastAdvanceWidth;
        return (int)round($units * 1000 / $this->unitsPerEm);
    }

    /**
     * 把一段 UTF-8 文本编码成 Identity-H 的十六进制串，并登记用到的字形。
     */
    public function encode($text)
    {
        $hex = '';
        $chars = preg_split('//u', (string)$text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return '';
        }
        foreach ($chars as $ch) {
            $u = unpack('N', mb_convert_encoding($ch, 'UTF-32BE', 'UTF-8'));
            $cp = isset($u[1]) ? (int)$u[1] : 0;
            $gid = $this->glyphIndex($cp);
            $this->usedGids[$gid] = $cp;
            $hex .= sprintf('%04X', $gid);
        }
        return $hex;
    }

    private function coversProbes()
    {
        foreach (self::PROBE_CODEPOINTS as $cp) {
            if ($this->glyphIndex($cp) === 0) {
                return false;
            }
        }
        return true;
    }

    public function baseFontName()
    {
        return $this->postScriptName;
    }

    /**
     * 生成 Type0 / CIDFontType0 / FontDescriptor / FontFile2 / ToUnicode 五个对象。
     *
     * @param int $firstObjNum Type0 字体对象的编号，后续对象依次占用
     * @return array [objNum => 对象内容字符串]，并返回最大编号供调用方续排
     */
    public function buildObjects($firstObjNum)
    {
        $type0 = (int)$firstObjNum;
        $descendant = $type0 + 1;
        $descriptor = $type0 + 2;
        $file = $type0 + 3;
        $toUnicode = $type0 + 4;
        $name = $this->postScriptName;

        // 宽度：全部等宽（等宽字体）时只用 /DW，否则按连续等宽区间输出 /W。
        $widths = [];
        foreach (array_keys($this->usedGids) as $gid) {
            $widths[$gid] = $this->width1000($gid);
        }
        ksort($widths);
        $distinct = array_unique($widths);
        $defaultWidth = empty($distinct) ? 500 : (int)reset($distinct);
        $w = '';
        if (count($distinct) > 1) {
            $runs = [];
            $prevGid = -3;
            $prevWidth = null;
            $from = 0;
            foreach ($widths as $gid => $width) {
                if ($gid === $prevGid + 1 && $width === $prevWidth) {
                    $prevGid = $gid;
                    continue;
                }
                if ($prevWidth !== null) {
                    $runs[] = $from . ' ' . $prevGid . ' ' . $prevWidth;
                }
                $from = $gid;
                $prevGid = $gid;
                $prevWidth = $width;
            }
            if ($prevWidth !== null) {
                $runs[] = $from . ' ' . $prevGid . ' ' . $prevWidth;
            }
            $w = ' /W [' . implode(' ', $runs) . ']';
        }

        $bbox = [];
        foreach ($this->bbox as $v) {
            $bbox[] = (int)round($v * 1000 / $this->unitsPerEm);
        }

        $objects = [];
        $objects[$type0] = $type0 . " 0 obj\n<< /Type /Font /Subtype /Type0 /BaseFont /" . $name
            . " /Encoding /Identity-H /DescendantFonts [" . $descendant . " 0 R] /ToUnicode " . $toUnicode . " 0 R >>\nendobj\n";
        $objects[$descendant] = $descendant . " 0 obj\n<< /Type /Font /Subtype /CIDFontType0 /BaseFont /" . $name
            . " /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /CIDToGIDMap /Identity"
            . " /FontDescriptor " . $descriptor . " 0 R /DW " . $defaultWidth . $w . " >>\nendobj\n";
        $objects[$descriptor] = $descriptor . " 0 obj\n<< /Type /FontDescriptor /FontName /" . $name
            . " /Flags 32 /FontBBox [" . implode(' ', $bbox) . "] /ItalicAngle 0"
            . " /Ascent " . (int)round($this->ascent * 1000 / $this->unitsPerEm)
            . " /Descent " . (int)round($this->descent * 1000 / $this->unitsPerEm)
            . " /CapHeight " . (int)round(($this->bbox[3] ? $this->bbox[3] : $this->ascent) * 1000 / $this->unitsPerEm)
            . " /StemV 80 /FontFile2 " . $file . " 0 R >>\nendobj\n";

        $raw = $this->fontFile;
        $compressed = function_exists('gzcompress') ? @gzcompress($raw, 6) : false;
        $stream = ($compressed === false ? $raw : $compressed);
        $filter = ($compressed === false ? '' : ' /Filter /FlateDecode');
        $objects[$file] = $file . " 0 obj\n<< /Length " . strlen($stream) . $filter
            . " /Length1 " . strlen($raw) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n";

        $cmapStream = $this->buildToUnicodeCMap();
        $objects[$toUnicode] = $toUnicode . " 0 obj\n<< /Length " . strlen($cmapStream)
            . (function_exists('gzcompress') ? ' /Filter /FlateDecode' : '') . " >>\nstream\n"
            . (function_exists('gzcompress') ? gzcompress($cmapStream, 6) : $cmapStream) . "\nendstream\nendobj\n";

        return [$objects, $toUnicode];
    }

    /**
     * ToUnicode 让阅读器能选中、复制、检索真实文字，而不是只剩字形号。
     */
    private function buildToUnicodeCMap()
    {
        $pairs = [];
        foreach ($this->usedGids as $gid => $cp) {
            if ($gid === 0 || $cp === 0) {
                continue;
            }
            $pairs[sprintf('%04X', $gid)] = $cp;
        }
        ksort($pairs);
        if (empty($pairs)) {
            return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
                . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
                . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
                . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
                . "0 beginbfchar\nendbfchar\nendcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
        }
        $out = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        $keys = array_keys($pairs);
        for ($i = 0; $i < count($keys); $i += 100) {
            $slice = array_slice($keys, $i, 100);
            $out .= count($slice) . " beginbfchar\n";
            foreach ($slice as $k) {
                $cp = $pairs[$k];
                $uni = $cp <= 0xFFFF
                    ? sprintf('%04X', $cp)
                    : sprintf('%04X', 0xD800 + intdiv($cp - 0x10000, 0x400)) . sprintf('%04X', 0xDC00 + (($cp - 0x10000) % 0x400));
                $out .= '<' . $k . '> <' . $uni . ">\n";
            }
            $out .= "endbfchar\n";
        }
        $out .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
        return $out;
    }

    private static function readPostScriptName($data, $nameOffset, $path)
    {
        $name = '';
        if ($nameOffset > 0 && $nameOffset + 6 <= strlen($data)) {
            $count = self::u16($data, $nameOffset + 2);
            $strings = $nameOffset + self::u16($data, $nameOffset + 4);
            for ($i = 0; $i < $count; $i++) {
                $record = $nameOffset + 6 + $i * 12;
                if ($record + 12 > strlen($data)) {
                    break;
                }
                if (self::u16($data, $record + 2) != 6) {
                    continue;
                }
                $platform = self::u16($data, $record);
                $len = self::u16($data, $record + 8);
                $strOffset = $strings + self::u16($data, $record + 10);
                if ($strOffset + $len > strlen($data)) {
                    continue;
                }
                $raw = substr($data, $strOffset, $len);
                if ($platform == 3 || $platform == 0) {
                    $decoded = @iconv('UTF-16BE', 'UTF-8//IGNORE', $raw);
                    if (is_string($decoded) && $decoded !== '') {
                        $name = $decoded;
                        break;
                    }
                } elseif ($platform == 1 && $name === '') {
                    $name = $raw;
                }
            }
        }
        $name = preg_replace('/[^A-Za-z0-9._+-]/', '', (string)$name);
        if ($name === '') {
            $name = preg_replace('/[^A-Za-z0-9._+-]/', '', pathinfo($path, PATHINFO_FILENAME));
        }
        return $name === '' ? 'EmbeddedSans' : $name;
    }

    private static function u16($data, $offset)
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) {
            return 0;
        }
        return unpack('n', substr($data, $offset, 2))[1];
    }

    private static function s16($data, $offset)
    {
        $v = self::u16($data, $offset);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function u32($data, $offset)
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) {
            return 0;
        }
        return unpack('N', substr($data, $offset, 4))[1];
    }
}
