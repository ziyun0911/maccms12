<?php
namespace app\common\util;

class Image
{
    const THUMB_SCALING   = 1;
    const THUMB_FILLED    = 2;
    const THUMB_CENTER    = 3;
    const THUMB_NORTHWEST = 4;
    const THUMB_SOUTHEAST = 5;
    const THUMB_FIXED     = 6;

    const WATER_NORTHWEST = 1;
    const WATER_NORTH     = 2;
    const WATER_NORTHEAST = 3;
    const WATER_WEST      = 4;
    const WATER_CENTER    = 5;
    const WATER_EAST      = 6;
    const WATER_SOUTHWEST = 7;
    const WATER_SOUTH     = 8;
    const WATER_SOUTHEAST = 9;

    protected $im = null;

    protected $info = [];

    private function __construct(\SplFileInfo $file)
    {
        $data = @getimagesize($file->getPathname());
        if (false === $data || (IMAGETYPE_GIF === $data[2] && empty($data['bits']))) {
            throw new \Exception('illegal image file: ' . $file->getPathname());
        }
        $this->info = [
            'width'  => $data[0],
            'height' => $data[1],
            'type'   => image_type_to_extension($data[2], false),
            'mime'   => $data['mime'],
        ];
        $fun = 'imagecreatefrom' . $this->info['type'];
        if (!function_exists($fun)) {
            throw new \Exception('unsupported image type: ' . $this->info['type']);
        }
        $im = @$fun($file->getPathname());
        if (empty($im)) {
            throw new \Exception('failed to create image resource: ' . $file->getPathname());
        }
        $this->im = $im;
    }

    public static function open($file)
    {
        $path = self::locate($file);
        if (!is_file($path)) {
            throw new \Exception('image file not exist: ' . $file);
        }
        return new self(new \SplFileInfo($path));
    }

    public function width()
    {
        return $this->info['width'];
    }

    public function height()
    {
        return $this->info['height'];
    }

    public function type()
    {
        return $this->info['type'];
    }

    public function mime()
    {
        return $this->info['mime'];
    }

    public function size()
    {
        return [$this->info['width'], $this->info['height']];
    }

    public function thumb($width, $height, $type = self::THUMB_SCALING)
    {
        $width  = max(1, (int) $width);
        $height = max(1, (int) $height);

        $w = $this->info['width'];
        $h = $this->info['height'];

        switch ((int) $type) {
            case self::THUMB_SCALING:
                if ($w < $width && $h < $height) {
                    return $this;
                }
                $scale  = min($width / $w, $height / $h);
                $x      = $y = 0;
                $width  = $w * $scale;
                $height = $h * $scale;
                break;
            case self::THUMB_CENTER:
                $scale = max($width / $w, $height / $h);
                $w     = $width / $scale;
                $h     = $height / $scale;
                $x     = ($this->info['width'] - $w) / 2;
                $y     = ($this->info['height'] - $h) / 2;
                break;
            case self::THUMB_NORTHWEST:
                $scale = max($width / $w, $height / $h);
                $x     = $y = 0;
                $w     = $width / $scale;
                $h     = $height / $scale;
                break;
            case self::THUMB_SOUTHEAST:
                $scale = max($width / $w, $height / $h);
                $w     = $width / $scale;
                $h     = $height / $scale;
                $x     = $this->info['width'] - $w;
                $y     = $this->info['height'] - $h;
                break;
            case self::THUMB_FILLED:
                if ($w < $width && $h < $height) {
                    $scale = 1;
                } else {
                    $scale = min($width / $w, $height / $h);
                }
                $neww = (int) round($w * $scale);
                $newh = (int) round($h * $scale);
                $x    = 0;
                $y    = 0;
                $posx = (int) round(($width - $neww) / 2);
                $posy = (int) round(($height - $newh) / 2);

                $img   = imagecreatetruecolor($width, $height);
                $color = imagecolorallocate($img, 255, 255, 255);
                imagefill($img, 0, 0, $color);
                imagecopyresampled($img, $this->im, $posx, $posy, $x, $y, $neww, $newh, $w, $h);
                imagedestroy($this->im);
                $this->im = $img;

                $this->info['width']  = (int) $width;
                $this->info['height'] = (int) $height;
                return $this;
            case self::THUMB_FIXED:
                $x = $y = 0;
                break;
            default:
                throw new \Exception('unsupported thumb type: ' . $type);
        }

        return $this->crop($w, $h, $x, $y, $width, $height);
    }

    public function crop($w, $h, $x = 0, $y = 0, $width = null, $height = null)
    {
        $w = (int) round($w);
        $h = (int) round($h);
        $x = max(0, (int) round($x));
        $y = max(0, (int) round($y));

        if (empty($width)) {
            $width = $w;
        }
        if (empty($height)) {
            $height = $h;
        }
        $width  = max(1, (int) round($width));
        $height = max(1, (int) round($height));

        $img   = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $color);
        imagecopyresampled($img, $this->im, 0, 0, $x, $y, $width, $height, $w, $h);
        imagedestroy($this->im);

        $this->im             = $img;
        $this->info['width']  = $width;
        $this->info['height'] = $height;

