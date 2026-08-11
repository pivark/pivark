<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace install\service;

use app\common\model\Role;
use app\common\model\User;
use app\common\model\UserRole;
use app\common\service\config\ConfigService;
use app\common\service\license\LicenseHeartbeatService;
use app\common\service\plugin\security\PluginEmergencyTokenService;
use app\common\service\plugin\PluginService;
use app\common\service\site\AdminEntryAliasService;
use app\common\service\site\SiteKeyService;
use app\common\service\upload\UploadDirProtectService;
use app\common\support\AppTime;
use install\support\InstallEditionRules;
use install\service\InstallCompletionLinksService;
use install\service\InstallEnvironmentCheck;
use install\service\InstallEnhancementPackService;
use app\common\support\InstallGate;
use app\common\support\LocalFile;
use app\common\support\OpsLog;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;
use install\service\InstallDatabaseService;
use install\service\InstallSeedService;
use app\common\support\SiteEnv;

/** 装站一次性：分步执行（wipe → finish）；装完可随 install/ 删除 */
final class InstallStepService
{

    public function __construct(
        private readonly InstallDatabaseService $installDatabaseService,
        private readonly InstallSeedService $installSeedService,
        private readonly InstallCompletionLinksService $installCompletionLinksService,
        private readonly SiteKeyService $siteKeyService,
        private readonly PluginService $pluginService,
        private readonly LicenseHeartbeatService $licenseHeartbeatService,
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * @return list<string>
     */
    public function installStepOrder(): array
    {
        return ['wipe', 'env', 'schema', 'migrate', 'admin', 'site', 'plugins', 'demo', 'finish'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function runInstallStep(array $payload, string $step): ServiceResult
    {
        if (InstallGate::isInstalled()) {
            return ServiceResult::fail('系统已安装；重装请删除 data/install.lock 后访问 /install（向导会自动覆盖 site.env 与清缓存）');
        }

        $step = strtolower(trim($step));
        if (!\in_array($step, $this->installStepOrder(), true)) {
            return ServiceResult::fail('无效的安装步骤');
        }

        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->markInstallWizardContext($payload);

        $db = $this->extractDbConfig($payload);
        if ($db['db_name'] === '' || $db['db_user'] === '') {
            return ServiceResult::fail('请填写数据库名和用户名');
        }

        if ($step === 'wipe' || $step === 'env') {
            $pre = $this->validateInstallPrerequisites($payload, $db);
            if (!$pre->isOk()) {
                return $pre;
            }
        }

        try {
            return match ($step) {
                'wipe'    => $this->installStepWipe($payload, $db),
                'env'     => $this->installStepEnv($db),
                'schema'  => $this->installStepSchema(),
                'migrate' => $this->installStepMigrate(),
                'admin'   => $this->installStepAdmin($payload),
                'site'    => $this->installStepSite($payload),
                'plugins' => $this->installStepPlugins($payload),
                'demo'    => $this->installStepDemo($payload),
                'finish'  => $this->installStepFinish($payload),
            };
        } catch (\Throwable $e) {
            return ServiceResult::fail($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function buildAdminLoginUrlFromPayload(array $payload): string
    {
        $siteUrl = $this->resolveInstallSiteUrl($payload);
        $raw = strtolower(trim((string) ($payload['admin_entry'] ?? 'admin')));
        if ($raw === '' || $raw === 'admin') {
            $basePath = '/admin/index.php';
        } else {
            $normalized = app(AdminEntryAliasService::class)->normalizeInput($raw);
            $basePath = $normalized !== '' ? '/' . $normalized . '/index.php' : '/admin/index.php';
        }

        return rtrim($siteUrl, '/') . $basePath . '/auth/login';
    }

    /**
     * 安装成功页：站内入口 + 官网/文档/演示外链 + 可删除目录说明
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildCompletionPayload(array $payload): array
    {
        $adminLogin = $this->buildAdminLoginUrlFromPayload($payload);
        $siteHome   = rtrim($this->resolveInstallSiteUrl($payload), '/') . '/';

        return [
            'url'            => $adminLogin,
            'site_home'      => $siteHome,
            'site_name'      => trim((string) ($payload['site_name'] ?? '元舟 PivArk')),
            'install_dir'    => 'install',
            'removable_dirs' => [
                [
                    'path' => 'install/assets/packages',
                    'hint' => '增强包 zip（已解压到 weapp/；安装完成时已自动删除 zip 文件）',
                ],
                [
                    'path' => 'install/assets/seed',
                    'hint' => '演示数据脚本（未勾选导入时可忽略；勾选后已执行完毕）',
                ],
                [
                    'path' => 'install/assets',
                    'hint' => '安装专用资源目录',
                ],
                [
                    'path' => 'install',
                    'hint' => '整个安装向导（删后无法通过 /install 重装，需重新上传该目录或整包）',
                ],
            ],
            'external_links' => $this->installCompletionLinksService->resolveCompletionExternalLinks(),
            'baota_paste_steps' => InstallEditionRules::baotaPasteStepsZh(),
            'baota_conf_hint'   => '装完点「进入管理后台」即可（/admin/index.php）。需要前台短链时：Nginx 粘宝塔伪静态；Apache 一般已自动写 .htaccess；IIS 下载 web.config 并装 URL Rewrite。',
            'rewrite_guides'    => [
                'nginx'  => InstallEditionRules::rewriteSetupGuideZh('nginx'),
                'apache' => InstallEditionRules::rewriteSetupGuideZh('apache'),
                'iis'    => InstallEditionRules::rewriteSetupGuideZh('iis'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $db
     */
    private function validateInstallPrerequisites(array $payload, array $db): ServiceResult
    {
        if (!$this->hasAcceptedCommunityLicenseTerms($payload)) {
            return ServiceResult::fail('请先勾选同意开源许可与使用声明');
        }
        foreach (app(InstallEnvironmentCheck::class)->buildChecks() as $row) {
            if (!$row['ok'] && \in_array($row['key'], ['php', 'pdo', 'data', 'runtime'], true)) {
                return ServiceResult::fail('环境检查未通过：' . $row['label']);
            }
        }
        $test = $this->installDatabaseService->testDatabase($db);
        if (!$test->isOk()) {
            return $test;
        }
        if ($this->installDatabaseService->databaseHasPrefixedTables($db) && !$this->hasOverwriteConfirmation($payload)) {
            $testData = $test->dataArray();
            $count = (int) ($testData['table_count'] ?? 0);
            if ($count === 0) {
                $count = $this->installDatabaseService->inspectPrefixedTables($db)['table_count'];
            }

            return ServiceResult::fail(
                '数据库中已存在 ' . $count . ' 张带前缀的表，请返回最后一步勾选「确认清空并覆盖」后再安装',
            );
        }

        return ServiceResult::ok(null);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $db
     */
    private function installStepWipe(array $payload, array $db): ServiceResult
    {
        $this->clearInstallRuntimeCaches();
        $this->installDatabaseService->clearSchemaProgressJob();
        $this->installSeedService->clearDemoProgressJob();
        $this->removeInstallLockIfExists();
        if (!$this->installDatabaseService->databaseHasPrefixedTables($db)) {
            return ServiceResult::ok(['dropped' => 0], '数据库为空，跳过清空');
        }
        if (!$this->hasOverwriteConfirmation($payload)) {
            return ServiceResult::fail('请先勾选确认清空并覆盖现有数据');
        }
        $dropped = $this->installDatabaseService->wipePrefixedTables($db);

        return ServiceResult::ok(['dropped' => $dropped], '已清空 ' . $dropped . ' 张旧表');
    }

    /**
     * @param array<string, string> $db
     */
    private function installStepEnv(array $db): ServiceResult
    {
        $this->writeEnv($db);

        return ServiceResult::ok(null, '环境配置已写入');
    }

    private function installStepSchema(): ServiceResult
    {
        $result = $this->installDatabaseService->runSchemaSetupNext();
        if ($result->isOk()) {
            $this->reloadThinkAfterWriteEnv();
        }

        return $result;
    }

    private function installStepMigrate(): ServiceResult
    {
        return $this->installDatabaseService->runNextMigration();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function installStepAdmin(array $payload): ServiceResult
    {
        $this->reloadThinkAfterWriteEnv();
        $pass = (string) ($payload['admin_pass'] ?? '');
        $confirm = (string) ($payload['admin_pass_confirm'] ?? '');
        if (strlen($pass) < 6) {
            return ServiceResult::fail('管理员密码至少 6 位');
        }
        if ($pass !== $confirm) {
            return ServiceResult::fail('两次输入的密码不一致');
        }
        $this->upsertSuperAdmin(
            trim((string) ($payload['admin_user'] ?? 'admin')),
            $pass,
            trim((string) ($payload['admin_name'] ?? '超级管理员')),
        );

        return ServiceResult::ok(null, '超级管理员已创建');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function installStepSite(array $payload): ServiceResult
    {
        $installSiteUrl = $this->resolveInstallSiteUrl($payload);
        $this->saveSiteConfig(
            trim((string) ($payload['site_name'] ?? '元舟 PivArk')),
            trim((string) ($payload['site_theme'] ?? 'default')),
            $installSiteUrl,
        );
        $adminLoginUrl = $this->applyAdminEntryAndLoginUrl($payload, $installSiteUrl);
        $this->siteKeyService->ensure();

        return ServiceResult::ok(['url' => $adminLoginUrl], '站点配置已保存');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function installStepPlugins(array $payload): ServiceResult
    {
        $pack     = app(InstallEnhancementPackService::class);
        $optional = $pack->normalizeIdentifiersFromPayload($payload);
        $required = InstallEditionRules::installRequiredWeappIdentifiers();
        $plugins  = array_values(array_unique(array_merge($required, $optional)));
        $installed = [];
        $skipped   = [];
        $errors    = [];
        foreach ($plugins as $pid) {
            try {
                $result = $pack->installIdentifier($pid);
                if (!$result->isOk()) {
                    $errors[] = $pid . ': ' . (string) $result->message();
                    continue;
                }
                $this->pluginService->enable($pid);
                $installed[] = $pid;
            } catch (\Throwable $e) {
                $errors[] = $pid . ': ' . $e->getMessage();
            }
        }
        // 勾选/必装任一失败 → 整步失败，禁止半成功进 demo（否则 Volume 种子会缺）
        if ($errors !== []) {
            return ServiceResult::fail('插件安装失败：' . implode('；', $errors));
        }
        $optionalInstalled = array_values(array_intersect($installed, $optional));
        if ($installed === []) {
            $msg = '未安装任何插件';
        } elseif ($optionalInstalled === []) {
            $msg = '已安装并启用演示主题必需插件（' . implode('、', array_intersect($installed, $required)) . '）';
        } else {
            $msg = '已安装并启用 ' . \count($installed) . ' 个插件';
        }

        return ServiceResult::ok([
            'plugins'     => $installed,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'import_demo' => $pack->shouldImportDemo($payload),
        ], $msg);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function installStepDemo(array $payload): ServiceResult
    {
        $pack       = app(InstallEnhancementPackService::class);
        $optional   = $pack->normalizeIdentifiersFromPayload($payload);
        $required   = InstallEditionRules::installRequiredWeappIdentifiers();
        $selected   = array_values(array_unique(array_merge($required, $optional)));
        $importDemo = $pack->shouldImportDemo($payload);

        return $this->installSeedService->runNextDemoBatch($selected, $importDemo);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function installStepFinish(array $payload): ServiceResult
    {
        $this->ensureInstallRuntimeDirs();
        $this->ensureInstallAdminBackofficeRole($payload);
        // 重装后清掉旧 Session，避免浏览器带着 must_change_password=1 白屏
        $this->clearInstallRuntimeCaches();
        InstallGate::createLock();
        try {
            $this->licenseHeartbeatService->sendImmediately('install_complete');
        } catch (\Throwable $e) {
            OpsLog::businessWarning('install_complete_heartbeat_failed', ['msg' => $e->getMessage()]);
        }
        $emergencyToken = app(PluginEmergencyTokenService::class)->ensureConfigured(ROOT_PATH);
        SiteEnv::injectIntoProcessEnv(ROOT_PATH);
        $this->clearInstallWizardContext();
        $this->purgeInstallPackageZips();
        $this->writePostInstallDynamicRootHtaccess();
        $this->writeBaotaRewriteArtifacts();
        app(\app\common\service\site\AdminPhysicalEntryService::class)->ensure();

        $completion = $this->buildCompletionPayload($payload);
        $siteUrl = rtrim($this->resolveInstallSiteUrl($payload), '/');
        $completion['plugin_emergency'] = [
            'query_key' => '_pv_emergency',
            'token'     => $emergencyToken,
            'example'   => $siteUrl . '/?_pv_emergency=' . rawurlencode($emergencyToken),
            'hint'      => '后台因插件无法打开时，在浏览器地址栏任意页面 URL 后加上 ?' . '_pv_emergency=' . $emergencyToken . ' 即可暂停全部插件。',
        ];

        return ServiceResult::ok(
            $completion,
            '安装完成',
        );
    }

    public function clearInstallRuntimeCachesForReinstall(): void
    {
        $this->clearInstallRuntimeCaches();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasAcceptedCommunityLicenseTerms(array $payload): bool
    {
        $raw = $payload['accept_license_terms'] ?? $payload['accept_terms'] ?? null;
        if (is_bool($raw)) {
            return $raw;
        }
        $val = strtolower(trim((string) $raw));

        return in_array($val, ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasOverwriteConfirmation(array $payload): bool
    {
        $raw = $payload['confirm_overwrite'] ?? null;
        if (\is_bool($raw)) {
            return $raw;
        }
        $val = strtolower(trim((string) $raw));

        return \in_array($val, ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function extractDbConfig(array $payload): array
    {
        return [
            'db_host'   => trim((string) ($payload['db_host'] ?? '127.0.0.1')),
            'db_port'   => trim((string) ($payload['db_port'] ?? '3306')),
            'db_name'   => trim((string) ($payload['db_name'] ?? '')),
            'db_user'   => trim((string) ($payload['db_user'] ?? '')),
            'db_pass'   => (string) ($payload['db_pass'] ?? ''),
            'db_prefix' => trim((string) ($payload['db_prefix'] ?? 'pv_')),
        ];
    }

    private function upsertSuperAdmin(string $username, string $password, string $nickname): void
    {
        if ($username === '' || $password === '') {
            throw new \RuntimeException('请设置管理员账号和密码');
        }
        if (strlen($password) < 8) {
            throw new \RuntimeException('管理员密码至少 8 位');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $now  = AppTime::now();
        $row  = User::where('username', $username)->find();
        // 安装向导已由用户自设密码：勿再强制「改初始密码」
        if ($row) {
            User::where('id', $row['id'])->update([
                'password'             => $hash,
                'nickname'             => $nickname,
                'status'               => 1,
                'must_change_password' => 0,
                'updated_at'           => $now,
            ]);
        } else {
            User::insert([
                'username'             => $username,
                'password'             => $hash,
                'nickname'             => $nickname,
                'status'               => 1,
                'must_change_password' => 0,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }
        $uid = (int) User::where('username', $username)->value('id');
        $roleId = (int) Role::where('code', 'super_admin')->value('id');
        if ($uid < 1) {
            throw new \RuntimeException('超级管理员账号写入失败');
        }
        if ($roleId < 1) {
            throw new \RuntimeException('缺少 super_admin 角色，请检查数据库初始化是否完整');
        }
        UserRole::where('user_id', $uid)->delete();
        UserRole::insert([
            'user_id'    => $uid,
            'role_id'    => $roleId,
            'created_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function ensureInstallAdminBackofficeRole(array $payload): void
    {
        $username = trim((string) ($payload['admin_user'] ?? 'admin'));
        if ($username === '') {
            throw new \RuntimeException('安装完成校验失败：管理员用户名为空');
        }
        $this->reloadThinkAfterWriteEnv();
        $uid = (int) User::where('username', $username)->value('id');
        $roleId = (int) Role::where('code', 'super_admin')->value('id');
        if ($uid < 1 || $roleId < 1) {
            throw new \RuntimeException('安装完成校验失败：管理员账号或 super_admin 角色缺失');
        }
        $now = AppTime::now();
        UserRole::where('user_id', $uid)->delete();
        UserRole::insert([
            'user_id'    => $uid,
            'role_id'    => $roleId,
            'created_at' => $now,
        ]);
    }

    private function ensureInstallRuntimeDirs(): void
    {
        foreach (['data', 'data/runtime', 'data/runtime/session', 'data/runtime/cache', 'data/runtime/log'] as $rel) {
            $dir = rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!$this->isWritableDir($dir)) {
                throw new \RuntimeException('目录不可写：' . $rel);
            }
        }
        app(UploadDirProtectService::class)->ensurePublicUploadRoot();
        $this->ensurePublicWebAliases();
    }

    /**
     * 站点根文档根部署：补 /uploads /static 与会员主题静态别名（Nginx 短伪静态无 public 映射时自愈）。
     * 禁止造栏目 stub 目录。
     */
    private function ensurePublicWebAliases(): void
    {
        $root = rtrim(str_replace('\\', '/', ROOT_PATH), '/');
        $pairs = [
            [$root . '/public/uploads', $root . '/uploads'],
            [$root . '/public/static', $root . '/static'],
            // 静态 HTML 子目录（seo_static_subdir 默认常为 html）；装完若已有 public/html 则自愈
            [$root . '/public/html', $root . '/html'],
        ];
        foreach ($pairs as [$target, $link]) {
            $this->ensureSymlinkAlias($target, $link, 'install_web_alias');
        }

        $memberTarget = $root . '/template/member';
        $memberLink   = $root . '/public/static/theme/member';
        if (is_dir($memberTarget)) {
            $themeParent = $root . '/public/static/theme';
            if (!is_dir($themeParent) && !LocalFile::mkdirIfMissing($themeParent)) {
                OpsLog::businessWarning('install_web_alias_mkdir_failed', ['path' => $themeParent]);
            } else {
                $this->ensureSymlinkAlias($memberTarget, $memberLink, 'install_member_theme_alias');
            }
        }

        // Nginx 跟 /static→public/static 时：把 default 主题 pc/assets 映到 public/static/theme/default，避免只靠 PHP 直出撞上 error_page 404。
        $defaultAssets = $root . '/template/default/pc/assets';
        $defaultLink   = $root . '/public/static/theme/default';
        if (is_dir($defaultAssets)) {
            $themeParent = $root . '/public/static/theme';
            if (!is_dir($themeParent) && !LocalFile::mkdirIfMissing($themeParent)) {
                OpsLog::businessWarning('install_web_alias_mkdir_failed', ['path' => $themeParent]);
            } else {
                $this->ensureSymlinkAlias($defaultAssets, $defaultLink, 'install_default_theme_assets_alias');
            }
        }
    }

    private function ensureSymlinkAlias(string $target, string $link, string $logKey): void
    {
        if (!is_dir($target) && !is_file($target)) {
            return;
        }
        if (is_link($link)) {
            if (!\function_exists('readlink')) {
                return;
            }
            $current = @readlink($link);
            $currentNorm = $current !== false ? str_replace('\\', '/', $current) : '';
            $targetNorm  = str_replace('\\', '/', $target);
            if ($currentNorm === $targetNorm || str_ends_with($currentNorm, '/' . basename($targetNorm))) {
                return;
            }
            OpsLog::businessWarning($logKey . '_exists_other', [
                'link'    => $link,
                'current' => $currentNorm,
                'expect'  => $targetNorm,
            ]);

            return;
        }
        if (file_exists($link) || is_dir($link)) {
            return;
        }
        if (\function_exists('symlink')) {
            try {
                if (@\symlink($target, $link)) {
                    return;
                }
            } catch (\Throwable $e) {
                OpsLog::businessWarning($logKey . '_failed', [
                    'link'  => $link,
                    'target'=> $target,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        // 宝塔常见：disable_functions 含 symlink，但允许 exec + ln -sfn
        if ($this->tryShellSymlink($target, $link)) {
            return;
        }
        OpsLog::businessWarning($logKey . '_failed', [
            'link'   => $link,
            'target' => $target,
            'hint'   => 'symlink_disabled_or_failed_use_baota_alias',
        ]);
    }

    private function tryShellSymlink(string $target, string $link): bool
    {
        if (!\function_exists('exec') || \PHP_OS_FAMILY === 'Windows') {
            return false;
        }
        $cmd = 'ln -sfn ' . \escapeshellarg($target) . ' ' . \escapeshellarg($link);
        $out = [];
        $code = 1;
        @\exec($cmd . ' 2>&1', $out, $code);
        if ($code === 0 && (is_link($link) || is_dir($link) || is_file($link))) {
            return true;
        }
        OpsLog::businessWarning('install_web_alias_shell_ln_failed', [
            'cmd'    => $cmd,
            'code'   => $code,
            'output' => \implode("\n", $out),
        ]);

        return false;
    }

    private function saveSiteConfig(string $siteName, string $theme, string $siteUrl = ''): void
    {
        $data = [
            'site_name'      => $siteName !== '' ? $siteName : '元舟 PivArk',
            'site_theme'     => $theme !== '' ? $theme : 'default',
            'site_status'    => '1',
            // 装完默认动态；伪静态/静态由用户在后台自行切换并配服务器
            'site_url_mode'  => 'dynamic',
        ];
        if ($siteUrl !== '') {
            $data['site_url'] = $siteUrl;
        }
        $this->configService->save($data);
    }

    /** 安装完成：把发行包占位 .htaccess 换成动态入口（/admin、前台可访问；非美化伪静态） */
    private function writePostInstallDynamicRootHtaccess(): void
    {
        $path = \rtrim(ROOT_PATH, '/\\') . \DIRECTORY_SEPARATOR . '.htaccess';
        $body = InstallEditionRules::postInstallDynamicRootHtaccessBody();
        if (@\file_put_contents($path, $body) === false) {
            OpsLog::businessWarning('install_write_dynamic_htaccess_failed', ['path' => $path]);
        }
    }

    /**
     * 装完落盘宝塔伪静态同源文件：站点根 `_pv_baota_rewrite.conf` + `data/baota-rewrite.conf`
     * （面板不会自动读；给人复制粘贴，与向导「复制配置」一致）
     */
    private function writeBaotaRewriteArtifacts(): void
    {
        $root = \rtrim(str_replace('\\', '/', ROOT_PATH), '/');
        $body = InstallEditionRules::baotaRewriteSnippet($root . '/');
        $targets = [
            $root . '/_pv_baota_rewrite.conf',
            $root . '/data/baota-rewrite.conf',
        ];
        $dataDir = $root . '/data';
        if (!\is_dir($dataDir) && !LocalFile::mkdirIfMissing($dataDir)) {
            OpsLog::businessWarning('install_write_baota_rewrite_mkdir_failed', ['path' => $dataDir]);
        }
        foreach ($targets as $path) {
            if (@\file_put_contents($path, $body) === false) {
                OpsLog::businessWarning('install_write_baota_rewrite_failed', ['path' => $path]);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveInstallSiteUrl(array $payload): string
    {
        $explicit = trim((string) ($payload['site_url'] ?? ''));
        if ($explicit === '') {
            throw new \RuntimeException('请填写站点地址');
        }

        if (preg_match('#^https?://#i', $explicit)) {
            return rtrim($explicit, '/') . '/';
        }

        return 'http://' . ltrim($explicit, '/') . '/';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyAdminEntryAndLoginUrl(array $payload, string $siteUrl): string
    {
        $raw = strtolower(trim((string) ($payload['admin_entry'] ?? '')));
        if ($raw === '' && isset($payload['admin_login_url'])) {
            $legacy = trim((string) $payload['admin_login_url']);
            if ($legacy !== '' && preg_match('#/([^/]+)/auth/login#i', $legacy, $m)) {
                $raw = strtolower($m[1]);
            }
        }
        if ($raw === '') {
            $raw = 'admin';
        }

        if ($raw === 'admin') {
            $this->configService->save(['admin_entry_alias' => '']);
            $basePath = '/admin';
        } else {
            $normalized = app(AdminEntryAliasService::class)->normalizeInput($raw);
            if ($normalized === '') {
                throw new \RuntimeException('后台目录名无效：请使用字母开头，仅含字母、数字、下划线或连字符，且不能为系统保留路径');
            }
            $this->configService->save(['admin_entry_alias' => $normalized]);
            $basePath = '/' . $normalized;
        }

        return rtrim($siteUrl, '/') . $basePath . '/auth/login';
    }

    /**
     * @param array<string, string> $config
     */
    private function writeEnv(array $config): void
    {
        SiteEnv::writeCommunityInstall(ROOT_PATH, [
            'db_host'   => $config['db_host'] ?? '127.0.0.1',
            'db_port'   => $config['db_port'] ?? '3306',
            'db_name'   => $config['db_name'] ?? '',
            'db_user'   => $config['db_user'] ?? '',
            'db_pass'   => $config['db_pass'] ?? '',
            'db_prefix' => $config['db_prefix'] ?? 'pv_',
        ]);
    }

    private function removeInstallLockIfExists(): void
    {
        $path = InstallGate::lockPath();
        if (\is_file($path)) {
            LocalFile::unlinkIfExists($path);
        }
    }

    private function clearInstallRuntimeCaches(): void
    {
        $root = \rtrim(ROOT_PATH, '/\\') . \DIRECTORY_SEPARATOR . 'data' . \DIRECTORY_SEPARATOR . 'runtime' . \DIRECTORY_SEPARATOR;
        foreach (['cache', 'temp', 'session'] as $sub) {
            $this->removeRuntimeDirFiles($root . $sub);
        }
    }

    private function removeRuntimeDirFiles(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $entries = \glob($dir . \DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($entries as $entry) {
            if (\is_file($entry)) {
                @\unlink($entry);
            } elseif (\is_dir($entry)) {
                $this->removeRuntimeDirFiles($entry);
                LocalFile::rmdirIfExists($entry);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function markInstallWizardContext(array $payload = []): void
    {
        $this->setProcessEnv('PIVARK_INSTALL_WIZARD', '1');

        // 与「导入演示数据」勾选对齐：未勾选则装插件也不灌 InstallDemo 样例（空站）
        $importDemo = app(InstallEnhancementPackService::class)->shouldImportDemo($payload) ? '1' : '0';
        $this->setProcessEnv('PIVARK_INSTALL_IMPORT_DEMO', $importDemo);
    }

    private function clearInstallWizardContext(): void
    {
        $this->setProcessEnv('PIVARK_INSTALL_WIZARD', null);
        $this->setProcessEnv('PIVARK_INSTALL_IMPORT_DEMO', null);
    }

    /** 部分面板 disable_functions 含 putenv；命名空间内须 \\putenv，且可降级只写 $_ENV/$_SERVER */
    private function setProcessEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            if (\function_exists('putenv')) {
                @\putenv($key);
            }

            return;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        if (\function_exists('putenv')) {
            @\putenv($key . '=' . $value);
        }
    }

    private function purgeInstallPackageZips(): void
    {
        $dir = ProjectPaths::installPackagesDir();
        if (is_dir($dir)) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $zipPath) {
                if (is_file($zipPath)) {
                    unlink($zipPath);
                }
            }
        }
        // 站点根误留的发行包 zip（可被公网下载）— 与宝塔 deny 双保险
        $root = rtrim(str_replace('\\', '/', ROOT_PATH), '/');
        foreach (glob($root . '/pivark-*.zip') ?: [] as $zipPath) {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    private function isWritableDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return LocalFile::mkdirIfMissing($dir);
        }

        return is_writable($dir);
    }

    private function reloadThinkAfterWriteEnv(): void
    {
        $root = ProjectPaths::root();
        SiteEnv::injectIntoProcessEnv($root);
        $bootstrap = ProjectPaths::migrationsDir() . DIRECTORY_SEPARATOR . '_migration.php';
        if (\is_readable($bootstrap)) {
            require_once $bootstrap;
        }
        if (\function_exists('migration_think_app')) {
            \migration_think_app($root);
        }
        if (\class_exists(\think\facade\Db::class)) {
            \think\facade\Db::connect('mysql', true);
        }
    }
}
