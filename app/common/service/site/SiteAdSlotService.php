<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\site\SiteModeService;
use app\common\service\site\SiteSlideService;

use app\common\model\FloatContactItem;

use app\common\model\SiteAdSlot;
use app\common\model\SiteSlide;
use app\common\service\infra\MetaSqlCacheService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\service\template\TemplateFragmentInvalidationMap;

/** 站点广告位（用户自建，模板 {pv:siteads slot="code"}） */
class SiteAdSlotService
{

    public function __construct(
        private readonly MetaSqlCacheService $metaSqlCacheService,
        private readonly SiteSlideService $siteSlideService,
        private readonly SiteModeService $siteModeService,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly TemplateFragmentInvalidationMap $templateFragmentInvalidationMap,
    ) {
    }

    /** @var array<string, array<string, mixed>>|null code => row */
    private static ?array $effectiveSlotMap = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    private function effectiveSlotMap(): array
    {
        if (self::$effectiveSlotMap !== null) {
            return self::$effectiveSlotMap;
        }

        /** @var array<string, array<string, mixed>> $map */
        $map = $this->metaSqlCacheService->remember('site_ad_slots_pub', function (): array {
            $out = [];
            foreach (SiteAdSlot::where('status', 1)->select()->toArray() as $row) {
                $code = $this->normalizeCode((string) ($row['code'] ?? ''));
                if ($code !== '' && $this->isRowEffectiveNow($row)) {
                    $out[$code] = $row;
                }
            }

            return $out;
        });
        self::$effectiveSlotMap = $map;

        return self::$effectiveSlotMap;
    }

    /**
     * @return array<string, string> code => name（启用位）
     */
    public function codeLabelMap(): array
    {
        $out = [];
        foreach ($this->listActiveRows() as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code !== '') {
                $out[$code] = (string) ($row['name'] ?? $code);
            }
        }

