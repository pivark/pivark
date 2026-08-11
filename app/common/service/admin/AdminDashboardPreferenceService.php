<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin;
use app\common\service\admin\AdminDashboardService;


use app\common\service\config\ConfigService;
use app\common\model\Config;

/** 后台首页控制台 — 按管理员账号保存展示偏好 */
class AdminDashboardPreferenceService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    private function dashboard(): AdminDashboardService
    {
        return app(AdminDashboardService::class);
    }

    private const KEY_PREFIX = 'admin_dashboard_pref_';

    /** @var list<string> */
    public const HOME_TEMPLATES = ['classic', 'insight', 'aurora'];

    /**
     * @return array{
     *   homeTemplate: string,
     *   showWelcomeToolbar: bool,
     *   overviewKeys: list<string>,
     *   shortcutsCommon: list<string>,
     *   shortcutsApps: list<string>
     * }
     */
    public function get(int $userId): array
    {
        if ($userId < 1) {
            return $this->defaults();
        }

        $raw = $this->configService->get($this->configKey($userId), '');
        if (!is_string($raw) || trim($raw) === '') {
            return $this->defaults();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->defaults();
        }

        return $this->normalize($decoded);
    }

    /**
     * @param array<string, mixed> $prefs
     */
    public function save(int $userId, array $prefs): void
    {
        if ($userId < 1) {
            return;
        }

        $normalized = $this->normalize($prefs);
        Config::setValue($this->configKey($userId), json_encode($normalized, JSON_UNESCAPED_UNICODE));
        $this->configService->forgetRequestCache();
    }

  /**
     * @return array{
     *   homeTemplate: string,
     *   showWelcomeToolbar: bool,
     *   overviewKeys: list<string>,
     *   shortcutsCommon: list<string>,
     *   shortcutsApps: list<string>
     * }
     */
    public function defaults(): array
    {
        $overviewKeys = [];
        foreach ($this->dashboard()->overviewStatDefinitions() as $def) {
            if (!empty($def['defaultVisible'])) {
                $overviewKeys[] = (string) $def['key'];
            }
        }

        $commonIds = [];
        foreach ($this->dashboard()->shortcutCatalogCommon() as $item) {
            $commonIds[] = (string) $item['id'];
            if (count($commonIds) >= 8) {
                break;
            }
        }

        $appsIds = [];
        foreach ($this->dashboard()->shortcutCatalogApps() as $item) {
            $appsIds[] = (string) $item['id'];
        }

        return [
            'homeTemplate'        => 'classic',
            'showWelcomeToolbar'  => true,
            'overviewKeys'        => $overviewKeys,
            'shortcutsCommon'   => $commonIds,
            'shortcutsApps'     => $appsIds,
        ];
    }

    /**
     * @param array<string, mixed> $prefs
     * @return array{
     *   homeTemplate: string,
     *   showWelcomeToolbar: bool,
     *   overviewKeys: list<string>,
     *   shortcutsCommon: list<string>,
     *   shortcutsApps: list<string>
     * }
     */
    public function normalize(array $prefs): array
    {
        $defaults = $this->defaults();
        $allowedOverview = $this->dashboard()->overviewKeySet();
        $allowedCommon   = $this->dashboard()->shortcutIdSet('common');
        $allowedApps     = $this->dashboard()->shortcutIdSet('apps');

        $homeTemplate = (string) ($prefs['homeTemplate'] ?? $defaults['homeTemplate']);
        if (!in_array($homeTemplate, self::HOME_TEMPLATES, true)) {
            $homeTemplate = $defaults['homeTemplate'];
        }

        $showWelcomeToolbar = $prefs['showWelcomeToolbar'] ?? $defaults['showWelcomeToolbar'];
        if (!is_bool($showWelcomeToolbar)) {
            $showWelcomeToolbar = filter_var($showWelcomeToolbar, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }
        if ($showWelcomeToolbar === null) {
            $showWelcomeToolbar = $defaults['showWelcomeToolbar'];
        }

        $overviewKeys = $this->filterKeys($prefs['overviewKeys'] ?? null, $allowedOverview);
        if ($overviewKeys === []) {
            $overviewKeys = $defaults['overviewKeys'];
        }

        $shortcutsCommon = $this->filterKeys($prefs['shortcutsCommon'] ?? null, $allowedCommon);
        if ($shortcutsCommon === []) {
            $shortcutsCommon = $defaults['shortcutsCommon'];
        }

        $shortcutsApps = $this->filterKeys($prefs['shortcutsApps'] ?? null, $allowedApps);

        return [
            'homeTemplate'       => $homeTemplate,
            'showWelcomeToolbar' => $showWelcomeToolbar,
            'overviewKeys'       => $overviewKeys,
            'shortcutsCommon' => $shortcutsCommon,
            'shortcutsApps'   => $shortcutsApps,
        ];
    }

    private function configKey(int $userId): string
    {
        return self::KEY_PREFIX . $userId;
    }

    /**
     * @param mixed $raw
     * @param array<string, true> $allowed
     * @return list<string>
     */
    private function filterKeys(mixed $raw, array $allowed): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $id = (string) $key;
            if ($id !== '' && isset($allowed[$id]) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
