<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 站点广告素材（表 site_slides，兼容旧「幻灯片」）
 */
declare(strict_types=1);


namespace app\common\service\site;

use app\common\service\plugin\extension\PluginOfficialProduct;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\site\SiteModeService;
use app\common\service\site\SiteAdSlotService;

use app\common\model\FloatContactItem;

use app\common\service\static\StaticHtmlDispatch;
use app\common\service\static\StaticHtmlService;
use app\common\model\SiteSlide;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\template\TemplateFragmentInvalidationMap;
use app\common\support\HtmlSanitizer;

class SiteSlideService
{

    public function __construct(
        private readonly SiteModeService $siteModeService,
        private readonly StaticHtmlDispatch $staticHtmlDispatch,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly TemplateFragmentInvalidationMap $templateFragmentInvalidationMap,
    ) {
    }

    public const SLOT_HOME_CAROUSEL = 'home_carousel';
    public const SLOT_HOME_HERO     = 'home_hero';
    public const SLOT_SIDEBAR       = 'sidebar';
    public const SLOT_LIST_TOP      = 'list_top';
    public const SLOT_FOOTER_STRIP  = 'footer_strip';
    public const SLOT_POPUP         = 'popup';
    /** @deprecated 用 hostTopbarSlotCode()；槽位码由宿主注入 */
    public const SLOT_WWW_TOPBAR    = 'www_topbar';

    /** 宿主顶栏广告位 code（空=本站无此槽） */
    public static function hostTopbarSlotCode(): string
    {
        $code = PluginOfficialProduct::dispatch('slide_host_topbar_slot_code', [], '');

        return is_string($code) ? trim($code) : '';
    }

    public const TYPE_CAROUSEL    = 'carousel';
    public const TYPE_SINGLE_IMAGE = 'single_image';
    public const TYPE_IMAGE_TEXT  = 'image_text';
    public const TYPE_HTML        = 'html';
    public const TYPE_OVERLAY_CENTER = 'overlay_center';
    public const TYPE_MOURNING       = 'mourning';

    public const DISPLAY_SCOPE_ALL  = 'all';
    public const DISPLAY_SCOPE_HOME = 'home';

    /** @var array<string, list<array<string, mixed>>> */
    private static array $listPublicCache = [];

    /** @var array<string, array<string, mixed>|null> */
    private static array $findPublicSingleCache = [];

    /** @return array<string, string> code => name（来自 site_ad_slots） */
    public function slotOptions(): array
    {
        return app(SiteAdSlotService::class)->codeLabelMap();
    }

    /** @return array<string, string> */
    public function creativeTypeOptions(): array
    {
        $out = [];
        foreach ($this->creativeTypeCatalog() as $item) {
            $out[$item['key']] = $item['label'];
        }

        return $out;
    }

    /**
     * 后台创意类型卡片（含示意说明）
     *
     * @return list<array{key:string,label:string,desc:string}>
     */
    public function creativeTypeCatalog(): array
    {
        return [
            [
                'key'   => self::TYPE_CAROUSEL,
                'label' => '轮播多图',
                'desc'  => '多张图横向轮播，带指示点',
            ],
            [
                'key'   => self::TYPE_SINGLE_IMAGE,
                'label' => '单图横幅',
                'desc'  => '单张横幅图，可带链接',
            ],
            [
                'key'   => self::TYPE_IMAGE_TEXT,
                'label' => '图文',
                'desc'  => '大图 + 标题 + 副标题 + 按钮',
            ],
            [
                'key'   => self::TYPE_HTML,
                'label' => 'HTML 代码',
                'desc'  => '统计/联盟等嵌入代码',
            ],
            [
                'key'   => self::TYPE_OVERLAY_CENTER,
                'label' => '全屏居中弹层',
                'desc'  => '遮罩 + 居中广告，可全站或仅首页；自动注入页脚',
            ],
            [
                'key'   => self::TYPE_MOURNING,
                'label' => '全站悼念',
                'desc'  => '全站黑白 + 顶栏悼念条；重大哀悼日使用',
            ],
        ];
    }

    /** @return list<string> */
    public function overlayCreativeTypes(): array
    {
        return [self::TYPE_OVERLAY_CENTER, self::TYPE_MOURNING];
    }

    public function isOverlayCreativeType(string $type): bool
    {
        return in_array(strtolower(trim($type)), $this->overlayCreativeTypes(), true);
    }