        return $out;
    }

    public function codeExists(string $code): bool
    {
        $code = $this->normalizeCode($code);

        return $code !== '' && isset($this->effectiveSlotMap()[$code]);
    }

    /** 后台：库中存在即可（不要求当前在有效时段内） */
    public function codeExistsForAdmin(string $code): bool
    {
        return $this->findRowByCode($code) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRowByCode(string $code): ?array
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return null;
        }
        $row = SiteAdSlot::where('code', $code)->find()?->toArray();

        return $row ?: null;
    }

    public function isSlotEffectiveByCode(string $code): bool
    {
        return $this->codeExists($code);
    }

    /** 安装灌种 / 后台变更后：清广告位前台缓存 */
    public function bustPublicCache(): void
    {
        self::$effectiveSlotMap = null;
        $this->metaSqlCacheService->forget('site_ad_slots_pub');
    }

    public function defaultCreativeTypeByCode(string $code): string
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return SiteSlideService::TYPE_IMAGE_TEXT;
        }
        $row = $this->findRowByCode($code);
        if ($row) {
            return $this->siteSlideService->normalizeCreativeType(
                (string) ($row['default_creative_type'] ?? ''),
            );
        }

        return SiteSlideService::TYPE_IMAGE_TEXT;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isRowEffectiveNow(array $row): bool
    {
        if ((int) ($row['status'] ?? 1) !== 1) {
            return false;
        }
        $now   = time();
        $start = trim((string) ($row['effective_start_at'] ?? ''));
        $end   = trim((string) ($row['effective_end_at'] ?? ''));
        if ($start !== '' && strtotime($start) > $now) {
            return false;
        }
        if ($end !== '' && strtotime($end) < $now) {
            return false;
        }

        return true;
    }

    /**
     * @return array{start:?string,end:?string,error?:string}
     */
    public function normalizeEffectiveRange(string $start, string $end): array
    {
        $startRaw = trim($start);
        $endRaw   = trim($end);
        $startVal = null;
        $endVal   = null;
        if ($startRaw !== '') {
            $ts = strtotime($startRaw);
            if ($ts === false) {
                return ['start' => null, 'end' => null, 'error' => '有效开始时间格式无效'];
            }
            $startVal = AppTime::format('Y-m-d H:i:s', $ts);
        }
        if ($endRaw !== '') {
            $te = strtotime($endRaw);
            if ($te === false) {
                return ['start' => null, 'end' => null, 'error' => '有效结束时间格式无效'];
            }
            $endVal = AppTime::format('Y-m-d H:i:s', $te);
        }
        if ($startVal !== null && $endVal !== null && strtotime($startVal) > strtotime($endVal)) {
            return ['start' => null, 'end' => null, 'error' => '开始时间不能晚于结束时间'];
        }

        return ['start' => $startVal, 'end' => $endVal];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function formatEffectiveText(array $row): string
    {
        $start = trim((string) ($row['effective_start_at'] ?? ''));
        $end   = trim((string) ($row['effective_end_at'] ?? ''));
        if ($start === '' && $end === '') {
            return '长期有效';
        }
        if ($start !== '' && $end !== '') {
            return $start . ' ~ ' . $end;
        }

        return $start !== '' ? $start . ' 起' : '至 ' . $end;
    }

    public function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
        $code = trim($code, '_');

        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{0,62}$/', $code)) {
            return '';
        }

        return $code;
    }

    public function codeFromName(string $name): string
    {
        $base = $this->normalizeCode($name);
        if ($base !== '') {
            return $base;
        }
        $ascii = strtolower(trim($name));
        $ascii = preg_replace('/[^a-z0-9]+/', '_', $ascii) ?? '';
        $ascii = trim($ascii, '_');
        if ($ascii !== '' && preg_match('/^[a-z][a-z0-9_]{0,62}$/', $ascii)) {
            return $ascii;
        }

        return 'ad_' . AppTime::format('ymd') . '_' . substr((string) mt_rand(1000, 9999), -4);
    }

    /** 模板粘贴用：单行自闭合，引擎按默认结构输出该位素材 */
    public function callTagInvoke(string $code): string
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return '';
        }

        return '{pv:siteads slot="' . $code . '" /}';
    }

    /** 列表/复制推荐标签（同 callTagInvoke） */
    public function callTagSnippet(string $code, ?string $innerTpl = null): string
    {
        unset($innerTpl);

        return $this->callTagInvoke($code);
    }

    /** 高级：标签内自定义循环体 HTML */
    public function callTagWithInner(string $code, ?string $innerTpl = null): string
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return '';
        }
        $inner = $innerTpl ?? $this->defaultInnerTpl();

        return '{pv:siteads slot="' . $code . "\"}\n" . $inner . "\n{/pv:siteads}";
    }

    public function defaultInnerTpl(): string
    {
        return '<div class="pv-ad-item">'
            . "\n  <a href=\"{\$field.link_url}\" target=\"{\$field.target}\">"
            . "\n    <img src=\"{\$field.image_url}\" alt=\"{\$field.title}\">"
            . "\n  </a>"
            . "\n  {pv:if empty=\"field.title\"}{pv:else}<h3>{\$field.title}</h3>{/pv:if}"
            . "\n  {pv:if empty=\"field.subtitle\"}{pv:else}<p>{\$field.subtitle}</p>{/pv:if}"
            . "\n</div>";
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdmin(): array
    {
        $rows = SiteAdSlot::order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $counts = $this->adCountByCode();
        $types  = $this->siteSlideService->creativeTypeOptions();
        $out    = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $ctype = (string) ($row['default_creative_type'] ?? 'image_text');
            $out[] = [
                'id'                    => (int) ($row['id'] ?? 0),
                'code'                  => $code,
                'name'                  => (string) ($row['name'] ?? ''),
                'remark'                => (string) ($row['remark'] ?? ''),
                'default_creative_type' => $ctype,
                'default_creative_type_text' => $types[$ctype] ?? $ctype,
                'sort'                  => (int) ($row['sort'] ?? 0),
                'status'                => (int) ($row['status'] ?? 1),
                'status_text'           => (int) ($row['status'] ?? 1) === 1 ? '启用' : '禁用',
                'effective_start_at'    => (string) ($row['effective_start_at'] ?? ''),
                'effective_end_at'      => (string) ($row['effective_end_at'] ?? ''),
                'effective_text'        => $this->formatEffectiveText($row),
                'is_effective_now'      => $this->isRowEffectiveNow($row) ? 1 : 0,
                'ad_count'              => (int) ($counts[$code] ?? 0),
                'call_tag'              => $this->callTagSnippet($code),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listActiveRows(): array
    {
        return SiteAdSlot::where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /** @return array<string, int> */
    private function adCountByCode(): array
    {
        $out = [];
        foreach (SiteSlide::column('slot') as $code) {
            $code = (string) $code;
            if ($code !== '') {
                $out[$code] = ($out[$code] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id      = (int) ($data['id'] ?? 0);
        $name    = trim((string) ($data['name'] ?? ''));
        $code    = $this->normalizeCode((string) ($data['code'] ?? ''));
        $remark  = trim((string) ($data['remark'] ?? ''));
        $ctype   = $this->siteSlideService->normalizeCreativeType((string) ($data['default_creative_type'] ?? ''));
        $sort    = (int) ($data['sort'] ?? 0);
        $status  = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
        $eff     = $this->normalizeEffectiveRange(
            (string) ($data['effective_start_at'] ?? ''),
            (string) ($data['effective_end_at'] ?? ''),
        );
        if (!empty($eff['error'])) {
            return ServiceResult::fail((string) $eff['error']);
        }
        $now     = AppTime::now();

        if ($name === '') {
            return ServiceResult::fail('请填写广告位名称');
        }
        if ($code === '') {
            return ServiceResult::fail('请填写调用标识（英文小写、数字、下划线）');
        }
        if (mb_strlen($name) > 120) {
            return ServiceResult::fail('名称过长');
        }

        $dup = SiteAdSlot::where('code', $code);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->find()) {
            return ServiceResult::fail('调用标识已存在');
        }

        $payload = [
            'code'                  => $code,
            'name'                  => $name,
            'remark'                => $remark,
            'default_creative_type' => $ctype,
            'sort'                  => $sort,
            'status'                => $status,
            'effective_start_at'    => $eff['start'],
            'effective_end_at'      => $eff['end'],
            'updated_at'            => $now,
        ];

        if ($id > 0) {
            $row = SiteAdSlot::where('id', $id)->find();
            if (!$row) {
                return ServiceResult::fail('广告位不存在');
            }
            $oldCode = (string) $row['code'];
            SiteAdSlot::where('id', $id)->update($payload);
            if ($oldCode !== '' && $oldCode !== $code) {
                SiteSlide::where('slot', $oldCode)->update(['slot' => $code, 'updated_at' => $now]);
            }
            $this->afterChange();

            return ServiceResult::ok(['id' => $id, 'call_tag' => $this->callTagSnippet($code)], '保存成功');
        }

        $payload['created_at'] = $now;
        $newId = (int) SiteAdSlot::insertGetId($payload);
        $this->afterChange();

        return ServiceResult::ok(['id' => $newId, 'call_tag' => $this->callTagSnippet($code)], '保存成功');
    }

    /**
     * @return ServiceResult
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数无效');
        }
        $row = SiteAdSlot::where('id', $id)->find();
        if (!$row) {
            return ServiceResult::fail('广告位不存在');
        }
        $code = (string) $row['code'];
        $cnt  = (int) SiteSlide::where('slot', $code)->count();
        if ($cnt > 0) {
            return ServiceResult::fail("该广告位下仍有 {$cnt} 条素材，请先删除或迁移后再删广告位");
        }
        SiteAdSlot::where('id', $id)->delete();
        $this->afterChange();

        return ServiceResult::ok(null, '删除成功');
    }

    private function afterChange(): void
    {
        $this->bustPublicCache();
        $this->siteModeService->clearPageCache();
        $this->frontCacheInvalidator->invalidateTemplateFragments($this->templateFragmentInvalidationMap->namesForHomeWidgets());
    }
}
