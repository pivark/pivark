<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * WeappSiteGateway
 */
declare(strict_types=1);

namespace app\common\service\weapp;

use app\common\model\SiteNav;
use app\common\model\SitePage;
use app\common\model\Tag;
use app\common\service\infra\MetaSqlCacheService;
use app\common\service\plugin\registry\HubCapabilityRegistry;
use app\common\service\plugin\registry\PluginSeoRegistry;
use app\common\service\site\NavChannelKind;
use app\common\service\site\SiteFormCrudService;
use app\common\service\site\SiteLinkService;
use app\common\service\site\SiteModeService;
use app\common\service\site\SiteNavService;
use app\common\service\site\SitePageService;
use app\common\service\site\SiteSlideService;
use app\common\service\theme\ThemeService;
use app\common\support\ServiceResult;

final class WeappSiteGateway
{

    public function __construct(
        private readonly SitePageService $sitePages,
        private readonly SiteModeService $siteMode,
        private readonly ThemeService $theme,
    ) {
    }

    public function sitePageNormalizeTpl(string $tpl): string
    {
        return $this->sitePages->normalizeTpl($tpl);
    }

    public function siteModeIsProduction(): bool
    {
        return $this->siteMode->isProduction();
    }

    /** @return array<string, mixed>|null */
    public function sitePageRowByPath(string $path): ?array
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        $row = SitePage::where('path', $path)->find()?->toArray();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function sitePageActiveRowByPath(string $path): ?array
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        $row = SitePage::where('path', $path)->where('status', 1)->find()?->toArray();

