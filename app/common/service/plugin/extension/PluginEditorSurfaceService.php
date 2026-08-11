<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\extension;

use app\common\support\ServiceResult;
use app\common\service\plugin\PluginManifestService;
use app\common\service\plugin\PluginService;
use app\common\service\config\ConfigService;
use app\common\service\product\ProductL1Access;

class PluginEditorSurfaceService
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly PluginService $pluginService,
        private readonly PluginManifestService $pluginManifestService,
    ) {
    }

    /** 站点级文档发布页插件块排序（JSON：inline/tab => identifier[]） */
    public const CONFIG_SURFACE_ORDER = 'document_editor_surface_order';

    public const SLOT_TAB     = 'tab';
    public const SLOT_INLINE  = 'inline';
    public const SLOT_SIDEBAR = 'sidebar';
    public const SLOT_MODAL   = 'modal';
    public const SLOT_HIDDEN  = 'hidden';

    /** @var list<string> */
    public const SLOTS = [
        self::SLOT_TAB,
        self::SLOT_INLINE,
        self::SLOT_SIDEBAR,
        self::SLOT_MODAL,
        self::SLOT_HIDDEN,
    ];

    /** @var array<string, string> */
    public const SLOT_LABELS = [
        self::SLOT_TAB     => '独立 Tab 页',
        self::SLOT_INLINE  => '嵌入基础内容区',
        self::SLOT_SIDEBAR => '右侧侧栏',
        self::SLOT_MODAL   => '弹层编辑',
        self::SLOT_HIDDEN  => '不在发布页显示',
    ];

    /** @var array<string, string> */
    private const SLOT_SETTING_DESC = [
        self::SLOT_TAB    => '在发布页顶栏增加独立 Tab，适合字段多、步骤多的配置（附件包、复杂表单等）。',
        self::SLOT_INLINE => '嵌在「基础内容」里的折叠块，与正文同一页保存，适合轻量字段（图集、单视频等）。',
        self::SLOT_HIDDEN => '文档发布页不出现编辑区；数据在插件后台或 API 维护，前台仅展示结果。',
        self::SLOT_SIDEBAR => '在发布页右侧侧栏展示插件配置区域。',
        self::SLOT_MODAL   => '通过弹层编辑插件数据，不占顶栏 Tab。',
    ];

    /** @var array<string, string> */
    private const SLOT_SETTING_EXAMPLE = [
        self::SLOT_TAB    => '参考官方：下载',
        self::SLOT_INLINE => '参考官方：图集、视频',
        self::SLOT_HIDDEN => '适合纯后台维护、前台只读的数据型插件',
        self::SLOT_SIDEBAR => '',
        self::SLOT_MODAL   => '',
    ];

    public function configKey(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));

        return $identifier . '_document_editor_slot';
    }

    public function orderConfigKey(string $identifier): string
    {
        return strtolower(trim($identifier)) . '_document_editor_order';
    }

    public function manifestDefaultOrder(string $identifier): int
    {
        $editor = $this->manifestEditor($identifier);

        return (int) ($editor['tab_order'] ?? 50);
    }

    public function siteOrderRaw(string $identifier): int
    {
        $raw = trim((string) $this->configService->get($this->orderConfigKey($identifier), ''));

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : 0;
    }

    /**
     * 排序权重（越小越靠前）：站点单插件数字 > 站点 JSON 列表位置 > manifest tab_order
     */
    public function resolveOrder(string $identifier, string $slot = ''): int
    {
        $identifier = strtolower(trim($identifier));
        $siteNum    = $this->siteOrderRaw($identifier);
        if ($siteNum > 0) {
            return $siteNum;
        }

        $slot = $this->normalizeSlot($slot);
        if ($slot === '') {
            $slot = $this->resolveSlot($identifier);
        }
        $lists = $this->siteOrderLists();
        $list  = $lists[$slot] ?? [];
        if ($list !== []) {
            $pos = array_search($identifier, $list, true);
            if ($pos !== false) {
                return ($pos + 1) * 10;
            }
        }

        return $this->manifestDefaultOrder($identifier);
    }

    /**
     * @return array{inline:list<string>,tab:list<string>}
     */
    public function siteOrderLists(): array
    {
        $raw = trim((string) $this->configService->get(self::CONFIG_SURFACE_ORDER, ''));
        if ($raw === '') {
            return ['inline' => [], 'tab' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['inline' => [], 'tab' => []];
        }

        return [
            'inline' => $this->normalizeOrderList($decoded['inline'] ?? []),
            'tab'    => $this->normalizeOrderList($decoded['tab'] ?? []),
        ];
    }

    /**
     * @param list<string> $identifiers
     * @return ServiceResult
     */
    public function saveSiteOrderList(string $slot, array $identifiers): ServiceResult
    {
        $slot = $this->normalizeSlot($slot);
        if ($slot !== self::SLOT_TAB && $slot !== self::SLOT_INLINE) {
            return ServiceResult::fail('仅支持 Tab 或嵌入区排序');
        }

        $lists = $this->siteOrderLists();
        $lists[$slot] = $this->normalizeOrderList($identifiers);
        $this->configService->set(self::CONFIG_SURFACE_ORDER, json_encode($lists, JSON_UNESCAPED_UNICODE));

        return ServiceResult::ok(null, '排序已保存');
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    public function normalizeOrderList($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $id = strtolower(trim((string) $id));
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function manifestEditor(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));

        if (ProductL1Access::isKernel($identifier)) {
            return ProductL1Access::documentEditorManifest();
        }

        if (!is_dir($this->pluginService->weappRoot() . $identifier)) {
            return [];
        }

        $manifest = $this->pluginService->readManifest($identifier);
        if ($manifest === null || empty($manifest['_manifest_valid'])) {
            return [];
        }

        $surfaces = is_array($manifest['surfaces'] ?? null) ? $manifest['surfaces'] : [];
        $editor   = is_array($surfaces['document_editor'] ?? null) ? $surfaces['document_editor'] : [];

        return $editor;
    }

    /** 是否应在插件「基础设置」展示文档编辑区配置（仅 document-addon 且启用 document_editor） */
    public function showsDocumentEditorSettings(string $identifier): bool
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return false;
        }

        if (ProductL1Access::isKernel($identifier)) {
            return ProductL1Access::allowsAdmin();
        }

        $manifest = $this->pluginService->readManifest($identifier);
        if ($manifest === null || empty($manifest['_manifest_valid'])) {
            return false;
        }

        if ($this->pluginManifestService->resolveKind($manifest) !== PluginManifestService::KIND_DOCUMENT_ADDON) {
            return false;
        }

        $editor = $this->manifestEditor($identifier);

        return $editor !== [] && !empty($editor['enabled']);
    }

    /** @return list<string> */
    public function allowedOverrides(string $identifier): array
    {
        if (!$this->showsDocumentEditorSettings($identifier)) {
            return [];
        }

        $editor = $this->manifestEditor($identifier);
        $raw    = $editor['allow_override'] ?? null;
        $out    = [];
        if (is_array($raw) && $raw !== []) {
            foreach ($raw as $slot) {
                $slot = $this->normalizeSlot((string) $slot);
                if ($slot !== '' && in_array($slot, self::SLOTS, true)) {
                    $out[] = $slot;
                }
            }
        } else {
            $out = [self::SLOT_TAB, self::SLOT_INLINE];
        }

        $out = array_values(array_unique($out));
        $out = array_values(array_filter(
            $out,
            static fn (string $slot): bool => $slot !== self::SLOT_HIDDEN
        ));

        return $out;
    }

    public function manifestDefaultSlot(string $identifier): string
    {
        $editor = $this->manifestEditor($identifier);
        if ($editor === [] || empty($editor['enabled'])) {
            return self::SLOT_HIDDEN;
        }

        return $this->normalizeSlot((string) ($editor['slot'] ?? self::SLOT_TAB));
    }

    public function siteSlotRaw(string $identifier): string
    {
        return trim((string) $this->configService->getDirect($this->configKey($identifier), ''));
    }

    public function resolveSlot(string $identifier): string
    {
        $default = $this->manifestDefaultSlot($identifier);
        if ($default === self::SLOT_HIDDEN) {
            return self::SLOT_HIDDEN;
        }

        $allowed = $this->allowedOverrides($identifier);
        $site    = $this->normalizeSlot($this->siteSlotRaw($identifier));
        if ($site !== '' && $allowed !== [] && in_array($site, $allowed, true)) {
            return $site;
        }

        return $default;
    }

    public function isEnabledInEditor(string $identifier): bool
    {
        $editor = $this->manifestEditor($identifier);
        if ($editor === [] || empty($editor['enabled'])) {
            return false;
        }

        return $this->resolveSlot($identifier) !== self::SLOT_HIDDEN;
    }

    /**
     * @return ServiceResult
     */
    public function saveSiteSlot(string $identifier, string $slot): ServiceResult
    {
        $identifier = strtolower(trim($identifier));
        $slot       = $this->normalizeSlot($slot);
        $allowed    = $this->allowedOverrides($identifier);
        if ($allowed === []) {
            return ServiceResult::fail('该插件未开放编辑页展现形态配置');
        }
        if (!in_array($slot, $allowed, true)) {
            return ServiceResult::fail('展现形态无效');
        }

        $this->configService->set($this->configKey($identifier), $slot);
        $this->configService->refreshAllCacheFromDatabase();

        return ServiceResult::ok(null, '配置已保存');
    }

    /**
     * 插件设置页卡片数据（含说明与参考）
     *
     * @return list<array{value:string,label:string,desc:string,example:string}>
     */
    public function surfaceSlotCards(string $identifier): array
    {
        $cards = [];
        foreach ($this->selectableOptions($identifier) as $opt) {
            $slot = $this->normalizeSlot((string) $opt['value']);
            if ($slot === '') {
                continue;
            }
            $cards[] = [
                'value'   => $slot,
                'label'   => $opt['label'],
                'desc'    => self::SLOT_SETTING_DESC[$slot] ?? '',
                'example' => self::SLOT_SETTING_EXAMPLE[$slot] ?? '',
            ];
        }

        return $cards;
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    /**
     * @return list<array{value:string,label:string}>
     */
    public function selectableOptions(string $identifier): array
    {
        $options = [];
        foreach ($this->allowedOverrides($identifier) as $slot) {
            $options[] = [
                'value' => $slot,
                'label' => self::SLOT_LABELS[$slot] ?? $slot,
            ];
        }

        return $options;
    }

    public function slotLabel(string $slot): string
    {
        $slot = $this->normalizeSlot($slot);

        return self::SLOT_LABELS[$slot] ?? $slot;
    }

    public function normalizeSlot(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return in_array($raw, self::SLOTS, true) ? $raw : '';
    }
}