        return $this;
    }

    public function text($text, $font, $size = 16, $color = '#00000000', $locate = self::WATER_SOUTHEAST, $offset = 0, $angle = 0)
    {
        $text = (string) $text;
        if ($text === '') {
            return $this;
        }
        $font = self::locate($font);
        if (!is_file($font)) {
            throw new \Exception('font file not exist: ' . $font);
        }
        $size = max(1, (int) $size);

        $box  = imagettfbbox($size, $angle, $font, $text);
        if (false === $box) {
            throw new \Exception('imagettfbbox failed, check gd FreeType support');
        }
        $minx = min($box[0], $box[2], $box[4], $box[6]);
        $maxx = max($box[0], $box[2], $box[4], $box[6]);
        $miny = min($box[1], $box[3], $box[5], $box[7]);
        $maxy = max($box[1], $box[3], $box[5], $box[7]);

        $x = $minx;
        $y = abs($miny);
        $w = $maxx - $minx;
        $h = $maxy - $miny;

        switch ((int) $locate) {
            case self::WATER_SOUTHEAST:
                $x += $this->info['width'] - $w;
                $y += $this->info['height'] - $h;
                break;
            case self::WATER_SOUTHWEST:
                $y += $this->info['height'] - $h;
                break;
            case self::WATER_NORTHWEST:
                break;
            case self::WATER_NORTHEAST:
                $x += $this->info['width'] - $w;
                break;
            case self::WATER_CENTER:
                $x += ($this->info['width'] - $w) / 2;
                $y += ($this->info['height'] - $h) / 2;
                break;
            case self::WATER_SOUTH:
                $x += ($this->info['width'] - $w) / 2;
                $y += $this->info['height'] - $h;
                break;
            case self::WATER_EAST:
                $x += $this->info['width'] - $w;
                $y += ($this->info['height'] - $h) / 2;
                break;
            case self::WATER_NORTH:
                $x += ($this->info['width'] - $w) / 2;
                break;
            case self::WATER_WEST:
                $y += ($this->info['height'] - $h) / 2;
                break;
            default:
                if (is_array($locate)) {
                    list($posx, $posy) = $locate;
                    $x += (int) $posx;
                    $y += (int) $posy;
                } else {
                    throw new \Exception('unsupported watermark location: ' . $locate);
                }
        }

        if (is_array($offset)) {
            $offset = array_map('intval', $offset);
            $ox     = $offset[0] ?? 0;
            $oy     = $offset[1] ?? 0;
        } else {
            $offset = intval($offset);
            $ox     = $oy = $offset;
        }

        if (is_string($color) && 0 === strpos($color, '#')) {
            $color = array_map('hexdec', str_split(substr($color, 1), 2));
            if (empty($color[3]) || $color[3] > 127) {
                $color[3] = 0;
            }
        } elseif (!is_array($color)) {
            throw new \Exception('illegal text color');
        }
        $col = imagecolorallocatealpha($this->im, (int) $color[0], (int) $color[1], (int) $color[2], (int) $color[3]);

        imagettftext($this->im, $size, $angle, (int) round($x + $ox), (int) round($y + $oy), $col, $font, $text);

        return $this;
    }

    public function save($pathname = '', $type = null, $quality = 80, $interlace = true)
    {
        $target = $pathname === '' ? null : self::locate($pathname, false);
        if (null === $target) {
            throw new \Exception('image save path required');
        }
        $dir = dirname($target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $type = null === $type ? $this->info['type'] : strtolower((string) $type);
        $quality = (int) $quality;

        if ('jpeg' === $type || 'jpg' === $type) {
            imageinterlace($this->im, $interlace);
            imagejpeg($this->im, $target, $quality);
        } elseif ('png' === $type) {
            imagesavealpha($this->im, true);
            imagepng($this->im, $target, min((int) ($quality / 10), 9));
        } elseif ('webp' === $type && function_exists('imagewebp')) {
            imagewebp($this->im, $target, $quality);
        } elseif ('gif' === $type && function_exists('imagegif')) {
            imagegif($this->im, $target);
        } elseif ('bmp' === $type && function_exists('imagebmp')) {
            imagebmp($this->im, $target);
        } else {
            $fun = 'image' . $type;
            if (!function_exists($fun)) {
                throw new \Exception('unsupported image save type: ' . $type);
            }
            $fun($this->im, $target);
        }

        return $this;
    }

    public static function locate($path, $must_exist = true)
    {
        $path = (string) $path;
        if ('' === $path) {
            return $path;
        }
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) || str_starts_with($path, '\\\\/')) {
            return $path;
        }
        if ($must_exist && is_file($path)) {
            return $path;
        }
        if (!$must_exist && file_exists($path)) {
            return $path;
        }
        $root = defined('ROOT_PATH') ? ROOT_PATH : '';
        if ($root === '') {
            return $path;
        }
        $rel = ltrim(str_replace(['./', '\\'], ['', '/'], $path), '/');
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $rel;
    }

    public function __destruct()
    {
        if (!empty($this->im)) {
            imagedestroy($this->im);
        }
    }
}