        return is_array($row) ? $row : null;
    }

    /**
     * 后台单页保存（迁移插件等）：走 SitePageService 校验与缓存失效。
     *
     * @param array<string, mixed> $data
     */
    public function sitePageSaveAdmin(array $data)
    {
        return $this->sitePages->saveAdmin($data);
    }

    /** @param array<string, mixed> $data */
    public function sitePageCreate(array $data): void
    {
        if ($data === []) {
            return;
        }
        SitePage::create($data);
    }

    /** @param array<string, mixed> $data */
    public function sitePageUpdateById(int $id, array $data): void
    {
        if ($id < 1 || $data === []) {
            return;
        }
        SitePage::where('id', $id)->update($data);
    }

    public function sitePageDeleteById(int $id): void
    {
        if ($id < 1) {
            return;
        }
        SitePage::where('id', $id)->delete();
    }

    public function siteNavDeleteAll(): void
    {
        SiteNav::where('id', '>', 0)->delete();
    }

    /** @param array<string, mixed> $data */
    public function siteNavCreate(array $data): int
    {
        $nav = SiteNav::create($data);

        return (int) ($nav['id'] ?? 0);
    }

    /**
     * 插件设置：可选真栏目（document/product），带缩进 label。
     *
     * @return list<array{id:int,title:string,content_kind:string,label:string}>
     */
    public function siteNavListContentCategoryOptions(): array
    {
        return app(SiteNavService::class)->listContentCategoryOptionsForPublish();
    }

    /**
     * 自身 + 全部子孙栏目 id（插件圈选含子栏）。
     *
     * @return list<int>
     */
    public function siteNavSelfAndDescendantIds(int $navId): array
    {
        return app(SiteNavService::class)->contentCategorySelfAndDescendantIds($navId);
    }

    /**
     * @param list<int> $navIds
     * @return list<int>
     */
    public function siteNavExpandWithDescendants(array $navIds): array
    {
        $out = [];
        foreach ($navIds as $id) {
            $id = (int) $id;
            if ($id < 1) {
                continue;
            }
            foreach ($this->siteNavSelfAndDescendantIds($id) as $cid) {
                $out[$cid] = true;
            }
        }

        return array_map('intval', array_keys($out));
    }

    /** @return array<string, mixed>|null */
    public function tagRowBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $row = Tag::where('slug', $slug)->find()?->toArray();

        return is_array($row) ? $row : null;
    }

    public function tagUpdateUseCount(int $tagId, int $count): void
    {
        if ($tagId < 1) {
            return;
        }
        Tag::where('id', $tagId)->update(['use_count' => $count]);
    }

    /** @param array<string, mixed> $data */
    public function tagUpdateById(int $id, array $data): void
    {
        if ($id < 1 || $data === []) {
            return;
        }
        Tag::where('id', $id)->update($data);
    }

    public function themeClassPrefix(): string
    {
        return $this->theme->themeClassPrefix() ?: 'st-';
    }

    public function themeCurrent(): string
    {
        return (string) $this->theme->getCurrentTheme();
    }

    public function sitePageUrlByTpl(string $tpl, string $fallback = ''): string
    {
        return $this->sitePages->urlByTpl($tpl, $fallback);
    }

    /**
     * @param array{id:string,title:string,keywords:string,paragraphs:list<string>} $entry
     */
    public function pluginSeoRegister(string $identifier, array $entry): void
    {
        app(PluginSeoRegistry::class)->register($identifier, $entry);
    }

    public function hubCapabilityRegisterCommunityHub(string $identifier): void
    {
        app(HubCapabilityRegistry::class)->register(HubCapabilityRegistry::SLOT_COMMUNITY_HUB, $identifier);
    }

    public function hubCapabilityRegisterAskHub(string $identifier): void
    {
        app(HubCapabilityRegistry::class)->register(HubCapabilityRegistry::SLOT_ASK_HUB, $identifier);
    }

    public function hubCapabilityRegisterTalentHub(string $identifier): void
    {
        app(HubCapabilityRegistry::class)->register(HubCapabilityRegistry::SLOT_TALENT_HUB, $identifier);
    }

    /** @param array<string, mixed> $data */
    public function siteFormSaveAdmin(array $data): ServiceResult
    {
        return app(SiteFormCrudService::class)->saveAdmin($data);
    }

    /** @param array<string, mixed> $data */
    public function siteLinkSaveAdmin(array $data): ServiceResult
    {
        return app(SiteLinkService::class)->saveAdmin($data);
    }

    /** @param array<string, mixed> $data */
    public function siteSlideSaveAdmin(array $data): ServiceResult
    {
        return app(SiteSlideService::class)->saveAdmin($data);
    }

    public function siteSlideSlotHomeCarousel(): string
    {
        return SiteSlideService::SLOT_HOME_CAROUSEL;
    }

    public function siteSlideTypeCarousel(): string
    {
        return SiteSlideService::TYPE_CAROUSEL;
    }

    public function siteNavTypeTag(): string
    {
        return SiteNavService::TYPE_TAG;
    }

    public function siteNavTypePage(): string
    {
        return SiteNavService::TYPE_PAGE;
    }

    public function navChannelKindProduct(): string
    {
        return NavChannelKind::PRODUCT;
    }

    public function navChannelKindDocument(): string
    {
        return NavChannelKind::DOCUMENT;
    }

    public function navChannelKindPage(): string
    {
        return NavChannelKind::PAGE;
    }

    /**
     * 可落库的内容栏目 content_kind（document/product/page）。
     *
     * @return list<string>
     */
    public function navChannelKindContentKinds(): array
    {
        return [
            NavChannelKind::DOCUMENT,
            NavChannelKind::PRODUCT,
            NavChannelKind::PAGE,
        ];
    }

    /** @return array<string, mixed>|null */
    public function siteNavFindContentCategoryByPublicPath(string $path): ?array
    {
        return app(SiteNavService::class)->findContentCategoryByPublicPath($path);
    }

    public function siteNavIsContentCategoryId(int $id): bool
    {
        return app(SiteNavService::class)->isContentCategoryId($id);
    }

    public function metaSqlCacheForget(string $tag): void
    {
        app(MetaSqlCacheService::class)->forget($tag);
    }
}
