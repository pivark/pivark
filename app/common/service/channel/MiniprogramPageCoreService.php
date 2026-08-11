<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split — 读写/规范化/预览/对外 payload
 */
declare(strict_types=1);

namespace app\common\service\channel;


use app\common\support\AppTime;
use app\common\support\ServiceResult;
use app\common\service\channel\MiniprogramConfigService;
use app\common\service\channel\MiniprogramPageDefaultsService;


use app\common\service\config\ConfigService;
use app\common\service\document\DocumentFormatService;
use app\common\service\document\satellite\DocumentBlockService;
use app\common\service\document\DocumentPublicService;
use app\common\service\item\ItemService;
use app\common\service\site\SiteSlideService;
use app\common\service\tag\TagPublicService;
use app\common\service\user\UserService;
use app\common\support\WeappPublicAsset;
use app\common\service\channel\MiniprogramChannelService;
class MiniprogramPageCoreService
{

    /** @var array<string, mixed> 单次 buildAdminPreview 内复用查询结果 */
    private array $previewQueryCache = [];

    public function __construct(
        private readonly ConfigService $configService,
        private readonly MiniprogramPageDefaultsService $miniprogramPageDefaultsService,
        private readonly DocumentBlockService $documentBlockService,
        private readonly MiniprogramChannelService $miniprogramChannelService,
        private readonly TagPublicService $tagPublicService,
        private readonly SiteSlideService $siteSlideService,
        private readonly DocumentPublicService $documentService,
        private readonly DocumentFormatService $documentFormatService,
        private readonly ItemService $itemService,
    ) {
    }

    /** @return array<string, string> */
    public function theme(): array
    {
        $raw = trim((string) $this->configService->get('mp_wechat_theme_json', ''));
        if ($raw === '') {
            return $this->miniprogramPageDefaultsService->defaultTheme();
        }
        $decoded = json_decode($raw, true);

        return $this->normalizeTheme(is_array($decoded) ? $decoded : []);
    }

    /** @return list<array<string, mixed>> */
    public function homeBlocks(): array
    {
        if (app(MiniprogramConfigService::class)->edition() === MiniprogramConfigService::EDITION_SHOP) {
            return $this->shopHomeBlocks();
        }
        $raw = trim((string) $this->configService->get('mp_wechat_home_blocks', ''));
        if ($raw === '') {
            return $this->miniprogramPageDefaultsService->defaultHomeBlocks();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return $this->miniprogramPageDefaultsService->defaultHomeBlocks();
        }

        return $this->normalizeBlocks($decoded, MiniprogramPageConfigService::PAGE_HOME);
    }

    /** @return list<array<string, mixed>> */
    public function shopHomeBlocks(): array
    {
        $raw = trim((string) $this->configService->get('mp_wechat_shop_home_blocks', ''));
        if ($raw === '') {
            return $this->miniprogramPageDefaultsService->defaultShopHomeBlocks();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return $this->miniprogramPageDefaultsService->defaultShopHomeBlocks();
        }

        return $this->normalizeBlocks($decoded, MiniprogramPageConfigService::PAGE_HOME);
    }

    /** @return list<array<string, mixed>> */
    public function productsBlocks(): array
    {
        $raw = trim((string) $this->configService->get('mp_wechat_products_blocks', ''));
        if ($raw === '') {
            return $this->miniprogramPageDefaultsService->defaultProductsBlocks();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return $this->miniprogramPageDefaultsService->defaultProductsBlocks();
        }

        return $this->normalizeBlocks($decoded, MiniprogramPageConfigService::PAGE_PRODUCTS);
    }

    /** @return list<array<string, mixed>> */
    public function mineBlocks(): array
    {
        $raw = trim((string) $this->configService->get('mp_wechat_mine_blocks', ''));
        if ($raw === '') {
            return $this->miniprogramPageDefaultsService->defaultMineBlocks();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return $this->miniprogramPageDefaultsService->defaultMineBlocks();
        }

        return $this->normalizeBlocks($decoded, MiniprogramPageConfigService::PAGE_MINE);
    }

