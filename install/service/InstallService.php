<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace install\service;

use install\service\InstallCompletionLinksService;
use install\service\InstallEnvironmentCheck;
use install\service\InstallEnhancementPackService;
use install\service\InstallDatabaseService;
use install\service\InstallStepService;
use app\common\service\upload\UploadDirProtectService;
use app\common\support\InstallGate;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;
use app\common\support\SiteEnv;

/** 装站一次性：向导门面；装完可随 install/ 删除 */
class InstallService
{

    public function __construct(
        private readonly InstallDatabaseService $installDatabaseService,
        private readonly InstallStepService $installStepService,
        private readonly InstallCompletionLinksService $installCompletionLinksService,
    ) {
    }

    /**
     * 检测当前 Web 服务器类型
     * @return string apache|nginx|iis|php-builtin|unknown
     */
    public function detectWebServer(string $serverSoftware = ''): string
    {
        $sapi = php_sapi_name();
        
        if ($sapi === 'cli-server' || $sapi === 'cli') {
            return 'php-builtin';
        }
        
        $software = $serverSoftware;
        
        if (stripos($software, 'Apache') !== false) {
            return 'apache';
        }
        
        if (stripos($software, 'Nginx') !== false) {
            return 'nginx';
        }
        
        if (stripos($software, 'IIS') !== false || stripos($software, 'Microsoft-IIS') !== false) {
            return 'iis';
        }
        
        if (stripos($software, 'LiteSpeed') !== false) {
            return 'litespeed';
        }
        
        return 'unknown';
    }

    /**
     * 获取 Web 服务器信息
     * @return array{server:string, software:string, sapi:string, recommend:string}
     */
    public function getServerInfo(string $serverSoftware = 'Unknown'): array
    {
        $server = $this->detectWebServer($serverSoftware);
        $software = $serverSoftware !== '' ? $serverSoftware : 'Unknown';
        $sapi = php_sapi_name();
        
        $recommend = match($server) {
            'apache' => '.htaccess',
            'nginx' => 'nginx.conf',
            'iis' => 'web.config',
            'php-builtin' => 'router.php',
            default => 'auto-detect',
        };
        
        return [
            'server' => $server,
            'software' => $software,
            'sapi' => $sapi,
            'recommend' => $recommend,
        ];
    }

    /**
     * 生成 Web 服务器配置
     * @param string $type apache|nginx|iis
     * @return string
     */
    public function generateServerConfig(string $type, string $domain = 'localhost'): string
    {
        $domain = $domain !== '' ? $domain : 'localhost';
        $docRoot = str_replace('\\', '/', ROOT_PATH);
        $publicDir = $docRoot . 'public';
        
        return match($type) {
            'nginx' => \install\support\InstallEditionRules::baotaRewriteSnippet($docRoot),
            'nginx_full' => $this->generateNginxFullServerConfig($domain, $docRoot, $publicDir),
            'apache' => $this->generateApacheConfig($docRoot),
            'iis' => $this->generateIisConfig($docRoot),
            default => '',
        };
    }

    /** 裸机 Nginx 完整 server{}（高级）；宝塔用户用 baotaRewriteSnippet */
    private function generateNginxFullServerConfig(string $domain, string $docRoot, string $publicDir): string
    {
        unset($publicDir);

        return <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$domain};
    root {$docRoot};
    index index.php index.html;

    location ~ /\\.env {
        deny all;
    }
    location ~ /\\.git {
        deny all;
    }
    location ~ ^/(runtime|data)(/|\$) {
        deny all;
    }
    location ~ ^/(composer\\.(json|lock)|package(-lock)?\\.json)\$ {
        deny all;
    }

{$this->nginxUploadProtectBlock()}

    # 会员主题：/static/theme/member → template/member
    location ^~ /static/theme/member/ {
        alias {$docRoot}template/member/;
        access_log off;
    }

    location = / {
        try_files /public/index.html /index.php?/;
    }

    location /public/ {
        expires 30d;
        access_log off;
    }

    location / {
        try_files \$uri \$uri/ /public\$uri /index.php?/\$uri;
    }

