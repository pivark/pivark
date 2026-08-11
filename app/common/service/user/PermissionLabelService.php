<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\user;

use app\common\service\plugin\PluginService;

/**
 * 权限展示标签（中文）— 角色表单、RBAC 界面
 */
class PermissionLabelService
{
    public function __construct(
        private readonly PluginService $plugin,
    ) {
    }

    /** @var array<string, string> group key admin.{module} → 分组标题 */
    private const GROUP_LABELS = [
        'admin.dashboard' => '控制台',
        'admin.user'      => '账号管理',
        'admin.role'      => '角色管理',
        'admin.config'    => '系统设置',
        'admin.menu'      => '菜单管理',
        'admin.document'  => '文档管理',
        'admin.tag'       => '标签管理',
        'admin.log'       => '操作日志',
        'admin.media'     => '媒体库',
        'admin.plugin'    => '插件中心',
        'admin.backup'    => '数据备份',
        'admin.sql_console' => 'SQL 控制台',
        'admin.site'      => '系统设置',
        'admin.cron'      => '定时任务',
        'admin.member'    => '会员',
        'admin.stats'     => '访问统计',
        'admin.form'      => '自定义表单',
        'admin.notice'    => '登录弹窗提醒',
        'admin.favorite'  => '点赞收藏',
        'admin.seo'       => 'SEO',
        'admin.item'      => '产品中心',
        'admin.cockpit'   => '运营驾驶舱',
    ];

    /** @var array<string, string> permission code → 中文名称 */
    private const CODE_LABELS = [
        'admin.dashboard' => '控制台',

        'admin.user'         => '账号管理',
        'admin.user.list'    => '账号列表',
        'admin.user.create'  => '新增用户',
        'admin.user.edit'    => '编辑用户',
        'admin.user.delete'  => '删除用户',
        'admin.user.export'  => '导出用户',
        'admin.user.import'  => '导入用户',

        'admin.role'        => '角色管理',
        'admin.role.list'   => '角色列表',
        'admin.role.create' => '新增角色',
        'admin.role.edit'   => '编辑角色',
        'admin.role.delete' => '删除角色',

        'admin.config' => '系统设置',

        'admin.menu'        => '菜单管理',
        'admin.menu.list'   => '菜单列表',
        'admin.menu.create' => '新增菜单',
        'admin.menu.edit'   => '编辑菜单',
        'admin.menu.delete' => '删除菜单',

        'admin.document'        => '文档管理',
        'admin.document.list'   => '文档列表',
        'admin.document.create' => '新增文档',
        'admin.document.edit'   => '编辑文档',
        'admin.document.delete' => '删除文档',
        'admin.document.export' => '导出文档',
        'admin.document.import' => '导入文档',

        'admin.tag'        => '标签管理',
        'admin.tag.list'   => '标签列表',
        'admin.tag.create' => '新增标签',
        'admin.tag.edit'   => '编辑标签',
        'admin.tag.delete' => '删除标签',
        'admin.tag.merge'  => '合并标签',

        'admin.log'      => '操作日志',
        'admin.log.list' => '日志列表',

        'admin.media'      => '媒体库',
        'admin.media.list' => '媒体列表',

        'admin.plugin'        => '插件中心',
        'admin.plugin.list'   => '插件中心 · 浏览',
        'admin.plugin.manage' => '插件中心 · 运维',

        'admin.backup'        => '数据备份',
        'admin.backup.list'   => '备份列表',
        'admin.backup.create' => '创建备份',
        'admin.sql_console'     => 'SQL 控制台',
        'admin.sql_console.run' => '执行/导入 SQL',

        'admin.site'                  => '系统设置',
        'admin.site.nav.list'         => '导航列表',
        'admin.site.nav.edit'         => '编辑导航',
        'admin.site.page.list'        => '单页列表',
        'admin.site.page.edit'        => '编辑单页',
        'admin.site.slide.list'       => '轮播列表',
        'admin.site.slide.edit'       => '编辑轮播',
        'admin.site.inquiry.list'     => '咨询列表',
        'admin.site.inquiry.edit'     => '处理咨询',
        'admin.site.domain.list'      => '域名列表',
        'admin.site.domain.edit'      => '编辑域名',
        'admin.site.link.list'        => '友情链接',
        'admin.site.link.edit'        => '编辑链接',

        'admin.stats'        => '访问统计',
        'admin.stats.list'   => '访问统计',
        'admin.stats.config' => '访问统计 · 设置',

        'admin.form'      => '自定义表单',
        'admin.form.list' => '表单列表',
        'admin.form.edit' => '自定义表单 · 设置',

        'admin.notice'                  => '登录弹窗提醒',
        'admin.notice.core_update'      => '版本更新弹窗',
        'admin.notice.form_pending'     => '新留言弹窗（独有提醒）',

        'admin.favorite'        => '点赞收藏',
        'admin.favorite.list'   => '点赞收藏',
        'admin.favorite.config' => '点赞收藏 · 设置',

        'admin.cron'      => '定时任务',
        'admin.cron.list' => '任务列表',

        'admin.seo.url'     => 'URL 规则',
        'admin.seo.sitemap' => '站点地图',
        'admin.seo.robots'  => 'Robots 规则',

        'admin.member'              => '会员',
        'admin.member.list'         => '会员列表',
        'admin.member.create'       => '新增会员',
        'admin.member.edit'         => '编辑会员',
        'admin.member.delete'       => '删除会员',
        'admin.member.export'       => '导出会员',
        'admin.member.level.list'   => '会员级别',
        'admin.member.level.create' => '新增级别',
        'admin.member.level.edit'   => '编辑级别',
        'admin.member.level.delete' => '删除级别',

        'admin.item'      => '产品中心',
        'admin.item.list' => '产品中心 · 列表',
        'admin.item.edit' => '产品中心 · 编辑',
        'admin.item.export' => '产品中心 · 导出',
        'admin.item.variant.list' => '产品中心 · 规格查看',
        'admin.item.variant.edit' => '产品中心 · 规格编辑',

        'admin.cockpit.view' => '查看经营看板',


    ];


