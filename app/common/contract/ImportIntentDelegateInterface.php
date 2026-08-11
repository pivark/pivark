<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\contract;

/**
 * 内容捕获 / 导入意图 — 插件侧声明（内核经 PluginExtensionRegistry dispatch）。
 *
 * 前台 stash / 预填在 admin SPA 由 TS ImportIntentHandler 执行；
 * PHP delegate 提供 SSOT 元数据、权益与后续服务端校验扩展点。
 */
interface ImportIntentDelegateInterface
{
    public function identifier(): string;

    public function isEnabled(): bool;

    /** @return list<string> Intent kind：pan_share / local_archive / video_page … */
    public function supportedIntentKinds(): array;

    /** @return list<string> Channel：paste / drop / scan / import / voice / chat */
    public function supportedChannels(): array;

    public function priority(): int;

    /** 后台弹窗用短标签 */
    public function label(): string;
}