    location ~ \\.php\$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }

    location ~ /\\. {
        deny all;
    }
}
NGINX;
    }

    private function generateApacheConfig(string $docRoot): string
    {
        unset($docRoot);

        // SSOT：安装完成后的动态入口（非伪静态美化）
        return \install\support\InstallEditionRules::postInstallDynamicRootHtaccessBody();
    }

    private function generateIisConfig(string $docRoot): string
    {
        unset($docRoot);

        return \app\common\service\site\SiteUrlRewriteGuideService::iisWebConfigBody();
    }

    /**
     * 保存配置文件到指定位置
     * @param string $type apache|nginx|iis
     * @param string $path 保存路径
     * @return bool
     */
    public function saveServerConfig(string $type, string $path): bool
    {
        $config = $this->generateServerConfig($type);
        if ($config === '') {
            return false;
        }
        
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return false;
            }
        }
        
        return file_put_contents($path, $config, LOCK_EX) !== false;
    }

    /**
     * 安装向导环境报告（Web 页 + /install/check）
     *
     * @return array{
     *   checks:list<array<string,mixed>>,
     *   meta:array<string,mixed>,
     *   baota_steps:list<string>,
     *   missing_required:list<string>
     * }
     */
    public function environmentReport(): array
    {
        return app(InstallEnvironmentCheck::class)->report();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function runInstallWizard(array $payload): ServiceResult
    {
        return $this->finalize($payload);
    }

    /**
     * Community 安装向导：官方内容增强包可选项（供 view 渲染）
     *
     * @return list<array<string, mixed>>
     */
    public function enhancementPackCatalog(): array
    {
        return app(InstallEnhancementPackService::class)->catalogForWizard();
    }

    /**
     * Community 安装向导：开源许可与使用声明（第 4 步勾选 SSOT）
     *
     * @return array{title:string,intro:string,bullets:list<string>,checkbox_label:string,edition:string}
     */
    public function communityLicenseTerms(): array
    {
        return [
            'title'           => '开源许可与使用声明（Community 版）',
            'intro'           => '继续安装前，请阅读并确认以下条款。勾选并点击「开始安装」即表示你代表部署方接受本声明。',
            'edition'         => 'community',
            'checkbox_label'  => '我已阅读并同意上述开源许可与使用声明',
            'bullets'         => [
                '本发行包为 PivArk Community（开源版），遵循发行包根目录 LICENSE（PivArk Community 开源许可），可用于学习、部署与商业建站。',
                '部署与二次开发须保留产品版权信息（PivArk / pivark.com）；隐藏版权、替换品牌标识需购买商业授权。商业授权授予约定范围内的使用权，不构成源码著作权转让。',
                '安装包仅含通用建站内核与示范插件；官网运营、授权管理等平台能力不在本包内。',
                '从插件市场安装的官方/第三方插件，须分别遵守其许可与商用条款；不得破解、绕过或非法分发加密插件包。',
                '你对服务器安全、数据备份、账号权限与对外隐私政策/用户协议承担部署方责任；因错误暴露或弱口令导致的损失由部署方自行承担。',
                '更多版本权益与授权说明见 pivark.com；购买商业授权后可获得去版权、核心在线升级与技术支持等能力。',
            ],
        ];
    }

    public function hasAcceptedCommunityLicenseTerms(array $payload): bool
    {
        $raw = $payload['accept_license_terms'] ?? $payload['accept_terms'] ?? null;
        if (is_bool($raw)) {
            return $raw;
        }
        $val = strtolower(trim((string) $raw));

        return in_array($val, ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * @return list<array{key:string,label:string,ok:bool,detail:string}>
     */
    public function checkEnvironment(): array
    {
        $checks = [
            ['key' => 'php', 'label' => 'PHP >= 8.1', 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'detail' => PHP_VERSION],
            ['key' => 'pdo', 'label' => 'PDO MySQL', 'ok' => extension_loaded('pdo_mysql'), 'detail' => extension_loaded('pdo_mysql') ? 'ok' : 'missing'],
            ['key' => 'json', 'label' => 'JSON', 'ok' => extension_loaded('json'), 'detail' => 'ok'],
            ['key' => 'gd', 'label' => 'GD', 'ok' => extension_loaded('gd'), 'detail' => extension_loaded('gd') ? 'ok' : 'missing'],
            ['key' => 'data', 'label' => 'data/ 可写', 'ok' => $this->isWritableDir(ROOT_PATH . 'data'), 'detail' => ROOT_PATH . 'data'],
            ['key' => 'runtime', 'label' => 'data/runtime/ 可写', 'ok' => $this->isWritableDir(ROOT_PATH . 'data/runtime'), 'detail' => ROOT_PATH . 'data/runtime'],
        ];

        return $checks;
    }

    /**
     * @param array<string, string> $config
     * @return mixed
     */
    public function testDatabase(array $config): ServiceResult
    {
        return $this->installDatabaseService->testDatabase($config);
    }

    /**
     * @param array<string, string> $config
     * @return array{table_count:int,sample_tables:list<string>,needs_overwrite:bool}
     */
    public function inspectPrefixedTables(array $config): array
    {
        return $this->installDatabaseService->inspectPrefixedTables($config);
    }

    public function hasOverwriteConfirmation(array $payload): bool
    {
        $raw = $payload['confirm_overwrite'] ?? null;
        if (\is_bool($raw)) {
            return $raw;
        }
        $val = strtolower(trim((string) $raw));

        return \in_array($val, ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * @return list<string>
     */
    public function installStepOrder(): array
    {
        return $this->installStepService->installStepOrder();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function runInstallStep(array $payload, string $step): ServiceResult
    {
        return $this->installStepService->runInstallStep($payload, $step);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function finalize(array $payload): ServiceResult
    {
        $loginUrl = '';
        foreach ($this->installStepOrder() as $step) {
            if ($step === 'migrate') {
                $result = $this->installDatabaseService->runAllMigrationsViaCli();
                if (!$result->isOk()) {
                    return $result;
                }
                continue;
            }
            if ($step === 'schema' || $step === 'demo') {
                $guard = 0;
                while ($guard++ < 500) {
                    $result = $this->runInstallStep($payload, $step);
                    if (!$result->isOk()) {
                        return $result;
                    }
                    $data = $result->dataArray();
                    if (!empty($data['url'])) {
                        $loginUrl = (string) $data['url'];
                    }
                    if (!empty($data['done'])) {
                        break;
                    }
                }
                continue;
            }
            $result = $this->runInstallStep($payload, $step);
            if (!$result->isOk()) {
                return $result;
            }
            $data = $result->dataArray();
            if (!empty($data['url'])) {
                $loginUrl = (string) $data['url'];
            }
        }

        return ServiceResult::ok(
            ['url' => $loginUrl !== '' ? $loginUrl : $this->installStepService->buildAdminLoginUrlFromPayload($payload)],
            '安装完成',
        );
    }

    /**
     * @param array<string, string> $config
     */
    public function writeEnv(array $config): void
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

    /**
     * @return list<string>
     */
    public function runSchemaSetup(): array
    {
        return $this->installDatabaseService->runSchemaSetup();
    }

    /**
     * 安装成功页 payload（finish 步骤亦返回同结构）
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function buildCompletionPayload(array $payload): array
    {
        return $this->installStepService->buildCompletionPayload($payload);
    }

    /**
     * 渠道商可在 install/channel.json 覆盖安装页文案（复制 channel.example.json）
     *
     * @return array{title:string,subtitle:string,footer_html:string,support_url:string,logo_url:string}
     */
    public function channelBranding(): array
    {
        $defaults = [
            'title'       => '元舟 PivArk 安装向导',
            'subtitle'    => '企业全域原子化数字资产中枢 · Community 开源版',
            'footer_html' => '',
            'support_url' => 'https://help.pivark.cn',
            'logo_url'    => '',
        ];
        $file = ProjectPaths::installChannelConfigFile();
        if (!is_readable($file)) {
            return $defaults;
        }
        $raw = json_decode((string) file_get_contents($file), true);
        if (!is_array($raw)) {
            return $defaults;
        }
        foreach (['title', 'subtitle', 'footer_html', 'support_url', 'logo_url'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                $defaults[$key] = trim($raw[$key]);
            }
        }

        return $defaults;
    }

    /**
     * @return list<array{label:string,hint:string,url?:string,url_cn?:string,url_com?:string}>
     */
    public function completionExternalLinkDefinitions(): array
    {
        return $this->installCompletionLinksService->completionExternalLinkDefinitions();
    }

    /**
     * @return list<array{label:string,url:string,hint:string,url_cn?:string,url_com?:string,picked?:string}>
     */
    public function completionExternalLinks(): array
    {
        return $this->installCompletionLinksService->completionExternalLinks();
    }

    /**
     * @return list<array{label:string,url:string,hint:string,picked:string,url_cn?:string,url_com?:string}>
     */
    public function resolveCompletionExternalLinks(): array
    {
        return $this->installCompletionLinksService->resolveCompletionExternalLinks();
    }

    /** 打开 /install 且未锁定时：重装只需删 install.lock，运行时缓存在此自动清 */
    public function prepareReinstallWizard(): void
    {
        if (InstallGate::isInstalled()) {
            return;
        }
        $this->installStepService->clearInstallRuntimeCachesForReinstall();
    }

    /**
     * @return array{db_host:string,db_port:string,db_name:string,db_user:string,db_pass:string,db_prefix:string}
     */
    public function readExistingDatabaseDefaults(): array
    {
        return $this->installDatabaseService->readExistingDatabaseDefaults();
    }

    public function hasPriorInstallArtifacts(): bool
    {
        if (SiteEnv::resolvePath(ROOT_PATH) !== null) {
            return true;
        }

        return $this->readExistingDatabaseDefaults()['db_name'] !== '';
    }

    private function isWritableDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return LocalFile::mkdirIfMissing($dir);
        }

        return is_writable($dir);
    }

    private function nginxUploadProtectBlock(): string
    {
        return app(UploadDirProtectService::class)->nginxLocationSnippet();
    }
}