    public function groupLabel(string $groupKey): string
    {
        $groupKey = strtolower(trim($groupKey));
        if (isset(self::GROUP_LABELS[$groupKey])) {
            return self::GROUP_LABELS[$groupKey];
        }
        if (str_starts_with($groupKey, 'plugin.')) {
            $id = substr($groupKey, 7);

            return $this->pluginIdentifierLabel($id);
        }

        return $this->humanizeIdentifier(str_replace('admin.', '', $groupKey));
    }

    public function labelForCode(string $code, ?string $fallbackName = null): string
    {
        $code = strtolower(trim($code));
        $enterprise = $this->enterprisePermissionLabel($code);
        if ($enterprise !== '') {
            return $enterprise;
        }
        if (isset(self::CODE_LABELS[$code])) {
            return self::CODE_LABELS[$code];
        }

        if (str_starts_with($code, 'plugin.')) {
            return $this->labelForPluginCode($code, $fallbackName);
        }

        $inferred = $this->inferAdminLabel($code);
        if ($inferred !== '') {
            return $inferred;
        }

        if ($fallbackName !== null && trim($fallbackName) !== '' && $this->containsCjk(trim($fallbackName))) {
            return trim($fallbackName);
        }

        return $fallbackName !== null && trim($fallbackName) !== ''
            ? trim($fallbackName)
            : $this->humanizeIdentifier(str_replace('.', ' ', $code));
    }

    /**
     * @return array<string, string> code → 中文标签（迁移脚本用）
     */
    public function allKnownCodeLabels(): array
    {
        return self::CODE_LABELS;
    }