    public function normalizeDisplayScope(string $scope, string $creativeType = ''): string
    {
        if ($creativeType === self::TYPE_MOURNING) {
            return self::DISPLAY_SCOPE_ALL;
        }
        $scope = strtolower(trim($scope));

        return $scope === self::DISPLAY_SCOPE_HOME
            ? self::DISPLAY_SCOPE_HOME
            : self::DISPLAY_SCOPE_ALL;
    }

    public function normalizeSlot(string $slot): string
    {
        $code = app(SiteAdSlotService::class)->normalizeCode($slot);
        if ($code === '') {
            return '';
        }

        return app(SiteAdSlotService::class)->codeExists($code) ? $code : '';
    }

    /** 后台保存/列表：仅校验广告位是否存在 */
    public function normalizeSlotForAdmin(string $slot): string
    {
        $code = app(SiteAdSlotService::class)->normalizeCode($slot);
        if ($code === '') {
            return '';
        }

        return app(SiteAdSlotService::class)->codeExistsForAdmin($code) ? $code : '';
    }

    public function normalizeCreativeType(string $type, string $slot = ''): string
    {
        $type = strtolower(trim($type));
        $allowed = array_keys($this->creativeTypeOptions());
        if (in_array($type, $allowed, true)) {
            return $type;
        }
        if ($slot === self::SLOT_HOME_CAROUSEL) {
            return self::TYPE_CAROUSEL;
        }

        return self::TYPE_SINGLE_IMAGE;
    }

