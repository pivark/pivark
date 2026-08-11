<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\watermark;
use app\common\service\watermark\WatermarkConfigService;

/** 对本地图片文件叠加水印 */
class WatermarkService
{

    public function __construct(
        private readonly WatermarkConfigService $watermarkConfigService,
    ) {
    }

    /** @var list<string> */
    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * 若已启用水印配置，对本地图片叠加水印（原地覆盖）。
     */
    public function maybeApply(string $absolutePath, string $ext): void
    {
        if (!$this->watermarkConfigService->isEnabled() || !is_file($absolutePath)) {
            return;
        }
        $ext = strtolower($ext);
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!in_array($ext, self::IMAGE_EXTS, true) || !extension_loaded('gd')) {
            return;
        }

        $cfg = $this->watermarkConfigService->all();
        $img = $this->loadImage($absolutePath, $ext);
        if ($img === null) {
            return;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 1 || $h < 1) {
            imagedestroy($img);
            return;
        }

        $minW = max(0, (int) ($cfg['watermark_min_w'] ?? 0));
        $minH = max(0, (int) ($cfg['watermark_min_h'] ?? 0));
        if ($w < $minW || $h < $minH) {
            imagedestroy($img);
            return;
        }

        $type = (string) ($cfg['watermark_type'] ?? WatermarkConfigService::TYPE_TEXT);
        $opacity = min(100, max(0, (int) ($cfg['watermark_opacity'] ?? 65)));
        $pos     = $this->watermarkConfigService->normalizePos((string) ($cfg['watermark_pos'] ?? 'br'));

        if ($type === WatermarkConfigService::TYPE_IMAGE) {
            $this->applyImageWatermark($img, $w, $h, (string) ($cfg['watermark_image'] ?? ''), $opacity, $pos);
        } else {
            $this->applyTextWatermark($img, $w, $h, (string) ($cfg['watermark_text'] ?? ''), $opacity, $pos);
        }

        $quality = min(100, max(50, (int) ($cfg['watermark_quality'] ?? 85)));
        $this->saveImage($img, $absolutePath, $ext, $quality);
        imagedestroy($img);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    /**
     * @return \GdImage|null
     */
    private function loadImage(string $path, string $ext): ?\GdImage
    {
        return match ($ext) {
            'jpg'   => @imagecreatefromjpeg($path) ?: null,
            'png'   => @imagecreatefrompng($path) ?: null,
            'gif'   => @imagecreatefromgif($path) ?: null,
            'webp'  => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
            default => null,
        };
    }

    private function saveImage(\GdImage $img, string $path, string $ext, int $quality): void
    {
        match ($ext) {
            'jpg'  => imagejpeg($img, $path, $quality),
            'png'  => imagepng($img, $path, (int) round((100 - $quality) / 10)),
            'gif'  => imagegif($img, $path),
            'webp' => function_exists('imagewebp') ? imagewebp($img, $path, $quality) : null,
            default => null,
        };
    }

    private function applyTextWatermark(
        \GdImage $img,
        int $w,
        int $h,
        string $text,
        int $opacity,
        string $pos
    ): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $fontSize = max(12, (int) round(min($w, $h) / 18));
        $font     = \app\common\support\CaptchaImageRenderer::resolveFontPath();
        $alpha    = (int) round(127 * (1 - $opacity / 100));
        $color    = imagecolorallocatealpha($img, 255, 255, 255, $alpha);
        $shadow   = imagecolorallocatealpha($img, 0, 0, 0, min(127, $alpha + 20));

        if ($font !== null && function_exists('imagettfbbox')) {
            $box = imagettfbbox($fontSize, 0, $font, $text);
            if (!is_array($box)) {
                return;
            }
            $tw = abs($box[2] - $box[0]);
            $th = abs($box[7] - $box[1]);
            [$x, $y] = $this->position($w, $h, $tw, $th, $pos, $fontSize);
            imagettftext($img, $fontSize, 0, $x + 1, $y + 1, $shadow, $font, $text);
            imagettftext($img, $fontSize, 0, $x, $y, $color, $font, $text);
            return;
        }

        $tw = imagefontwidth(5) * strlen($text);
        $th = imagefontheight(5);
        [$x, $y] = $this->position($w, $h, $tw, $th, $pos, 0);
        imagestring($img, 5, $x, $y, $text, $color);
    }

    private function applyImageWatermark(
        \GdImage $img,
        int $w,
        int $h,
        string $urlOrPath,
        int $opacity,
        string $pos
    ): void {
        $path = $this->resolveWatermarkPath($urlOrPath);
        if ($path === null) {
            return;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        $wm = $this->loadImage($path, $ext);
        if ($wm === null) {
            return;
        }

        $ww = imagesx($wm);
        $wh = imagesy($wm);
        if ($ww < 1 || $wh < 1) {
            imagedestroy($wm);
            return;
        }

        $maxW = (int) round($w * 0.35);
        if ($ww > $maxW && $maxW > 0) {
            $scale = $maxW / $ww;
            $nw    = $maxW;
            $nh    = max(1, (int) round($wh * $scale));
            $scaled = imagescale($wm, $nw, $nh);
            imagedestroy($wm);
            if ($scaled === false) {
                return;
            }
            $wm = $scaled;
            $ww = $nw;
            $wh = $nh;
        }

        [$x, $y] = $this->position($w, $h, $ww, $wh, $pos, 0);
        imagecopymerge($img, $wm, $x, $y, 0, 0, $ww, $wh, $opacity);
        imagedestroy($wm);
    }

    /** @return array{0:int,1:int} */
    private function position(int $cw, int $ch, int $tw, int $th, string $pos, int $fontSize): array
    {
        $pad = 12;
        $yBase = $fontSize > 0 ? $fontSize : $th;

        return match ($pos) {
            'tl' => [$pad, $pad + $yBase],
            'tc' => [(int) (($cw - $tw) / 2), $pad + $yBase],
            'tr' => [$cw - $tw - $pad, $pad + $yBase],
            'ml' => [$pad, (int) (($ch + $th) / 2)],
            'mc' => [(int) (($cw - $tw) / 2), (int) (($ch + $th) / 2)],
            'mr' => [$cw - $tw - $pad, (int) (($ch + $th) / 2)],
            'bl' => [$pad, $ch - $pad],
            'bc' => [(int) (($cw - $tw) / 2), $ch - $pad],
            default => [$cw - $tw - $pad, $ch - $pad],
        };
    }

    private function resolveWatermarkPath(string $urlOrPath): ?string
    {
        $urlOrPath = trim($urlOrPath);
        if ($urlOrPath === '') {
            return null;
        }
        if (str_starts_with($urlOrPath, '/')) {
            $path = ROOT_PATH . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $urlOrPath);
            return is_file($path) ? $path : null;
        }
        if (is_file($urlOrPath)) {
            return $urlOrPath;
        }

        return null;
    }
}