    /** @return list<array<string, mixed>> */
    public function tagsBlocks(): array
    {
        $raw = trim((string) $this->configService->get('mp_wechat_tags_blocks', ''));
        if ($raw === '') {
            return $this->miniprogramPageDefaultsService->defaultTagsBlocks();
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return $this->miniprogramPageDefaultsService->defaultTagsBlocks();
        }

        return $this->normalizeBlocks($decoded, MiniprogramPageConfigService::PAGE_TAGS);
    }

    /** @return array<string, mixed> */
    public function tabBar(): array
    {
        $raw = trim((string) $this->configService->get('mp_wechat_tabbar_json', ''));
        if ($raw === '') {
            return $this->miniprogramPageDefaultsService->defaultTabBar();
        }
        $decoded = json_decode($raw, true);

        return $this->normalizeTabBar(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function normalizeTabBar(array $in): array
    {
        $base = $this->miniprogramPageDefaultsService->defaultTabBar();
        $colorRaw = trim((string) ($in['color'] ?? $base['color']));
        $base['color'] = $this->isColor($colorRaw) ? $colorRaw : $base['color'];
        $sel = trim((string) ($in['selected_color'] ?? ''));
        $base['selected_color'] = $this->isColor($sel) ? $sel : '';
        $bgRaw = trim((string) ($in['background'] ?? $base['background']));
        $base['background'] = $this->isColor($bgRaw) ? $bgRaw : $base['background'];
        $border = trim((string) ($in['border_style'] ?? 'black'));
        $base['border_style'] = in_array($border, ['black', 'white'], true) ? $border : 'black';
        $listIn = is_array($in['list'] ?? null) ? $in['list'] : [];
        if ($listIn !== []) {
            $out = [];
            foreach ($listIn as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $text = trim((string) ($row['text'] ?? ''));
                $path = trim((string) ($row['pagePath'] ?? ''));
                if ($text === '' || $path === '') {
                    continue;
                }
                $icon = trim((string) ($row['icon'] ?? ''));
                $selIcon = trim((string) ($row['selected_icon'] ?? ($row['selectedIcon'] ?? '')));
                $item = ['text' => $text, 'pagePath' => $path];
                if ($icon !== '') {
                    $item['icon'] = $icon;
                }
                if ($selIcon !== '') {
                    $item['selected_icon'] = $selIcon;
                }
                $out[] = $item;
            }
            if ($out !== []) {
                $base['list'] = $out;
            }
        }

        return $base;
    }

    /** @return array<string, mixed> */
    public function tabBarForPublic(): array
    {
        $bar   = $this->tabBar();
        $theme = $this->theme();
        $sel   = trim((string) ($bar['selected_color'] ?? ''));
        if ($sel === '') {
            $sel = $theme['accent'] ?? '#07c160';
        }

        $list = [];
        foreach ($bar['list'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = [
                'text'     => (string) ($row['text'] ?? ''),
                'pagePath' => (string) ($row['pagePath'] ?? ''),
            ];
            if (!empty($row['icon'])) {
                $item['icon'] = WeappPublicAsset::absoluteAssetUrl((string) $row['icon']);
            }
            if (!empty($row['selected_icon'])) {
                $item['selected_icon'] = WeappPublicAsset::absoluteAssetUrl((string) $row['selected_icon']);
            }
            $list[] = $item;
        }

        return [
            'color'          => $bar['color'],
            'selected_color' => $sel,
            'background'     => $bar['background'],
            'border_style'   => $bar['border_style'],
            'list'           => $list,
        ];
    }

    /**
     * @param string $page home|tags|products|mine
     * @return list<array<string, mixed>>
     */
    public function blocksForPage(string $page): array
    {
        return match ($page) {
            MiniprogramPageConfigService::PAGE_TAGS     => $this->tagsBlocks(),
            MiniprogramPageConfigService::PAGE_PRODUCTS => $this->productsBlocks(),
            MiniprogramPageConfigService::PAGE_MINE     => $this->mineBlocks(),
            default             => $this->homeBlocks(),
        };
    }

    /** @return array{theme:array<string,string>,blocks:list<array<string,mixed>>,tabbar:array<string,mixed>} */

    private function publicPagePayload(callable $blocksFn): array
    {
        $blocks = [];
        foreach ($blocksFn() as $block) {
            if (empty($block['enabled'])) {
                continue;
            }
            $blocks[] = $block;
        }

        return [
            'theme'  => $this->theme(),
            'blocks' => $blocks,
            'tabbar' => $this->tabBarForPublic(),
        ];
    }

    /** @return array{theme:array<string,string>,blocks:list<array<string,mixed>>,tabbar:array<string,mixed>} */

    public function publicHomePayload(): array
    {
        return $this->publicPagePayload(fn (): array => $this->homeBlocks());
    }

    /** @return array{theme:array<string,string>,blocks:list<array<string,mixed>>,tabbar:array<string,mixed>} */

    public function publicTagsPayload(): array
    {
        return $this->publicPagePayload(fn (): array => $this->tagsBlocks());
    }

    /** @return array{theme:array<string,string>,blocks:list<array<string,mixed>>,tabbar:array<string,mixed>} */

    public function publicProductsPayload(): array
    {
        return $this->publicPagePayload(fn (): array => $this->productsBlocks());
    }

    /** @return array{theme:array<string,string>,blocks:list<array<string,mixed>>,tabbar:array<string,mixed>} */

    public function publicMinePayload(): array
    {
        return $this->publicPagePayload(fn (): array => $this->mineBlocks());
    }

    /**
     * 内容版站内交付流是否就绪（装修四页 + 产品列表组件）
     *
     * @return array{
     *   decor_ready:bool,
     *   products_has_list:bool,
     *   block_counts:array{home:int,tags:int,products:int,mine:int}
     * }
     */

    public function contentFlowStatus(): array
    {
        $home     = $this->homeBlocks();
        $tags     = $this->tagsBlocks();
        $products = $this->productsBlocks();
        $mine     = $this->mineBlocks();

        $hasProductList = false;
        foreach ($products as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            if (!in_array($type, ['product_grid', 'product_scroll'], true)) {
                continue;
            }
            if (($row['enabled'] ?? true) === false) {
                continue;
            }
            $hasProductList = true;
            break;
        }

        $decorReady = count($home) >= 2
            && count($tags) >= 1
            && count($mine) >= 1
            && ($hasProductList || count($products) >= 3);

        return [
            'decor_ready'       => $decorReady,
            'products_has_list' => $hasProductList,
            'block_counts'      => [
                'home'     => count($home),
                'tags'     => count($tags),
                'products' => count($products),
                'mine'     => count($mine),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function adminMeta(): array
    {
        $theme = $this->theme();

        return [
            'theme'            => $theme,
            'blocks'           => $this->homeBlocks(),
            'tags_blocks'      => $this->tagsBlocks(),
            'products_blocks'  => $this->productsBlocks(),
            'mine_blocks'      => $this->mineBlocks(),
            'tabbar'           => $this->tabBar(),
            'site'             => $this->previewSite(),
            'pages'            => $this->miniprogramPageDefaultsService->decorPages(),
            'categories'       => $this->miniprogramPageDefaultsService->blockCategories(),
            'block_catalog'    => $this->miniprogramPageDefaultsService->blockCatalog(),
            'block_defaults'   => $this->miniprogramPageDefaultsService->allDefaultProps(),
            'orderby_options'  => $this->documentBlockService->orderbyOptions(),
            'attr_options'     => $this->documentBlockService->attrOptions(),
            'slide_slots'      => [
                ['value' => SiteSlideService::SLOT_HOME_CAROUSEL, 'label' => '首页轮播'],
                ['value' => SiteSlideService::SLOT_HOME_HERO, 'label' => '首页主图'],
            ],
        ];
    }

    /**
     * 解析后台保存请求（支持 JSON body 与 form 数组 theme/blocks/tabbar）
     *
     * @param array<string, mixed> $post 控制器传入 POST（禁止 Service 内读 Request）
     * @return array{page:string,theme:array<string,mixed>,blocks:list<array<string,mixed>>,tabbar:array<string,mixed>}
     */
    public function parseAdminSavePayload(array $post = [], string $rawBody = ''): array
    {
        $body = $post;
        if ($body === [] && trim($rawBody) !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        if (!is_array($body)) {
            $body = [];
        }

        $page = trim((string) ($body['page'] ?? MiniprogramPageConfigService::PAGE_HOME));

        $tabbar = $body['tabbar'] ?? null;
        if (!is_array($tabbar)) {
            $tabbar = $this->decodeJsonField($body['tabbar_json'] ?? '');
        }

        $theme = $body['theme'] ?? null;
        if (!is_array($theme)) {
            $theme = $this->decodeJsonField($body['theme_json'] ?? '');
        }

        $blocks = $body['blocks'] ?? null;
        if (!is_array($blocks)) {
            $blocks = $this->decodeJsonField($body['blocks_json'] ?? '');
        }

        return [
            'page'   => $page,
            'theme'  => is_array($theme) ? $theme : [],
            'blocks' => is_array($blocks) ? $blocks : [],
            'tabbar' => is_array($tabbar) ? $tabbar : [],
        ];
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function decodeJsonField(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */

    public function saveAdmin(array $data): ServiceResult
    {
        $deny = $this->miniprogramChannelService->assertLicensed();
        if ($deny !== null) {
            return $deny;
        }

        $page = trim((string) ($data['page'] ?? MiniprogramPageConfigService::PAGE_HOME));
        if ($page === 'tabbar') {
            $tabbar = $this->normalizeTabBar(is_array($data['tabbar'] ?? null) ? $data['tabbar'] : []);
            $this->configService->set('mp_wechat_tabbar_json', json_encode($tabbar, JSON_UNESCAPED_UNICODE));
            $this->configService->forgetRequestCache();

            return ServiceResult::ok(null, '底部导航已保存');
        }

        $theme = $this->normalizeTheme(is_array($data['theme'] ?? null) ? $data['theme'] : []);
        $this->configService->set('mp_wechat_theme_json', json_encode($theme, JSON_UNESCAPED_UNICODE));

        $blocks = $this->normalizeBlocks(
            is_array($data['blocks'] ?? null) ? $data['blocks'] : [],
            $page,
        );
        $configKey = match ($page) {
            MiniprogramPageConfigService::PAGE_TAGS     => 'mp_wechat_tags_blocks',
            MiniprogramPageConfigService::PAGE_PRODUCTS => 'mp_wechat_products_blocks',
            MiniprogramPageConfigService::PAGE_MINE     => 'mp_wechat_mine_blocks',
            default             => 'mp_wechat_home_blocks',
        };
        $this->configService->set($configKey, json_encode($blocks, JSON_UNESCAPED_UNICODE));
        $this->configService->forgetRequestCache();

        return ServiceResult::ok(null, '页面装修已保存');
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, string>
     */
    public function normalizeTheme(array $in): array
    {
        $base = $this->miniprogramPageDefaultsService->defaultTheme();
        // 旧默认「企业深蓝」→ 2026 微信系浅绿中性；仅当整套仍是旧出厂色才迁移，自定义品牌色不碰
        $legacyPrimary = strtolower(trim((string) ($in['primary'] ?? '')));
        $legacyDark = strtolower(trim((string) ($in['primary_dark'] ?? '')));
        if ($legacyPrimary === '#1a6fb5' && ($legacyDark === '' || $legacyDark === '#0c3d6e')) {
            $in['primary'] = $base['primary'];
            $in['primary_dark'] = $base['primary_dark'];
            $in['accent'] = $base['accent'];
            if (strtolower(trim((string) ($in['page_bg'] ?? ''))) === '#f0f2f5') {
                $in['page_bg'] = $base['page_bg'];
            }
            if (strtolower(trim((string) ($in['text_primary'] ?? ''))) === '#222222') {
                $in['text_primary'] = $base['text_primary'];
            }
            if (strtolower(trim((string) ($in['text_muted'] ?? ''))) === '#888888') {
                $in['text_muted'] = $base['text_muted'];
            }
        }
        foreach ($base as $key => $default) {
            if ($key === 'corner_style') {
                $val = trim((string) ($in[$key] ?? $default));
                $base[$key] = in_array($val, ['rounded', 'square'], true) ? $val : $default;

                continue;
            }
            $val = trim((string) ($in[$key] ?? $default));
            if (!$this->isColor($val)) {
                $val = $default;
            }
            $base[$key] = $val;
        }

        return $base;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeBlocks(array $rows, string $page = MiniprogramPageConfigService::PAGE_HOME): array
    {
        $allowed = array_column($this->miniprogramPageDefaultsService->blockCatalog(), 'type');
        $out     = [];
        $i       = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = trim((string) ($row['type'] ?? ''));
            if (!in_array($type, $allowed, true)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = $type . '_' . $i;
            }
            $props = is_array($row['props'] ?? null) ? $row['props'] : [];
            $props = array_merge($this->miniprogramPageDefaultsService->defaultPropsForType($type), $props);
            $out[] = [
                'id'      => $id,
                'type'    => $type,
                'enabled' => !empty($row['enabled']),
                'props'   => $props,
            ];
            $i++;
        }

        return $out !== [] ? $out : $this->miniprogramPageDefaultsService->defaultBlocksForPage($page);
    }

    private function isColor(string $value): bool
    {
        return (bool) preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value);
    }

    /**
     * 后台所见即所得预览（草稿 theme/blocks，未保存亦可用真实 API 数据）
     *
     * @param array<string, mixed> $themeIn
     * @param list<array<string, mixed>> $blocksIn
     * @return array{theme:array<string,string>,site:array<string,string>,rows:list<array<string,mixed>>}
     */

    public function buildAdminPreview(array $themeIn, array $blocksIn, string $page = MiniprogramPageConfigService::PAGE_HOME): array
    {
        $this->previewQueryCache = [];
        $theme  = $this->normalizeTheme($themeIn);
        $blocks = $this->normalizeBlocks($blocksIn, $page);
        $site   = $this->previewSite();
        $rows   = [];
        foreach ($blocks as $block) {
            $row = [
                'id'       => (string) ($block['id'] ?? ''),
                'type'     => (string) ($block['type'] ?? ''),
                'enabled'  => !empty($block['enabled']),
                'props'    => is_array($block['props'] ?? null) ? $block['props'] : [],
                'banners'  => [],
                'products' => [],
                'docList'  => [],
                'tags'     => [],
            ];
            if ($row['enabled']) {
                $this->fillPreviewRow($row, $page);
            }
            $rows[] = $row;
        }

        return [
            'theme'  => $theme,
            'site'   => $site,
            'rows'   => $rows,
            'tabbar' => $this->tabBarForPublicWithTheme($theme),
            'page'   => $page,
        ];
    }

    /** @param array<string, string> $theme */
    private function tabBarForPublicWithTheme(array $theme): array
    {
        $bar = $this->tabBarForPublic();
        if (trim((string) $this->tabBar()['selected_color']) === '') {
            $bar['selected_color'] = $theme['accent'] ?? $bar['selected_color'];
        }

        return $bar;
    }

    private function previewSiteLogo(): string
    {
        $raw = trim((string) $this->configService->get('site_logo', ''));
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw) === 1 || str_starts_with($raw, '//')) {
            return $raw;
        }

        return (string) (app(UserService::class)->publicMediaUrlIfExists($raw) ?? '');
    }

    /** @return array{name:string,desc:string,logo:string,copyright:string,icp:string}

     */
    private function previewSite(): array
    {
        $name = trim((string) $this->configService->get('site_name', ''));
        if ($name === '') {
            $name = trim((string) $this->configService->get('site_title', '站点'));
        }

        return [
            'name'      => $name,
            'desc'      => trim((string) $this->configService->get('site_description', '')),
            'logo'      => $this->previewSiteLogo(),
            'copyright' => trim((string) $this->configService->get('site_copyright', '')),
            'icp'       => trim((string) $this->configService->get('site_icp', '')),
        ];
    }

    /** @param array<string, mixed> $row */
    private function fillPreviewRow(array &$row, string $page = MiniprogramPageConfigService::PAGE_HOME): void
    {
        $props = $row['props'];
        $type  = (string) $row['type'];

        if ($type === 'page_hero' && $page === MiniprogramPageConfigService::PAGE_TAGS) {
            return;
        }
        if ($type === 'banner') {
            $row['banners'] = $this->previewBanners($props);
        } elseif ($type === 'product_scroll') {
            $row['products'] = $this->previewProductsCached($props);
        } elseif ($type === 'product_grid') {
            $row['products'] = $this->previewProductsCached($props);
        } elseif ($type === 'document_list' || $type === 'image_grid' || $type === 'featured_doc') {
            $list = $this->previewDocumentsCached($props);
            if ($type === 'image_grid') {
                $list = array_values(array_filter(
                    $list,
                    static fn (array $d): bool => ($d['litpic'] ?? '') !== ''
                ));
            }
            if ($type === 'featured_doc') {
                $list = array_slice($list, 0, 1);
            }
            $row['docList'] = $list;
        } elseif ($type === 'tag_list') {
            $row['tags'] = $this->previewTags($props);
        }
    }

    /** @param array<string, mixed> $props
     * @return list<array{slug:string,name:string,count:int}
     */

    private function previewTags(array $props): array
    {
        $limit  = max(1, min(30, (int) ($props['limit'] ?? 12)));
        $result = $this->tagPublicService->listPublic(1, $limit);
        $out    = [];
        foreach ($result['list'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'slug'  => trim((string) ($item['slug'] ?? '')),
                'name'  => trim((string) ($item['name'] ?? '')),
                'count' => (int) ($item['use_count'] ?? 0),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $props
     * @return list<array{id:int,title:string,summary:string,litpic:string}
     */

    private function previewBanners(array $props): array
    {
        $limit = max(1, min(10, (int) ($props['limit'] ?? 5)));
        if (($props['source'] ?? 'documents') === 'slides') {
            $slot = trim((string) ($props['slot'] ?? SiteSlideService::SLOT_HOME_CAROUSEL));
            $slides = $this->siteSlideService->listPublic($slot !== '' ? $slot : SiteSlideService::SLOT_HOME_CAROUSEL);
            $out    = [];
            foreach ($slides as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $litpic = $this->absMediaUrl(trim((string) ($item['image_url'] ?? $item['image'] ?? '')));
                if ($litpic === '') {
                    continue;
                }
                $out[] = [
                    'id'      => (int) ($item['link_document_id'] ?? $item['id'] ?? $i),
                    'title'   => trim((string) ($item['title'] ?? '')),
                    'summary' => trim((string) ($item['subtitle'] ?? '')),
                    'litpic'  => $litpic,
                ];
            }

            return $out;
        }

        $params = ['page' => 1, 'limit' => $limit];
        $attr   = trim((string) ($props['attr'] ?? ''));
        if ($attr !== '') {
            $params['attr'] = $attr;
        }
        $list = $this->mapPreviewDocuments($this->listPreviewDocumentsRaw($params));
        if ($list === [] && $attr !== '') {
            $list = $this->mapPreviewDocuments(
                $this->listPreviewDocumentsRaw(['page' => 1, 'limit' => $limit])
            );
        }

        return array_values(array_filter($list, static fn (array $d): bool => $d['litpic'] !== ''));
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function listPreviewDocumentsRaw(array $params): array
    {
        $key = 'docs:' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));
        if (array_key_exists($key, $this->previewQueryCache)) {
            return $this->previewQueryCache[$key];
        }
        $result = $this->documentService->listPublic($params);
        $raw    = is_array($result['list'] ?? null) ? $result['list'] : [];
        if ($raw !== []) {
            $raw = $this->documentFormatService->applyListMediaFieldsBatch($raw);
        }
        $this->previewQueryCache[$key] = $raw;

        return $raw;
    }

    /** @param array<string, mixed> $props
     * @return list<array{id:int,title:string,summary:string,litpic:string,time:string,click:int}>
     */
    private function previewDocumentsCached(array $props): array
    {
        $key = 'docRows:' . md5(json_encode($props, JSON_UNESCAPED_UNICODE));
        if (array_key_exists($key, $this->previewQueryCache)) {
            return $this->previewQueryCache[$key];
        }
        $rows = $this->previewDocuments($props);
        $this->previewQueryCache[$key] = $rows;

        return $rows;
    }

    /** @param array<string, mixed> $props
     * @return list<array{id:int,name:string,slug:string,cover:string}>
     */
    private function previewProductsCached(array $props): array
    {
        $key = 'products:' . md5(json_encode($props, JSON_UNESCAPED_UNICODE));
        if (array_key_exists($key, $this->previewQueryCache)) {
            return $this->previewQueryCache[$key];
        }
        $rows = $this->previewProducts($props);
        $this->previewQueryCache[$key] = $rows;

        return $rows;
    }

    /** @param array<string, mixed> $props
     * @return list<array{id:int,name:string,slug:string,cover:string}>
     */
    private function previewProducts(array $props): array
    {
        $limit  = max(1, min(20, (int) ($props['limit'] ?? 8)));
        $params = ['page' => 1, 'limit' => $limit];
        $tag    = trim((string) ($props['tag'] ?? ''));
        if ($tag !== '') {
            $params['tag'] = $tag;
        }
        $result = $this->itemService->listPublic($params);
        $out    = [];
        foreach ($result['list'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'id'    => (int) ($item['id'] ?? 0),
                'name'  => trim((string) ($item['name'] ?? '')),
                'slug'  => trim((string) ($item['slug'] ?? '')),
                'cover' => $this->resolvePreviewItemCover($item),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $props
     * @return list<array{id:int,title:string,summary:string,litpic:string,time:string,click:int}>
     */
    private function previewDocuments(array $props): array
    {
        $limit  = max(1, min(30, (int) ($props['limit'] ?? 10)));
        $params = ['page' => 1, 'limit' => $limit];
        $tags   = trim((string) ($props['tags'] ?? ''));
        if ($tags !== '') {
            $params['tags'] = $tags;
        }
        $attr = trim((string) ($props['attr'] ?? ''));
        if ($attr !== '') {
            $params['attr'] = $attr;
        }
        $orderby = trim((string) ($props['orderby'] ?? 'new'));
        $params['sort'] = $this->documentBlockService->mapOrderbyToSort($orderby);

        return $this->mapPreviewDocuments($this->listPreviewDocumentsRaw($params));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id:int,title:string,summary:string,litpic:string,time:string,click:int}
     */

    private function mapPreviewDocuments(array $rows): array
    {
        $out = [];
        foreach ($rows as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = (string) ($item['content'] ?? '');
            $summary = trim((string) ($item['summary'] ?? ''));
            if ($summary === '' && $content !== '') {
                $plain   = trim(strip_tags($content));
                $summary = mb_substr($plain, 0, 80);
            }
            $ts = (string) ($item['published_at'] ?? $item['created_at'] ?? '');
            $time = '';
            if ($ts !== '') {
                $t = strtotime($ts);
                $time = $t ? AppTime::format('Y-m-d', $t) : '';
            }
            $litpic = trim((string) ($item['litpic'] ?? ''));
            if ($litpic === '') {
                $litpic = trim((string) ($item['thumb_url'] ?? ''));
            }
            $out[] = [
                'id'      => (int) ($item['id'] ?? 0),
                'title'   => trim((string) ($item['title'] ?? '无标题')),
                'summary' => $summary,
                'litpic'  => $this->absMediaUrl($litpic),
                'time'    => $time,
                'click'   => (int) ($item['click'] ?? 0),
            ];
        }

        return $out;
    }

    /** 后台预览 / 小程序：统一转为可访问的绝对 URL */
    private function absMediaUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, '//')) {
            return 'https:' . $path;
        }
        if (!preg_match('#^https?://#i', $path)) {
            if (!str_starts_with($path, '/')) {
                $path = '/' . ltrim($path, '/');
            }
            if (app(UserService::class)->publicMediaUrlIfExists($path) === null) {
                return '';
            }
        }

        return WeappPublicAsset::absoluteAssetUrl($path);
    }

    /** @param array<string, mixed> $item */
    private function resolvePreviewItemCover(array $item): string
    {
        return $this->absMediaUrl(trim((string) ($item['litpic'] ?? '')));
    }

}