    /**
     * 全屏/悼念类素材（自动注入，供 SiteOverlayAdService）
     *
     * @return list<array<string, mixed>>
     */
    public function listActiveOverlayMaterials(): array
    {
        $query = SiteSlide::where('status', 1)
            ->whereIn('creative_type', $this->overlayCreativeTypes());
        $this->applyEffectiveTimeScope($query);
        $rows = $query->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $out  = [];
        foreach ($rows as $i => $row) {
            $slot = $this->normalizeSlot((string) ($row['slot'] ?? ''));
            if ($slot === '' || !app(SiteAdSlotService::class)->isSlotEffectiveByCode($slot)) {
                continue;
            }
            $creativeType = $this->normalizeCreativeType((string) ($row['creative_type'] ?? ''), $slot);
            if (!$this->isOverlayCreativeType($creativeType)) {
                continue;
            }
            if ($creativeType === self::TYPE_OVERLAY_CENTER && trim((string) ($row['image_url'] ?? '')) === '') {
                continue;
            }
            $out[] = $this->formatPublicRow($row, $i);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPublic(?string $slot = self::SLOT_HOME_CAROUSEL): array
    {
        $slot = $this->normalizeSlot($slot ?? self::SLOT_HOME_CAROUSEL);
        if ($slot === '' || !app(SiteAdSlotService::class)->isSlotEffectiveByCode($slot)) {
            return [];
        }
        if (array_key_exists($slot, self::$listPublicCache)) {
            return self::$listPublicCache[$slot];
        }
        $query = SiteSlide::where('status', 1)->where('slot', $slot);
        $this->applyEffectiveTimeScope($query);
        $this->applyStandardCreativeScope($query);
        $this->applyPublicImageScope($query, $slot);
        $rows = $query->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        $out = [];
        foreach ($rows as $i => $row) {
            $out[] = $this->formatPublicRow($row, $i);
        }

        return self::$listPublicCache[$slot] = $out;
    }

    /**
     * 按当前页面展示范围过滤（display_scope：home / all）
     *
     * @return list<array<string, mixed>>
     */
    public function listPublicForCurrentPage(?string $slot = self::SLOT_HOME_CAROUSEL): array
    {
        $slot = $this->normalizeSlot($slot ?? self::SLOT_HOME_CAROUSEL);
        if ($slot === '') {
            return [];
        }

        $isHome = $this->isCurrentHomePage();
        $out    = [];
        foreach ($this->listPublic($slot) as $row) {
            $scope = (string) ($row['display_scope'] ?? self::DISPLAY_SCOPE_ALL);
            if ($scope === self::DISPLAY_SCOPE_HOME && !$isHome) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    public function isCurrentHomePage(): bool
    {
        $path = app(SiteNavService::class)->currentPath();

        return $path === '/' || $path === '/index.html';
    }

    /**
     * 单图广告位取第一条启用素材（如首页主图）
     *
     * @return array<string, mixed>|null
     */
    public function findPublicSingle(string $slot = self::SLOT_HOME_HERO): ?array
    {
        $slot = $this->normalizeSlot($slot);
        if ($slot === '' || !app(SiteAdSlotService::class)->isSlotEffectiveByCode($slot)) {
            return null;
        }
        if (array_key_exists($slot, self::$findPublicSingleCache)) {
            return self::$findPublicSingleCache[$slot];
        }
        $query = SiteSlide::where('status', 1)->where('slot', $slot);
        $this->applyEffectiveTimeScope($query);
        $this->applyStandardCreativeScope($query);
        $row = $query->order('sort', 'asc')->order('id', 'asc')->find()?->toArray();

        return self::$findPublicSingleCache[$slot] = ($row ? $this->formatPublicRow($row, 0) : null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdmin(?string $slot = null, ?string $creativeType = null): array
    {
        $query = SiteSlide::order('sort', 'asc')->order('id', 'asc');
        if ($slot !== null && $slot !== '') {
            $query->where('slot', $this->normalizeSlotForAdmin($slot));
        }
        if ($creativeType !== null && $creativeType !== '') {
            $query->where('creative_type', $this->normalizeCreativeType($creativeType));
        }
        $rows = $query->select()->toArray();
        $out  = [];
        foreach ($rows as $i => $row) {
            $out[] = $this->formatAdminRow($row, $i);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     * @param mixed $id
     */
    public function findAdmin(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = SiteSlide::where('id', $id)->find()?->toArray();

        return $row ? $this->formatAdminRow($row, 0) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id           = (int) ($data['id'] ?? 0);
        $slot         = $this->normalizeSlotForAdmin((string) ($data['slot'] ?? ''));
        if ($slot === '') {
            return ServiceResult::fail('请选择有效的广告位');
        }
        $creativeType = $this->normalizeCreativeType((string) ($data['creative_type'] ?? ''), $slot);
        $slotDefault  = app(SiteAdSlotService::class)->defaultCreativeTypeByCode($slot);
        if ($this->isOverlayCreativeType($slotDefault)) {
            if ($creativeType !== $slotDefault) {
                return ServiceResult::fail('该广告位仅支持「' . ($this->creativeTypeOptions()[$slotDefault] ?? $slotDefault) . '」素材');
            }
        } elseif ($this->isOverlayCreativeType($creativeType)) {
            return ServiceResult::fail('全屏/悼念类请使用「全站浮层」等专用广告位，模板位不可混用');
        }
        $displayScope = $this->normalizeDisplayScope(
            (string) ($data['display_scope'] ?? self::DISPLAY_SCOPE_ALL),
            $creativeType,
        );
        $title        = trim((string) ($data['title'] ?? ''));
        $subtitle     = trim((string) ($data['subtitle'] ?? ''));
        $imageUrl     = app(\app\common\service\media\MediaUrlService::class)->formatForStorage(
            trim((string) ($data['image_url'] ?? ''))
        );
        $linkUrl      = trim((string) ($data['link_url'] ?? ''));
        $linkText     = trim((string) ($data['link_text'] ?? ''));
        $htmlBody     = trim((string) ($data['html_body'] ?? ''));
        $sort         = (int) ($data['sort'] ?? 0);
        $status       = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        $openNewTab    = !empty($data['open_new_tab']) ? 1 : 0;
        $eff = $this->normalizeEffectiveRange(
            (string) ($data['effective_start_at'] ?? ''),
            (string) ($data['effective_end_at'] ?? ''),
        );
        if (!empty($eff['error'])) {
            return ServiceResult::fail((string) $eff['error']);
        }
        $now          = AppTime::now();

        if ($creativeType === self::TYPE_HTML) {
            if ($htmlBody === '') {
                return ServiceResult::fail('请填写 HTML 或嵌入代码');
            }
            $htmlBody = HtmlSanitizer::cleanArticle($htmlBody);
        } elseif ($creativeType === self::TYPE_MOURNING) {
            if ($title === '') {
                $title = '沉痛悼念';
            }
        } elseif ($creativeType === self::TYPE_OVERLAY_CENTER) {
            if ($imageUrl === '') {
                return ServiceResult::fail('请上传弹层广告图片');
            }
        } elseif ($slot !== '' && $slot === self::hostTopbarSlotCode() && $creativeType === self::TYPE_IMAGE_TEXT && $subtitle === '') {
            return ServiceResult::fail('请填写顶栏公告文案');
        } elseif ($imageUrl === '' && !($slot !== '' && $slot === self::hostTopbarSlotCode())) {
            return ServiceResult::fail('请上传广告图片');
        }

        if ($creativeType === self::TYPE_SINGLE_IMAGE && $status === 1) {
            SiteSlide::where('slot', $slot)
                ->where('creative_type', self::TYPE_SINGLE_IMAGE)
                ->where('id', '<>', $id > 0 ? $id : 0)
                ->update(['status' => 0, 'updated_at' => $now]);
        }
        if ($creativeType === self::TYPE_MOURNING && $status === 1) {
            SiteSlide::where('creative_type', self::TYPE_MOURNING)
                ->where('id', '<>', $id > 0 ? $id : 0)
                ->update(['status' => 0, 'updated_at' => $now]);
        }
        if (mb_strlen($title) > 200) {
            return ServiceResult::fail('标题过长');
        }
        if (mb_strlen($subtitle) > 500) {
            return ServiceResult::fail('副标题过长');
        }
        if (mb_strlen($linkText) > 100) {
            return ServiceResult::fail('按钮文字过长');
        }

        $payload = [
            'slot'           => $slot,
            'creative_type'  => $creativeType,
            'display_scope'  => $displayScope,
            'title'          => $title,
            'subtitle'       => $subtitle,
            'image_url'      => $imageUrl,
            'link_url'       => $linkUrl,
            'link_text'      => $linkText,
            'html_body'      => $htmlBody,
            'sort'                => $sort,
            'status'              => $status,
            'effective_start_at'  => $eff['start'],
            'effective_end_at'    => $eff['end'],
            'open_new_tab'        => $openNewTab,
            'updated_at'          => $now,
        ];

        if ($id > 0) {
            if (!SiteSlide::where('id', $id)->find()) {
                return ServiceResult::fail('广告不存在');
            }
            SiteSlide::where('id', $id)->update($payload);
            $this->afterChange();

            return ServiceResult::ok(['id' => $id], '保存成功');
        }

        $payload['created_at'] = $now;
        $newId = (int) SiteSlide::insertGetId($payload);
        $this->afterChange();

        return ServiceResult::ok(['id' => $newId], '保存成功');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     * @param mixed $sort
     */
    public function updateSortAdmin(int $id, int $sort): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!SiteSlide::where('id', $id)->find()) {
            return ServiceResult::fail('广告不存在');
        }
        SiteSlide::where('id', $id)->update([
            'sort'       => $sort,
            'updated_at' => AppTime::now(),
        ]);
        $this->afterChange();

        return ServiceResult::ok(null, '已更新');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     * @param mixed $status
     */
    public function updateStatusAdmin(int $id, int $status): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!SiteSlide::where('id', $id)->find()) {
            return ServiceResult::fail('广告不存在');
        }
        $status = $status === 1 ? 1 : 0;
        SiteSlide::where('id', $id)->update([
            'status'     => $status,
            'updated_at' => AppTime::now(),
        ]);
        $this->afterChange();

        return ServiceResult::ok(['status' => $status], $status === 1 ? '已启用' : '已禁用');
    }

    /**
     * @return ServiceResult
     * @param mixed $id
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        if (!SiteSlide::where('id', $id)->find()) {
            return ServiceResult::fail('广告不存在');
        }
        SiteSlide::where('id', $id)->delete();
        $this->afterChange();

        return ServiceResult::ok(null, '删除成功');
    }

    private function afterChange(): void
    {
        $this->siteModeService->clearPageCache();
        $this->staticHtmlDispatch->afterNavOrSlideChange();
        $this->frontCacheInvalidator->invalidateTemplateFragments($this->templateFragmentInvalidationMap->namesForHomeWidgets());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPublicRow(array $row, int $index, bool $resolveLink = true): array
    {
        $linkUrl = trim((string) ($row['link_url'] ?? ''));
        $linkText = trim((string) ($row['link_text'] ?? ''));
        if ($linkText === '' && $linkUrl !== '') {
            $linkText = '了解更多';
        }
        if ($resolveLink && $linkUrl !== '') {
            $linkUrl = $this->resolveFrontLinkUrl($linkUrl);
        }

        $slot = $this->normalizeSlot((string) ($row['slot'] ?? self::SLOT_HOME_CAROUSEL));
        $creativeType = $this->normalizeCreativeType((string) ($row['creative_type'] ?? ''), $slot);
        $slots = $this->slotOptions();
        $types = $this->creativeTypeOptions();

        return [
            'id'                 => (int) ($row['id'] ?? 0),
            'slot'               => $slot,
            'slot_text'          => $slots[$slot] ?? $slot,
            'creative_type'      => $creativeType,
            'creative_type_text' => $types[$creativeType] ?? $creativeType,
            'display_scope'      => $this->normalizeDisplayScope(
                (string) ($row['display_scope'] ?? self::DISPLAY_SCOPE_ALL),
                $creativeType,
            ),
            'display_scope_text' => $this->displayScopeText(
                $this->normalizeDisplayScope((string) ($row['display_scope'] ?? self::DISPLAY_SCOPE_ALL), $creativeType),
            ),
            'title'              => (string) ($row['title'] ?? ''),
            'subtitle'           => (string) ($row['subtitle'] ?? ''),
            'image_url'          => (string) ($row['image_url'] ?? ''),
            'link_url'           => $linkUrl,
            'link_text'          => $linkText,
            'html_body'          => (string) ($row['html_body'] ?? ''),
            'open_new_tab'       => (int) ($row['open_new_tab'] ?? 0),
            'target'             => ((int) ($row['open_new_tab'] ?? 0) === 1) ? '_blank' : '_self',
            'index'              => $index,
            'is_first'           => $index === 0 ? 1 : 0,
            'active_class'       => $index === 0 ? 'active' : '',
        ];
    }

    /** 前台出站：站内相对路径走导航解析（动态含 /index.php）；外链原样 */
    private function resolveFrontLinkUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return $url;
        }
        if (preg_match('#^https?://#i', $url) === 1 || str_starts_with($url, '//')) {
            return $url;
        }

        return app(SiteNavService::class)->resolveRouteTarget($url);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatAdminRow(array $row, int $index): array
    {
        return array_merge($this->formatPublicRow($row, $index, false), [
            'sort'                => (int) ($row['sort'] ?? 0),
            'status'              => (int) ($row['status'] ?? 1),
            'status_text'         => (int) ($row['status'] ?? 1) === 1 ? '启用' : '禁用',
            'effective_start_at'  => (string) ($row['effective_start_at'] ?? ''),
            'effective_end_at'    => (string) ($row['effective_end_at'] ?? ''),
            'effective_text'      => $this->formatEffectiveText($row),
            'created_at'          => (string) ($row['created_at'] ?? ''),
            'updated_at'          => (string) ($row['updated_at'] ?? ''),
        ]);
    }

    /**
     * @return array{start:?string,end:?string,error?:string}
     */
    public function normalizeEffectiveRange(string $start, string $end): array
    {
        return app(SiteAdSlotService::class)->normalizeEffectiveRange($start, $end);
    }

    public function formatEffectiveText(array $row): string
    {
        return app(SiteAdSlotService::class)->formatEffectiveText($row);
    }

    /** @param \think\db\Query $query */
    public function applyEffectiveTimeScope($query): void
    {
        $now = AppTime::now();
        $query->where(function ($q) use ($now): void {
            $q->whereNull('effective_start_at')->whereOr('effective_start_at', '<=', $now);
        });
        $query->where(function ($q) use ($now): void {
            $q->whereNull('effective_end_at')->whereOr('effective_end_at', '>=', $now);
        });
    }

    /** @param \think\db\Query $query */
    public function applyStandardCreativeScope($query): void
    {
        $query->whereNotIn('creative_type', $this->overlayCreativeTypes());
    }

    /** @param \think\db\Query $query */
    public function applyPublicImageScope($query, string $slot): void
    {
        if ($slot !== '' && $slot === self::hostTopbarSlotCode()) {
            $query->whereIn('creative_type', [self::TYPE_IMAGE_TEXT, self::TYPE_HTML]);

            return;
        }

        $query->where(function ($q): void {
            $q->where('creative_type', self::TYPE_HTML)
                ->whereOr(function ($q2): void {
                    $q2->where('creative_type', '<>', self::TYPE_HTML)
                        ->where('image_url', '<>', '');
                });
        });
    }

    public function displayScopeText(string $scope): string
    {
        return $scope === self::DISPLAY_SCOPE_HOME ? '仅首页' : '全站';
    }
}
