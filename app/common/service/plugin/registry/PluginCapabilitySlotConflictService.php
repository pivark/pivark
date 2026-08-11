<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\registry;

use app\common\enum\ApiErrorCode;
use app\common\model\Plugin;
use app\common\service\plugin\manifest\PluginDistributionPolicy;
use app\common\service\plugin\PluginService;
use app\common\service\release\PivarkEditionService;
use app\common\support\ServiceResult;

/**
 * 插件能力槽位冲突检测：启用前按 manifest 声明的功能槽检查互斥（功能↔功能，非插件名↔插件名）。
 */
final class PluginCapabilitySlotConflictService
{
    public const SLOT_GROUP_DOCUMENT_ADDON_BRIDGE_META = 'document_addon_bridge_meta';
    public const SLOT_GROUP_COMMERCE_PRODUCT_MODE      = 'commerce_product_mode';

    /**
     * @param array<string, mixed>|null $manifest
     * @return list<string>
     */
    public function commerceProductModeSlots(?array $manifest): array
    {
        return $this->slotKeys($manifest, self::SLOT_GROUP_COMMERCE_PRODUCT_MODE);
    }

    /**
     * 站内卖货能力启用前校验：版式资格 + 功能槽互斥。
     * 冲突时返回提示文案，通过则返回 null。
     */
    public function commerceEnableGuardMessage(string $identifier): ?string
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return null;
        }

        if (PluginDistributionPolicy::isHostOnly($identifier) && !$this->hostOfficialEditionAllowed()) {
            return '该发行形态专属能力仅平台站可用；开源发行版不含此插件。';
        }

        $manifest = $this->pluginService()->readManifest($identifier);
        $mySlots  = $this->commerceProductModeSlots($manifest);
        if ($mySlots === []) {
            return null;
        }

        // 平台站：站内卖货槽仅允许发行形态受限的插件占用（不按第三方插件名枚举）
        if (app(PivarkEditionService::class)->isPlatform()
            && !PluginDistributionPolicy::isHostOnly($identifier)) {
            return '当前为平台宿主站点，不可启用其它站内卖货能力。';
        }

        foreach ($this->enabledInstalledIdentifiers() as $peer) {
            if ($peer === $identifier) {
                continue;
            }
            $peerManifest = $this->pluginService()->readManifest($peer);
            $peerSlots    = $this->commerceProductModeSlots($peerManifest);
            if (array_intersect($mySlots, $peerSlots) === []) {
                continue;
            }
            $peerName = $this->displayName($peerManifest, $peer);
            $myName   = $this->displayName($manifest, $identifier);

            return '站内卖货能力互斥：已启用「' . $peerName . '」，须先停用后再开「' . $myName . '」。';
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @return list<string>
     */
    private function slotKeys(?array $manifest, string $group): array
    {
        if ($manifest === null) {
            return [];
        }
        $slots = $manifest['capability_slots'] ?? null;
        if (!is_array($slots)) {
            return [];
        }
        $meta = $slots[$group] ?? null;
        if (!is_array($meta)) {
            return [];
        }
        $out = [];
        foreach ($meta as $key) {
            $key = strtolower(trim((string) $key));
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key)) {
                continue;
            }
            $out[] = $key;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @return list<string>
     */
    public function documentAddonBridgeMetaSlots(?array $manifest): array
    {
        return $this->slotKeys($manifest, self::SLOT_GROUP_DOCUMENT_ADDON_BRIDGE_META);
    }

    /** @param array<string, mixed>|null $manifest */
    public function extendsIdentifier(?array $manifest): string
    {
        if ($manifest === null) {
            return '';
        }

        return strtolower(trim((string) ($manifest['extends'] ?? '')));
    }

    public function slotLabel(string $slot): string
    {
        $slot = strtolower(trim($slot));

        return str_replace('_', ' ', $slot);
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @return list<string>
     */
    public function auditManifestSlots(?array $manifest): array
    {
        if ($manifest === null) {
            return [];
        }
        $issues = [];
        $extends = $this->extendsIdentifier($manifest);
        if ($extends !== '' && !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $extends)) {
            $issues[] = 'extends 须为合法插件 identifier';
        }
        $slots = $manifest['capability_slots'] ?? null;
        if ($slots === null) {
            return $issues;
        }
        if (!is_array($slots)) {
            $issues[] = 'capability_slots 须为对象';

            return $issues;
        }
        foreach ([
            self::SLOT_GROUP_DOCUMENT_ADDON_BRIDGE_META,
            self::SLOT_GROUP_COMMERCE_PRODUCT_MODE,
        ] as $group) {
            $meta = $slots[$group] ?? null;
            if ($meta === null) {
                continue;
            }
            if (!is_array($meta)) {
                $issues[] = 'capability_slots.' . $group . ' 须为字符串数组';
                continue;
            }
            foreach ($meta as $key) {
                $key = strtolower(trim((string) $key));
                if ($key === '' || !preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key)) {
                    $issues[] = 'capability_slots.' . $group . ' 含非法槽位：' . (string) $key;
                }
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @return list<array{peer: string, peer_name: string, slots: list<string>, slot_labels: list<string>}>
     */
    public function conflictsForEnable(string $identifier): array
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return [];
        }
        $manifest = $this->pluginService()->readManifest($identifier);
        $mySlots  = $this->documentAddonBridgeMetaSlots($manifest);
        if ($mySlots === []) {
            return [];
        }

        $conflicts = [];
        foreach ($this->enabledInstalledIdentifiers() as $peer) {
            if ($peer === $identifier) {
                continue;
            }
            $peerManifest = $this->pluginService()->readManifest($peer);
            $peerSlots    = $this->documentAddonBridgeMetaSlots($peerManifest);
            $overlap      = array_values(array_intersect($mySlots, $peerSlots));
            if ($overlap === []) {
                continue;
            }
            $labels = [];
            foreach ($overlap as $slot) {
                $labels[] = $this->slotLabel($slot);
            }
            $conflicts[] = [
                'peer'        => $peer,
                'peer_name'   => $this->displayName($peerManifest, $peer),
                'slots'       => $overlap,
                'slot_labels' => $labels,
            ];
        }

        return $conflicts;
    }

    /**
     * 启用前：存在文档扩展槽冲突且未确认时返回错误结果；否则返回 null。
     */
    public function enableGuardResult(string $identifier, bool $ackSlotConflict): ?ServiceResult
    {
        if (!(bool) config('plugin.security.capability_slot_conflict_warn_on_enable', true)) {
            return null;
        }
        if ($ackSlotConflict) {
            return null;
        }
        $conflicts = $this->conflictsForEnable($identifier);
        if ($conflicts === []) {
            return null;
        }

        $parts = [];
        foreach ($conflicts as $row) {
            $labels = implode('、', $row['slot_labels']);
            $parts[] = '「' . $row['peer_name'] . '」（' . $row['peer'] . '）· ' . $labels;
        }

        return ServiceResult::fail(
            '站点已启用同类型能力插件，继续开启可能在文档编辑、搜索或支付链路上冲突：' . implode('；', $parts),
            ApiErrorCode::CONFLICT,
            [
                'slot_conflict_required' => 1,
                'conflicts'              => $conflicts,
            ],
        );
    }

    /** @return list<string> */
    private function enabledInstalledIdentifiers(): array
    {
        $out = [];
        foreach (Plugin::where('installed', 1)->where('enabled', 1)->order('id', 'asc')->column('identifier') as $id) {
            $id = strtolower(trim((string) $id));
            if ($id !== '' && $this->pluginService()->isEnabled($id)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function pluginService(): PluginService
    {
        return app(PluginService::class);
    }

    private function hostOfficialEditionAllowed(): bool
    {
        return PluginDistributionPolicy::bundlePresent()
            && in_array(app(PivarkEditionService::class)->edition(), [
                PivarkEditionService::PLATFORM,
                PivarkEditionService::DEV,
            ], true);
    }

    /** @param array<string, mixed>|null $manifest */
    private function displayName(?array $manifest, string $fallbackId): string
    {
        $name = trim((string) ($manifest['name'] ?? ''));

        return $name !== '' ? $name : $fallbackId;
    }
}
