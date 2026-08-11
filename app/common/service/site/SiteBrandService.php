<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;
use app\common\service\site\SiteCoreLicenseService;

use app\common\service\release\CoreUpdateRemoteService;
use app\common\service\release\PivarkEditionService;
use app\common\service\theme\ThemeService;

final class SiteBrandService
{

    public function __construct(
        private readonly ThemeService $themeService,
        private readonly SiteCoreLicenseService $siteCoreLicenseService,
        private readonly PivarkEditionService $pivarkEditionService,
        private readonly CoreUpdateRemoteService $coreUpdateRemoteService,
    ) {
    }

    public function requiresAttribution(): bool
    {
        if ($this->themeService->getCurrentTheme() === 'demo') {
            return false;
        }

        if ($this->siteCoreLicenseService->hasFeature(SiteCoreLicenseService::FEATURE_REMOVE_BRAND)) {
            return false;
        }

        return $this->pivarkEditionService->isCommunity();
    }

    /** 产品方版权行（仪表盘「版本与站点」；≠ 客户 site_copyright 页脚） */
    public function productCopyrightText(): string
    {
        return '© 2024-2026 元舟 PivArk · pivark.cn';
    }

    public function officialSiteUrl(): string
    {
        return 'https://www.pivark.cn';
    }

    /** Gitee 开源仓库（浏览 / Issues） */
    public function giteeRepoUrl(): string
    {
        return 'https://gitee.com/pivark/pivark';
    }

    /** GitHub 开源仓库（浏览） */
    public function githubRepoUrl(): string
    {
        $url = trim((string) config('pivark.github_repo_url', ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        return 'https://github.com/pivark/pivark';
    }

    /** GitHub clone 地址 */
    public function githubRepoGitUrl(): string
    {
        return $this->githubRepoUrl() . '.git';
    }

    /**
     * 产品许可短标签（Community 自定义 LICENSE；有去版权授权则标商业授权）
     */
    public function productLicenseLabel(): string
    {
        if ($this->siteCoreLicenseService->hasFeature(SiteCoreLicenseService::FEATURE_REMOVE_BRAND)) {
            return '已获商业授权 · 可去版权';
        }

        return 'PivArk Community 开源许可（见 LICENSE）';
    }

    public function attributionText(): string
    {
        $version = $this->coreUpdateRemoteService->currentVersion();

        return 'Powered by 元舟 PivArk v' . $version . ' · pivark.cn';
    }

    public function attributionHtml(): string
    {
        $version = $this->coreUpdateRemoteService->currentVersion();
        $cn = '<a href="https://www.pivark.cn" target="_blank" rel="noopener noreferrer">pivark.cn</a>';

        return 'Powered by 元舟 PivArk v' . $version . ' · ' . $cn;
    }

    public function resolveSiteCopyright(string $userCopyright): string
    {
        if (!$this->requiresAttribution()) {
            return trim($userCopyright);
        }

        $required = $this->attributionText();
        $userCopyright = trim($userCopyright);
        if ($userCopyright === '') {
            return $required;
        }
        if ($this->containsAttribution($userCopyright)) {
            return $userCopyright;
        }

        return $userCopyright . ' · ' . $required;
    }

    public function sanitizeCopyrightInput(string $input): string
    {
        return $this->resolveSiteCopyright($input);
    }

    /** 后台保存：剥离标签并限制长度 */
    public function sanitizeSiteNameInput(string $input): string
    {
        $name = $this->normalizeStoredSiteName($input, '');
        if ($name === '') {
            throw new \InvalidArgumentException('网站名称不能包含 HTML 或脚本内容');
        }

        return mb_substr($name, 0, 60);
    }

    /**
     * 修复 configs 中历史脏值（迁移/展示共用，不抛异常）
     */
    public function normalizeStoredSiteName(string $raw, string $fallback = '元舟 PivArk'): string
    {
        $name = trim(strip_tags($raw));
        if ($name === '' || $this->siteNameLooksUnsafe($name) || $this->siteNameLooksUnsafe($raw)) {
            return $fallback;
        }

        return mb_substr($name, 0, 60);
    }

    /** 前台展示：配置异常时回退 site_title 或默认品牌名 */
    public function resolveSiteNameForTemplate(string $configured, string $siteTitle = ''): string
    {
        $name = trim(strip_tags($configured));
        if ($name !== '' && !$this->siteNameLooksUnsafe($name)) {
            return $name;
        }

        $title = trim(strip_tags($siteTitle));
        if ($title !== '') {
            if (preg_match('/^(.+?)\s*[—\-|]\s*/u', $title, $m)) {
                return trim((string) $m[1]);
            }

            return mb_substr($title, 0, 40);
        }

        return '元舟 PivArk';
    }

    private function siteNameLooksUnsafe(string $name): bool
    {
        return preg_match('/<|>|script|javascript:/i', $name) === 1;
    }

    /**
     * @return array{
     *   required:bool,
     *   attribution:string,
     *   attribution_html:string,
     *   notice:string
     * }
     */
    public function adminPayload(): array
    {
        $required = $this->requiresAttribution();

        return [
            'required'          => $required,
            'attribution'       => $this->attributionText(),
            'attribution_html'  => $this->attributionHtml(),
            'notice'            => $required
                ? '社区版页脚须保留 PivArk 标识；专业版授权可去掉。'
                : '',
        ];
    }

    private function containsAttribution(string $text): bool
    {
        $lower = strtolower($text);

        return str_contains($lower, 'pivark')
            || str_contains($lower, 'pivark.cn')
            || str_contains($text, '元舟');
    }

    /** demo 主题前台：剥离门户/开源版特有品牌词，避免与演示站 SSOT 冲突 */
    public function neutralizeDemoPresentation(string $html): string
    {
        if ($html === '' || $this->themeService->getCurrentTheme() !== 'demo') {
            return $html;
        }

        $replacements = [
            '元舟 PivArk'   => 'PivArk',
            '元舟'          => '',
            'PivArk 叠加'   => 'PivArk',
            'weapp 开发者'  => '扩展开发者',
            'Apache-2.0'    => 'Community 开源许可',
            'Apache 2.0'    => 'Community 开源许可',
        ];
        foreach ($replacements as $from => $to) {
            $html = str_replace($from, $to, $html);
        }

        return preg_replace('/\s{2,}/u', ' ', $html) ?? $html;
    }
}
