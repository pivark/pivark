<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

/**
 * 宿主 portal 账户页视图辅助（自 HostRuntimeProbe 迁出）。
 */
final class HostPortalAccountView
{
    public static function esc(mixed $value): string
    {
        $handler = HostRuntimeProbe::firstActiveHostRuntimeHandler();
        if ($handler !== null) {
            return $handler->portalViewEsc($value);
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array{scene?:string,size_class?:string} $opts
     */
    public static function renderPayChannelButtons(array $opts = []): void
    {
        $handler = HostRuntimeProbe::firstActiveHostRuntimeHandler();
        if ($handler !== null) {
            $handler->renderPortalPayChannelButtons($opts);

            return;
        }

        $scene     = (string) ($opts['scene'] ?? 'portal_activate');
        $sizeClass = trim((string) ($opts['size_class'] ?? ''));
        $buttons   = app(\app\common\service\payment\PaymentConfigService::class)->frontPayChannelOptionsFor($scene);

        foreach ($buttons as $btn) {
            $channel = (string) ($btn['channel'] ?? '');
            $class   = (string) ($btn['class'] ?? 'pv-btn');
            if ($sizeClass !== '' && $scene === 'portal_deposit') {
                $class .= ' ' . $sizeClass;
            }
            echo '<button type="button" class="' . self::esc($class) . '" data-pay-channel="' . self::esc($channel) . '">'
                . self::esc((string) ($btn['label'] ?? $channel)) . '</button>';
        }
    }
}
