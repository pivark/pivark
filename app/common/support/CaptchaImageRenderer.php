<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

use think\facade\Config;

/** 验证码 PNG 渲染（TTF + 干扰线/杂点，出图逻辑对齐 think-captcha 思路） */
class CaptchaImageRenderer
{
    /**
     * @param array{width?:int,height?:int,font_size?:int,use_curve?:bool,use_noise?:bool,bg?:array{0:int,1:int,2:int}} $options
     */
    public static function png(string $code, array $options = []): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        $width    = max(80, (int) ($options['width'] ?? 132));
        $height   = max(30, (int) ($options['height'] ?? 36));
        $fontSize = max(12, (int) ($options['font_size'] ?? 18));
        $useCurve = !array_key_exists('use_curve', $options) || !empty($options['use_curve']);
        $useNoise = !array_key_exists('use_noise', $options) || !empty($options['use_noise']);
        $bg       = $options['bg'] ?? [243, 251, 254];
        if (!is_array($bg) || count($bg) < 3) {
            $bg = [243, 251, 254];
        }

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            return '';
        }

        $background = imagecolorallocate($image, (int) $bg[0], (int) $bg[1], (int) $bg[2]);
        imagefill($image, 0, 0, $background);

        if ($useNoise) {
            self::writeNoise($image, $width, $height);
        }
        if ($useCurve) {
            self::writeCurve($image, $width, $height);
        }

        $font = self::resolveFontPath();
        if ($font !== null && function_exists('imagettftext')) {
            self::writeTtfCode($image, $code, $font, $fontSize, $width, $height);
        } else {
            self::writeBitmapCode($image, $code, $width, $height);
        }

        ob_start();
        imagepng($image);
        $data = ob_get_clean() ?: '';
        imagedestroy($image);

        return $data;
    }

    public static function resolveFontPath(): ?string
    {
        $configured = (string) Config::get('captcha.render.font', 'public/static/common/fonts/captcha.ttf');
        $candidates = [
            self::absPath($configured),
            defined('ROOT_PATH') ? ROOT_PATH . 'public/static/common/fonts/captcha.ttf' : null,
        ];
        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function absPath(string $relative): string
    {
        if ($relative === '') {
            return '';
        }
        if ($relative[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $relative) === 1) {
            return $relative;
        }
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;

        return $root . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * @param \GdImage $image
     */
    private static function writeNoise($image, int $width, int $height): void
    {
        $count = (int) ($width * $height / 3);
        for ($i = 0; $i < $count; $i++) {
            $color = imagecolorallocate($image, random_int(120, 220), random_int(120, 220), random_int(120, 220));
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $color);
        }
    }

    /**
     * @param \GdImage $image
     */
    private static function writeCurve($image, int $width, int $height): void
    {
        for ($i = 0; $i < 3; $i++) {
            $color = imagecolorallocate($image, random_int(100, 180), random_int(100, 180), random_int(100, 180));
            $startX = random_int(0, (int) ($width / 4));
            $startY = random_int(0, $height);
            $endX   = random_int((int) ($width * 3 / 4), $width);
            $endY   = random_int(0, $height);
            $ctrlX  = random_int((int) ($width / 4), (int) ($width * 3 / 4));
            $ctrlY  = random_int(0, $height);
            for ($t = 0; $t <= 1; $t += 0.01) {
                $x = (int) ((1 - $t) * (1 - $t) * $startX + 2 * (1 - $t) * $t * $ctrlX + $t * $t * $endX);
                $y = (int) ((1 - $t) * (1 - $t) * $startY + 2 * (1 - $t) * $t * $ctrlY + $t * $t * $endY);
                if ($x >= 0 && $x < $width && $y >= 0 && $y < $height) {
                    imagesetpixel($image, $x, $y, $color);
                }
            }
        }
    }

    /**
     * @param \GdImage $image
     */
    private static function writeTtfCode($image, string $code, string $font, int $fontSize, int $width, int $height): void
    {
        $len = strlen($code);
        if ($len < 1) {
            return;
        }

        $slot = max(1, (int) (($width - 10) / $len));
        $x    = 8;
        for ($i = 0; $i < $len; $i++) {
            $char  = $code[$i];
            $color = imagecolorallocate($image, random_int(10, 80), random_int(10, 80), random_int(10, 80));
            $angle = random_int(-25, 25);
            $y     = random_int((int) ($height * 0.65), (int) ($height * 0.85));
            imagettftext($image, $fontSize, $angle, $x, $y, $color, $font, $char);
            $x += $slot;
        }
    }

    /**
     * @param \GdImage $image
     */
    private static function writeBitmapCode($image, string $code, int $width, int $height): void
    {
        $x   = 10;
        $len = strlen($code);
        for ($i = 0; $i < $len; $i++) {
            $color = imagecolorallocate($image, random_int(20, 70), random_int(20, 70), random_int(20, 70));
            imagestring($image, 5, $x, random_int(6, 14), $code[$i], $color);
            $x += (int) max(18, ($width - 20) / max(1, $len));
        }
    }
}
