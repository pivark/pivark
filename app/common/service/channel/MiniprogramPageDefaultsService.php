<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split — 默认区块/catalog
 */
declare(strict_types=1);

namespace app\common\service\channel;
use app\common\service\channel\MiniprogramConfigService;


use app\common\service\config\ConfigService;
use app\common\service\document\DocumentFormatService;
use app\common\service\item\ItemService;
use app\common\service\site\SiteSlideService;
use app\common\service\tag\TagPublicService;
use app\common\support\WeappPublicAsset;
use app\common\service\channel\MiniprogramChannelService;
use think\facade\Request;

class MiniprogramPageDefaultsService
{

    public function __construct(
        private readonly ConfigService $configService,
        private readonly MiniprogramConfigService $miniprogramConfigService,
    ) {
    }


    /** @return list<array{key:string,label:string} */

    public function blockCategories(): array
    {
        return [
            ['key' => 'basic', 'label' => '基础组件'],
            ['key' => 'nav', 'label' => '导航组件'],
            ['key' => 'media', 'label' => '媒体展示'],
            ['key' => 'content', 'label' => '内容组件'],
            ['key' => 'layout', 'label' => '布局组件'],
        ];
    }

    /** @return list<array{key:string,label:string,catalog_pages:list<string>} */

    public function decorPages(): array
    {
        return [
            ['key' => 'home', 'label' => '首页', 'catalog_pages' => ['home']],
            ['key' => 'tags', 'label' => '分类页', 'catalog_pages' => ['tags']],
            ['key' => 'products', 'label' => '产品页', 'catalog_pages' => ['products', 'home', 'tags']],
            ['key' => 'mine', 'label' => '我的页', 'catalog_pages' => ['mine', 'home', 'tags']],
            ['key' => 'tabbar', 'label' => '底部导航', 'catalog_pages' => []],
        ];
    }