    private function labelForPluginCode(string $code, ?string $fallbackName): string
    {
        if (str_ends_with($code, '.use')) {
            $base = $this->pluginDisplayName($code, $fallbackName);

            return $base . ' · 使用';
        }
        if (str_ends_with($code, '.manage')) {
            $base = $this->pluginDisplayName($code, $fallbackName);

            return $base . ' · 使用';
        }
        if (str_ends_with($code, '.settings')) {
            $base = $this->pluginDisplayName($code, $fallbackName);

            return $base . ' · 设置';
        }
        if (str_ends_with($code, '.list')) {
            $base = $this->pluginDisplayName($code, $fallbackName);

            return $base . ' · 列表';
        }
        if (str_ends_with($code, '.edit')) {
            $base = $this->pluginDisplayName($code, $fallbackName);

            return $base . ' · 设置';
        }
        $actionLabels = [
            'project' => '招标项目',
            'export'  => '导出文档',
            'view'    => '查看',
        ];
        $rest     = substr($code, 7);
        $dot      = strpos($rest, '.');
        $suffix   = $dot === false ? '' : substr($rest, $dot + 1);
        if ($suffix !== '' && isset($actionLabels[$suffix])) {
            $base = $this->pluginDisplayName($code, $fallbackName);

            return $base . ' · ' . $actionLabels[$suffix];
        }
        if ($fallbackName !== null && trim($fallbackName) !== '') {
            return $this->normalizeFallbackName(trim($fallbackName));
        }

        return $this->pluginIdentifierLabel(str_replace('plugin.', '', $code));
    }

    private function pluginDisplayName(string $code, ?string $fallbackName): string
    {
        if ($fallbackName !== null && trim($fallbackName) !== '') {
            $name = $this->normalizeFallbackName(trim($fallbackName));

            return $name;
        }
        $rest = substr($code, 7);
        $dot  = strpos($rest, '.');
        $id   = $dot === false ? $rest : substr($rest, 0, $dot);

        return $this->pluginIdentifierLabel($id);
    }

    private function normalizeFallbackName(string $name): string
    {
        $name = preg_replace('/\s*[·•]\s*(Manage|Settings|管理|设置|List|列表)$/ui', '', $name) ?? $name;

        return trim($name);
    }

    private function pluginIdentifierLabel(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        $manifest = $this->plugin->readManifest($identifier);
        if ($manifest !== null) {
            $name = trim((string) ($manifest['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return $this->humanizeIdentifier(str_replace(['_', '-'], ' ', $identifier));
    }

    private function inferAdminLabel(string $code): string
    {
        if (!str_starts_with($code, 'admin.')) {
            return '';
        }
        $parts = explode('.', $code);
        if (count($parts) < 3) {
            return $this->groupLabel($code);
        }
        $groupKey = $parts[0] . '.' . $parts[1];
        $action   = $parts[count($parts) - 1];
        $group    = $this->groupLabel($groupKey);

        return match ($action) {
            'list'   => $group . ' · 列表',
            'create' => $group . ' · 新增',
            'edit'   => $group . ' · 编辑',
            'delete' => $group . ' · 删除',
            'config' => $group . ' · 设置',
            'manage' => $group . ' · 管理',
            'export' => $group . ' · 导出',
            'import' => $group . ' · 导入',
            'merge'  => $group . ' · 合并',
            'view'   => $group . ' · 查看',
            default  => $group . ' · ' . $this->humanizeIdentifier($action),
        };
    }

    private function humanizeIdentifier(string $value): string
    {
        $value = str_replace(['_', '-'], ' ', strtolower(trim($value)));

        return $value;
    }

    private function containsCjk(string $value): bool
    {
        return (bool) preg_match('/[\x{4e00}-\x{9fff}]/u', $value);
    }

    private function enterprisePermissionLabel(string $code): string
    {
        static $map = null;
        if ($map === null) {
            $path = dirname(__DIR__, 3) . '/config/enterprise/plugin_permissions.php';
            $map  = [];
            if (is_readable($path)) {
                /** @var array<string, mixed> $registry */
                $registry = require $path;
                $rows     = $registry['permissions'] ?? [];
                foreach ($rows as $permCode => $meta) {
                    if (is_array($meta) && isset($meta['label'])) {
                        $map[(string) $permCode] = (string) $meta['label'];
                    }
                }
            }
        }

        return $map[$code] ?? '';
    }
}
