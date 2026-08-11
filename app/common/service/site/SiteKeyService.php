<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\model\FloatContactItem;

use app\common\service\config\ConfigService;
/** 站点实例 ID（授权 / heartbeat 预留，v1.1+ 联网激活） */
class SiteKeyService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public const CONFIG_KEY = 'site_key';

    public function get(): string
    {
        return trim((string) $this->configService->get(self::CONFIG_KEY, ''));
    }

    public function isValid(string $key): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }

        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $key
        );
    }

    public function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return strtolower(vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        ));
    }

    /**
     * 安装或首次进入后台时确保存在 site_key；已有合法值则不覆盖。
     */
    public function ensure(): string
    {
        $current = $this->get();
        if ($this->isValid($current)) {
            return $current;
        }

        $key = $this->generate();
        $this->configService->set(self::CONFIG_KEY, $key);
        $this->configService->forgetRequestCache();

        return $key;
    }
}