    /** @return array<string, string> */
    public function defaultTheme(): array
    {
        return [
            'primary'       => '#07c160',
            'primary_dark'  => '#06ad56',
            'accent'        => '#07c160',
            'page_bg'       => '#f7f8fa',
            'card_bg'       => '#ffffff',
            'text_primary'  => '#1f2329',
            'text_muted'    => '#8f959e',
            'corner_style'  => 'rounded',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function defaultHomeBlocks(): array
    {
        return [
            [
                'id'      => 'banner',
                'type'    => 'banner',
                'enabled' => true,
                'props'   => [
                    'source' => 'documents',
                    'attr'   => 'recommend',
                    'limit'  => 5,
                    'slot'   => SiteSlideService::SLOT_HOME_CAROUSEL,
                ],
            ],
            [
                'id'      => 'search',
                'type'    => 'search',
                'enabled' => true,
                'props'   => ['placeholder' => '搜索产品、方案与资讯'],
            ],
            [
                'id'      => 'quick_nav',
                'type'    => 'quick_nav',
                'enabled' => true,
                'props'   => [
                    'items' => [
                        ['label' => '产品中心', 'icon' => '产', 'action' => 'tab:products'],
                        ['label' => '方案分类', 'icon' => '案', 'action' => 'tab:tags'],
                        ['label' => '搜索', 'icon' => '搜', 'action' => 'page:search'],
                        ['label' => '会员中心', 'icon' => '我', 'action' => 'tab:mine'],
                    ],
                ],
            ],
            [
                'id'      => 'intro',
                'type'    => 'intro',
                'enabled' => true,
                'props'   => ['show_logo' => true],
            ],
            [
                'id'      => 'products',
                'type'    => 'product_scroll',
                'enabled' => true,
                'props'   => ['title' => '产品推荐', 'limit' => 8, 'tag' => '', 'more' => 'tab:products'],
            ],
            [
                'id'      => 'news',
                'type'    => 'document_list',
                'enabled' => true,
                'props'   => [
                    'title'   => '最新动态',
                    'tags'    => '',
                    'attr'    => '',
                    'orderby' => 'new',
                    'limit'   => 10,
                    'more'    => 'tab:tags',
                ],
            ],
            [
                'id'      => 'footer',
                'type'    => 'footer',
                'enabled' => true,
                'props'   => [],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function defaultShopHomeBlocks(): array
    {
        return [
            [
                'id'      => 'banner',
                'type'    => 'banner',
                'enabled' => true,
                'props'   => [
                    'source' => 'documents',
                    'attr'   => 'recommend',
                    'limit'  => 5,
                    'slot'   => SiteSlideService::SLOT_HOME_CAROUSEL,
                ],
            ],
            [
                'id'      => 'search',
                'type'    => 'search',
                'enabled' => true,
                'props'   => ['placeholder' => '搜索商品与方案'],
            ],
            [
                'id'      => 'stat',
                'type'    => 'stat_row',
                'enabled' => true,
                'props'   => $this->defaultPropsForType('stat_row'),
            ],
            [
                'id'      => 'grid',
                'type'    => 'product_grid',
                'enabled' => true,
                'props'   => [
                    'title'   => '热卖好物',
                    'limit'   => 6,
                    'tag'     => '',
                    'columns' => 2,
                    'more'    => 'tab:products',
                ],
            ],
            [
                'id'      => 'cta',
                'type'    => 'cta_button',
                'enabled' => true,
                'props'   => [
                    'title'       => '会员专享价',
                    'subtitle'    => '登录解锁更多权益',
                    'button_text' => '立即登录',
                    'action'      => 'tab:mine',
                ],
            ],
            [
                'id'      => 'footer',
                'type'    => 'footer',
                'enabled' => true,
                'props'   => [],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function defaultProductsBlocks(): array
    {
        return [
            [
                'id'      => 'title',
                'type'    => 'title_bar',
                'enabled' => true,
                'props'   => ['title' => '产品中心', 'subtitle' => '浏览全部产品与方案'],
            ],
            [
                'id'      => 'search',
                'type'    => 'search',
                'enabled' => true,
                'props'   => ['placeholder' => '搜索产品名称或型号'],
            ],
            [
                'id'      => 'grid',
                'type'    => 'product_grid',
                'enabled' => true,
                'props'   => [
                    'title'   => '全部产品',
                    'limit'   => 24,
                    'tag'     => '',
                    'columns' => 2,
                    'more'    => '',
                ],
            ],
            [
                'id'      => 'cta',
                'type'    => 'cta_button',
                'enabled' => true,
                'props'   => [
                    'title'       => '需要选型帮助？',
                    'subtitle'    => '提交留言，顾问将联系您',
                    'button_text' => '在线咨询',
                    'action'      => 'page:form/index?slug=contact',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function defaultMineBlocks(): array
    {
        $phone   = trim((string) $this->configService->get('site_phone', ''));
        $address = trim((string) $this->configService->get('site_address', ''));

        return [
            [
                'id'      => 'quick',
                'type'    => 'quick_nav',
                'enabled' => true,
                'props'   => [
                    'items' => [
                        ['label' => '会员充值', 'icon' => '充', 'action' => 'page:member/recharge'],
                        ['label' => '搜索内容', 'icon' => '搜', 'action' => 'page:search'],
                        ['label' => '产品中心', 'icon' => '产', 'action' => 'tab:products'],
                        ['label' => '在线留言', 'icon' => '服', 'action' => 'page:form/index?slug=contact'],
                    ],
                ],
            ],
            [
                'id'      => 'quote',
                'type'    => 'quote',
                'enabled' => true,
                'props'   => [
                    'text'   => '登录后可查看会员等级、积分与充值入口。',
                    'author' => '',
                    'style'  => 'accent',
                ],
            ],
            [
                'id'      => 'nav',
                'type'    => 'nav_list',
                'enabled' => true,
                'props'   => [
                    'items' => [
                        ['label' => '会员充值', 'desc' => '在线充值与套餐', 'icon' => '充', 'action' => 'page:member/recharge'],
                        ['label' => '搜索内容', 'desc' => '资讯与产品', 'icon' => '搜', 'action' => 'page:search'],
                        ['label' => '产品中心', 'desc' => '浏览全部产品', 'icon' => '产', 'action' => 'tab:products'],
                        ['label' => '我的订单', 'desc' => '商城购买记录', 'icon' => '单', 'action' => 'page:member/orders'],
                        ['label' => '在线留言', 'desc' => '提交联系表单', 'icon' => '服', 'action' => 'page:form/index?slug=contact'],
                    ],
                ],
            ],
            [
                'id'      => 'faq',
                'type'    => 'faq_list',
                'enabled' => true,
                'props'   => [
                    'title' => '常见问题',
                    'items' => [
                        ['q' => '如何登录会员？', 'a' => '点击「微信登录」授权即可。'],
                        ['q' => '如何充值？', 'a' => '登录后进入「会员充值」选择套餐。'],
                    ],
                ],
            ],
            [
                'id'      => 'contact',
                'type'    => 'contact_card',
                'enabled' => true,
                'props'   => [
                    'title'   => '联系我们',
                    'phone'   => $phone,
                    'address' => $address,
                    'note'    => '工作日 9:00-18:00',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function defaultBlocksForPage(string $page): array
    {
        return match ($page) {
            MiniprogramPageConfigService::PAGE_TAGS     => $this->defaultTagsBlocks(),
            MiniprogramPageConfigService::PAGE_PRODUCTS => $this->defaultProductsBlocks(),
            MiniprogramPageConfigService::PAGE_MINE     => $this->defaultMineBlocks(),
            default                                     => $this->defaultHomeBlocks(),
        };
    }

    /** @return list<array<string, mixed>> */
    public function defaultTagsBlocks(): array
    {
        return [
            [
                'id'      => 'hero',
                'type'    => 'page_hero',
                'enabled' => true,
                'props'   => $this->defaultPropsForType('page_hero'),
            ],
            [
                'id'      => 'notice',
                'type'    => 'notice',
                'enabled' => true,
                'props'   => [
                    'text'  => '点击分类可浏览该栏目下的全部内容',
                    'style' => 'info',
                ],
            ],
            [
                'id'      => 'hot',
                'type'    => 'hot_keywords',
                'enabled' => true,
                'props'   => $this->defaultPropsForType('hot_keywords'),
            ],
            [
                'id'      => 'catalog',
                'type'    => 'tag_catalog',
                'enabled' => true,
                'props'   => ['limit' => 100],
            ],
            [
                'id'      => 'news',
                'type'    => 'document_list',
                'enabled' => false,
                'props'   => [
                    'title'   => '分类动态',
                    'tags'    => '',
                    'attr'    => '',
                    'orderby' => 'new',
                    'limit'   => 5,
                    'more'    => 'tab:tags',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function defaultTabBar(): array
    {
        if ($this->miniprogramConfigService->edition() === MiniprogramConfigService::EDITION_SHOP) {
            return $this->defaultShopTabBar();
        }

        return [
            'color'          => '#666666',
            'selected_color' => '',
            'background'     => '#ffffff',
            'border_style'   => 'black',
            'list'           => [
                ['text' => '首页', 'pagePath' => 'pages/home/index'],
                ['text' => '分类', 'pagePath' => 'pages/tags/index'],
                ['text' => '产品', 'pagePath' => 'pages/products/index'],
                ['text' => '我的', 'pagePath' => 'pages/mine/index'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function defaultShopTabBar(): array
    {
        return [
            'color'          => '#666666',
            'selected_color' => '',
            'background'     => '#ffffff',
            'border_style'   => 'black',
            'list'           => [
                ['text' => '商城', 'pagePath' => 'pages/home/index'],
                ['text' => '分类', 'pagePath' => 'pages/tags/index'],
                ['text' => '好物', 'pagePath' => 'pages/products/index'],
                ['text' => '我的', 'pagePath' => 'pages/mine/index'],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function allDefaultProps(): array
    {
        $out = [];
        foreach ($this->blockCatalog() as $row) {
            $type = (string) ($row['type'] ?? '');
            if ($type !== '') {
                $out[$type] = $this->defaultPropsForType($type);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function defaultPropsForType(string $type): array
    {
        return match ($type) {
            'banner' => [
                'source' => 'documents',
                'attr'   => 'recommend',
                'limit'  => 5,
                'slot'   => SiteSlideService::SLOT_HOME_CAROUSEL,
            ],
            'search' => ['placeholder' => '搜索产品、方案与资讯'],
            'quick_nav' => [
                'items' => [
                    ['label' => '产品中心', 'icon' => '产', 'action' => 'tab:products'],
                    ['label' => '方案分类', 'icon' => '案', 'action' => 'tab:tags'],
                    ['label' => '搜索', 'icon' => '搜', 'action' => 'page:search'],
                    ['label' => '会员中心', 'icon' => '我', 'action' => 'tab:mine'],
                ],
            ],
            'intro' => ['show_logo' => true],
            'product_scroll' => [
                'title' => '产品推荐',
                'limit' => 8,
                'tag'   => '',
                'more'  => 'tab:products',
            ],
            'document_list' => [
                'title'        => '最新动态',
                'tags'         => '',
                'attr'         => '',
                'orderby'      => 'new',
                'limit'        => 10,
                'more'         => 'tab:tags',
                'layout'       => 'thumb_left',
                'show_summary' => true,
                'show_meta'    => true,
            ],
            'footer' => [],
            'title_bar' => [
                'title'    => '栏目标题',
                'subtitle' => '',
                'more'     => '',
            ],
            'divider' => ['height' => 16, 'style' => 'line'],
            'notice' => [
                'text'  => '欢迎访问，了解更多产品与服务',
                'style' => 'info',
            ],
            'rich_text' => [
                'content' => '在此输入自定义文案，支持换行。',
            ],
            'image_grid' => [
                'attr'    => 'recommend',
                'limit'   => 4,
                'columns' => 2,
            ],
            'spacer' => ['height' => 24],
            'tag_list' => [
                'title' => '热门分类',
                'limit' => 12,
                'more'  => 'tab:tags',
            ],
            'tag_catalog' => [
                'limit' => 100,
            ],
            'page_hero' => [
                'title'      => '内容分类',
                'subtitle'   => '按业务场景浏览文章与资料',
                'show_stat'  => true,
            ],
            'cta_button' => [
                'title'       => '需要定制方案？',
                'subtitle'    => '联系顾问获取一对一支持',
                'button_text' => '立即咨询',
                'action'      => 'tab:mine',
            ],
            'stat_row' => [
                'items' => [
                    ['label' => '服务客户', 'value' => '500+'],
                    ['label' => '产品型号', 'value' => '120+'],
                    ['label' => '行业案例', 'value' => '80+'],
                ],
            ],
            'product_grid' => [
                'title'   => '精选产品',
                'limit'   => 6,
                'tag'     => '',
                'columns' => 2,
                'more'    => 'tab:products',
            ],
            'hot_keywords' => [
                'title'    => '热门搜索',
                'keywords' => '测控仪器,系统集成,技术资料,产品选型',
            ],
            'featured_doc' => [
                'title' => '推荐阅读',
                'attr'  => 'recommend',
                'style' => 'horizontal',
            ],
            'contact_card' => [
                'title'   => '联系我们',
                'phone'   => '',
                'address' => '',
                'note'    => '工作日 9:00-18:00',
            ],
            'nav_list' => [
                'items' => [
                    ['label' => '产品中心', 'desc' => '浏览全部产品', 'icon' => '产', 'action' => 'tab:products'],
                    ['label' => '资料下载', 'desc' => '技术文档与手册', 'icon' => '载', 'action' => 'page:search'],
                    ['label' => '会员中心', 'desc' => '登录与充值', 'icon' => '我', 'action' => 'tab:mine'],
                ],
            ],
            'quote' => [
                'text'   => '以可靠测控技术，助力产业数字化升级。',
                'author' => '— 企业愿景',
                'style'  => 'accent',
            ],
            'feature_row' => [
                'title' => '我们的优势',
                'items' => [
                    ['icon' => '专', 'label' => '专业团队', 'desc' => '十年行业经验'],
                    ['icon' => '快', 'label' => '快速响应', 'desc' => '7×24 技术支持'],
                    ['icon' => '稳', 'label' => '稳定可靠', 'desc' => '严苛品质管控'],
                ],
            ],
            'link_card' => [
                'image_url' => '',
                'title'     => '探索完整产品目录',
                'subtitle'  => '浏览测控仪器与系统集成方案',
                'action'    => 'tab:products',
            ],
            'faq_list' => [
                'title' => '常见问题',
                'items' => [
                    ['q' => '如何获取产品报价？', 'a' => '请通过「联系我们」或拨打客服电话。'],
                    ['q' => '是否支持定制开发？', 'a' => '支持，请联系销售顾问说明需求。'],
                ],
            ],
            'steps_row' => [
                'title' => '合作流程',
                'items' => [
                    ['step' => '1', 'title' => '需求沟通', 'desc' => '了解应用场景与指标'],
                    ['step' => '2', 'title' => '方案设计', 'desc' => '提供选型与集成建议'],
                    ['step' => '3', 'title' => '交付验收', 'desc' => '安装调试与培训支持'],
                ],
            ],
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    public function blockCatalog(): array
    {
        return [
            ['type' => 'title_bar', 'label' => '栏目标题', 'desc' => '分区标题', 'icon' => 'title', 'category' => 'basic', 'pages' => ['home', 'tags', 'products', 'mine']],
            ['type' => 'notice', 'label' => '公告条', 'desc' => '单行提示', 'icon' => 'bell', 'category' => 'basic', 'pages' => ['home', 'tags', 'mine']],
            ['type' => 'rich_text', 'label' => '富文本', 'desc' => '自定义文案', 'icon' => 'text', 'category' => 'basic', 'pages' => ['home', 'tags', 'mine']],
            ['type' => 'page_hero', 'label' => '页头 Hero', 'desc' => '分类页顶栏', 'icon' => 'hero', 'category' => 'basic', 'pages' => ['tags']],
            ['type' => 'search', 'label' => '搜索栏', 'desc' => '跳转搜索', 'icon' => 'search', 'category' => 'nav', 'pages' => ['home', 'products']],
            ['type' => 'quick_nav', 'label' => '快捷入口', 'desc' => '四宫格', 'icon' => 'grid', 'category' => 'nav', 'pages' => ['home', 'mine']],
            ['type' => 'nav_list', 'label' => '导航列表', 'desc' => '纵向菜单', 'icon' => 'list', 'category' => 'nav', 'pages' => ['home', 'tags', 'mine']],
            ['type' => 'hot_keywords', 'label' => '热门搜索', 'desc' => '关键词标签', 'icon' => 'search', 'category' => 'nav', 'pages' => ['home', 'tags']],
            ['type' => 'cta_button', 'label' => '行动按钮', 'desc' => 'CTA 引导', 'icon' => 'cta', 'category' => 'nav', 'pages' => ['home', 'tags', 'mine']],
            ['type' => 'banner', 'label' => '轮播 Banner', 'desc' => '文档/幻灯', 'icon' => 'carousel', 'category' => 'media', 'pages' => ['home']],
            ['type' => 'featured_doc', 'label' => '主推文章', 'desc' => '大图卡片', 'icon' => 'star', 'category' => 'media', 'pages' => ['home', 'tags']],
            ['type' => 'image_grid', 'label' => '图片宫格', 'desc' => '缩略图网格', 'icon' => 'images', 'category' => 'media', 'pages' => ['home']],
            ['type' => 'intro', 'label' => '企业简介', 'desc' => '站点信息', 'icon' => 'building', 'category' => 'content', 'pages' => ['home']],
            ['type' => 'stat_row', 'label' => '数据条', 'desc' => '数字统计', 'icon' => 'stat', 'category' => 'content', 'pages' => ['home']],
            ['type' => 'quote', 'label' => '引用语', 'desc' => '品牌 Slogan', 'icon' => 'quote', 'category' => 'content', 'pages' => ['home', 'tags', 'mine']],
            ['type' => 'feature_row', 'label' => '特性三列', 'desc' => '卖点展示', 'icon' => 'grid', 'category' => 'content', 'pages' => ['home']],
            ['type' => 'link_card', 'label' => '大图卡片', 'desc' => '单图跳转', 'icon' => 'images', 'category' => 'media', 'pages' => ['home', 'tags']],
            ['type' => 'faq_list', 'label' => 'FAQ 问答', 'desc' => '常见问题', 'icon' => 'list', 'category' => 'content', 'pages' => ['home', 'tags', 'mine']],
            ['type' => 'steps_row', 'label' => '步骤流程', 'desc' => '合作/服务流程', 'icon' => 'stat', 'category' => 'content', 'pages' => ['home']],
            ['type' => 'contact_card', 'label' => '联系卡片', 'desc' => '电话地址', 'icon' => 'phone', 'category' => 'content', 'pages' => ['home', 'tags', 'mine']],
            ['type' => 'product_scroll', 'label' => '产品横滑', 'desc' => '品项列表', 'icon' => 'goods', 'category' => 'content', 'pages' => ['home', 'products']],
            ['type' => 'product_grid', 'label' => '产品宫格', 'desc' => '双列网格', 'icon' => 'grid', 'category' => 'content', 'pages' => ['home', 'tags', 'products']],
            ['type' => 'document_list', 'label' => '资讯列表', 'desc' => '文档列表', 'icon' => 'list', 'category' => 'content', 'pages' => ['home', 'tags', 'products', 'mine']],
            ['type' => 'tag_list', 'label' => '分类标签', 'desc' => '标签云', 'icon' => 'tags', 'category' => 'content', 'pages' => ['home']],
            ['type' => 'tag_catalog', 'label' => '分类目录', 'desc' => '分类列表', 'icon' => 'tags', 'category' => 'content', 'pages' => ['tags']],
            ['type' => 'divider', 'label' => '分割线', 'desc' => '分隔/留白', 'icon' => 'minus', 'category' => 'layout', 'pages' => ['home', 'tags', 'products', 'mine']],
            ['type' => 'spacer', 'label' => '空白间距', 'desc' => '纯留白', 'icon' => 'space', 'category' => 'layout', 'pages' => ['home', 'tags', 'products', 'mine']],
            ['type' => 'footer', 'label' => '页脚', 'desc' => '版权备案', 'icon' => 'footer', 'category' => 'layout', 'pages' => ['home']],
        ];
    }

}
