<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\watermark;

use app\common\support\ServiceResult;

use app\common\service\config\ConfigService;
/** 上传图片水印（文字 / 图片） */
class WatermarkConfigService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public const TYPE_TEXT  = 'text';
    public const TYPE_IMAGE = 'image';

    /** @return list<string> */
    public function keys(): array
    {
        return [
            'watermark_on',
            'watermark_type',
            'watermark_text',
            'watermark_image',
            'watermark_min_w',
            'watermark_min_h',
            'watermark_opacity',
            'watermark_quality',
            'watermark_pos',
        ];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->keys() as $key) {
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'watermark_on'      => '0',
            'watermark_type'    => self::TYPE_TEXT,
            'watermark_min_w'   => '200',
            'watermark_min_h'   => '50',
            'watermark_opacity' => '65',
            'watermark_quality' => '85',
            'watermark_pos'     => 'br',
            default             => '',
        };
    }

    public function isEnabled(): bool
    {
        return (string) $this->configService->get('watermark_on', '0') === '1';
    }

    public function normalizePos(string $pos): string
    {
        $pos = strtolower(trim($pos));
        $allowed = ['tl', 'tc', 'tr', 'ml', 'mc', 'mr', 'bl', 'bc', 'br'];

        return in_array($pos, $allowed, true) ? $pos : 'br';
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $type = (string) ($data['watermark_type'] ?? self::TYPE_TEXT);
        if (!in_array($type, [self::TYPE_TEXT, self::TYPE_IMAGE], true)) {
            $type = self::TYPE_TEXT;
        }

        $payload = [
            'watermark_on'      => !empty($data['watermark_on']) ? '1' : '0',
            'watermark_type'    => $type,
            'watermark_text'    => trim((string) ($data['watermark_text'] ?? '')),
            'watermark_image'   => trim((string) ($data['watermark_image'] ?? '')),
            'watermark_min_w'   => (string) max(0, (int) ($data['watermark_min_w'] ?? 200)),
            'watermark_min_h'   => (string) max(0, (int) ($data['watermark_min_h'] ?? 50)),
            'watermark_opacity' => (string) min(100, max(0, (int) ($data['watermark_opacity'] ?? 65))),
            'watermark_quality' => (string) min(100, max(50, (int) ($data['watermark_quality'] ?? 85))),
            'watermark_pos'     => $this->normalizePos((string) ($data['watermark_pos'] ?? 'br')),
        ];
        foreach ($payload as $key => $value) {
            $this->configService->set($key, $value);
        }
        $this->configService->forgetRequestCache();

        return ServiceResult::ok(null, '水印配置已保存');
    }
}
