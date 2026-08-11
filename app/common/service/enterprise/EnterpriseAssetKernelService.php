<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\enterprise;

use app\common\support\AppTime;
use app\common\support\AdminBatchSupport;
use app\common\support\QueryLimit;

use app\common\support\ServiceResult;

use app\common\service\export\ExportImportService;
use app\common\support\DbTable;
use app\common\model\Document;
use app\common\model\EnterpriseAsset;
use app\common\model\EnterpriseEntity;

/** 企业经营资料内核后端（不依赖标书插件 boot） */
class EnterpriseAssetKernelService
{
/** @var list<string> */
    public const ASSET_TYPES = ['cert', 'financial', 'performance', 'personnel', 'product', 'template', 'other'];

    /** @var list<string> */
    public const SCOPES = ['internal', 'product', 'web', 'tender'];

    /** @var array<string, string> */
    public const SCOPE_LABELS = [
        'internal' => '内部经营',
        'tender'   => '投标资料',
        'product'  => '产品对外',
        'web'      => '网站公开',
    ];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        'cert'        => '证照资质',
        'financial'   => '财务信用',
        'performance' => '业绩案例',
        'personnel'   => '人员证书',
        'product'     => '产品资料',
        'template'    => 'Word 母版',
        'other'       => '其它',
    ];

    public static function assetTableName(): string
    {
        return (new EnterpriseAsset())->getTable();
    }

    public static function tableExists(): bool
    {
        return DbTable::modelExists(EnterpriseAsset::class);
    }

    /** @return array{total:int,list:list<array<string,mixed>>} */
    public static function listAdmin(
        int $page = 1,
        int $limit = 20,
        string $keyword = '',
        int $entityId = 0,
        string $assetType = '',
        string $scope = '',
        bool $expiringOnly = false,
    ): array {
        $page  = max(1, $page);
        $limit = min(max($limit, 1), 100);
        $keyword = trim($keyword);
        $assetType = trim($assetType);
        $scope = strtolower(trim($scope));

        $query = EnterpriseAsset::where('status', '<>', 'archived');
        if ($entityId > 0) {
            $query->whereIn('entity_id', [0, $entityId]);
        }
        if ($assetType !== '' && in_array($assetType, self::ASSET_TYPES, true)) {
            $query->where('asset_type', $assetType);
        }
        self::applyScopeFilter($query, $scope);
        if ($expiringOnly) {
            self::applyExpiringSoonFilter($query, 30);
        }
        if ($keyword !== '') {
            app(EnterpriseAssetSearchSupport::class)->applyKeyword($query, $keyword);
        }

        $total = (int) (clone $query)->count();
        $rows  = $query->order('sort', 'asc')->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return ['total' => $total, 'list' => array_map([self::class, 'formatRow'], $rows)];
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public static function save(array $data): ServiceResult
    {
        $id    = (int) ($data['id'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return ServiceResult::fail('请填写资源标题');
        }

        $assetType = strtolower(trim((string) ($data['asset_type'] ?? 'other')));
        if (!in_array($assetType, self::ASSET_TYPES, true)) {
            $assetType = 'other';
        }

        $scopeRaw = $data['scopes'] ?? $data['scope'] ?? 'internal';
        $scope    = self::normalizeScopeStorage(is_array($scopeRaw) ? $scopeRaw : (string) $scopeRaw);
        if ($scope === '') {
            $scope = 'internal';
        }

        $validUntil = trim((string) ($data['valid_until'] ?? ''));
        $validUntil = $validUntil !== '' ? $validUntil : null;

        $documentId = max(0, (int) ($data['document_id'] ?? 0));
        $filePath   = self::normalizeStoredFilePath((string) ($data['file_path'] ?? ''));
        if ($documentId > 0) {
            $filePath = '';
        } elseif ($filePath !== '') {
            $documentId = 0;
        }

        $payload = [
            'entity_id'   => max(0, (int) ($data['entity_id'] ?? 0)),
            'asset_type'  => $assetType,
            'title'       => $title,
            'summary'     => trim((string) ($data['summary'] ?? '')),
            'keywords'    => trim((string) ($data['keywords'] ?? '')),
            'document_id' => $documentId,
            'file_path'   => $filePath,
            'valid_until' => $validUntil,
            'scope'       => $scope,
            'status'      => 'active',
            'sort'        => (int) ($data['sort'] ?? 0),
            'updated_at'  => AppTime::now(),
        ];

        if ($id > 0) {
            EnterpriseAsset::where('id', $id)->update($payload);

            return ServiceResult::ok(['id' => $id], '已更新');
        }

        $payload['created_at'] = $payload['updated_at'];
        $newId = (int) EnterpriseAsset::insertGetId($payload);

        return ServiceResult::ok(['id' => $newId], '已创建');
    }

    /** @return ServiceResult */
    public static function archive(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('无效资源');
        }
        EnterpriseAsset::where('id', $id)->update([
            'status'     => 'archived',
            'updated_at' => AppTime::now(),
        ]);

        return ServiceResult::ok(null, '已归档');
    }

    /** 永久删除经营资料索引（uploads 文件实体保留） */
    public static function purge(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('无效资源');
        }
        if (!self::tableExists()) {
            return ServiceResult::fail('企业经营资料表未初始化');
        }
        $deleted = EnterpriseAsset::where('id', $id)->delete();
        if ($deleted < 1) {
            return ServiceResult::fail('资源不存在或已删除');
        }

        return ServiceResult::ok(null, '已删除');
    }

    /**
     * @param list<int> $ids
     */
    public static function archiveBatch(array $ids): ServiceResult
    {
        $count = 0;
        foreach (AdminBatchSupport::normalizeIds($ids) as $id) {
            $result = self::archive($id);
            if ($result->isOk()) {
                $count++;
            }
        }
        if ($count < 1) {
            return ServiceResult::fail('没有可归档的经营资料');
        }

        return ServiceResult::ok(['count' => $count], "已归档 {$count} 条");
    }

    /**
     * @param list<int> $ids
     */
    public static function purgeBatch(array $ids): ServiceResult
    {
        $count = 0;
        foreach (AdminBatchSupport::normalizeIds($ids) as $id) {
            $result = self::purge($id);
            if ($result->isOk()) {
                $count++;
            }
        }
        if ($count < 1) {
            return ServiceResult::fail('没有可删除的经营资料');
        }

        return ServiceResult::ok(['count' => $count], "已删除 {$count} 条");
    }

    public static function releaseEntityAssets(int $entityId): int
    {
        if ($entityId < 1 || !self::tableExists()) {
            return 0;
        }

        return EnterpriseAsset::where('entity_id', $entityId)
            ->where('status', '<>', 'archived')
            ->update([
                'entity_id'  => 0,
                'updated_at' => AppTime::now(),
            ]);
    }

    public static function reassignEntityAssets(int $fromEntityId, int $toEntityId): int
    {
        if ($fromEntityId < 1 || $toEntityId < 1 || !self::tableExists()) {
            return 0;
        }

        return EnterpriseAsset::where('entity_id', $fromEntityId)
            ->where('status', '<>', 'archived')
            ->update([
                'entity_id'  => $toEntityId,
                'updated_at' => AppTime::now(),
            ]);
    }

    public static function archiveEntityAssets(int $entityId): int
    {
        if ($entityId < 1 || !self::tableExists()) {
            return 0;
        }

        return EnterpriseAsset::where('entity_id', $entityId)
            ->where('status', '<>', 'archived')
            ->update([
                'status'     => 'archived',
                'updated_at' => AppTime::now(),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function search(string $query, int $entityId = 0, int $limit = QueryLimit::RELATED_ITEMS): array
    {
        if (!self::tableExists()) {
            return [];
        }

        $query = trim($query);
        $today = AppTime::today();
        $builder = EnterpriseAsset::where('status', 'active')
            ->where(static function ($q) use ($today) {
                $q->whereNull('valid_until')->whereOr('valid_until', '>=', $today);
            });

        self::applyRecallEntityGuard($builder);

        if ($entityId > 0) {
            if (in_array($entityId, self::inactiveEntityIds(), true)) {
                return [];
            }
            $builder->whereIn('entity_id', [0, $entityId]);
        }

        if ($query !== '') {
            app(EnterpriseAssetSearchSupport::class)->applyKeyword($builder, $query);
            $rows = $builder->order('sort', 'asc')->order('id', 'desc')->limit(QueryLimit::SITEMAP_BATCH)->select()->toArray();
            $scored = [];
            foreach ($rows as $row) {
                $score = self::scoreMatch($query, $row);
                if ($score > 0) {
                    $scored[] = ['score' => $score, 'row' => $row];
                }
            }
            usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
            $rows = array_map(static fn ($item) => $item['row'], array_slice($scored, 0, $limit));

            return array_map([self::class, 'formatRow'], $rows);
        }

        $rows = $builder->order('sort', 'asc')->order('id', 'desc')->limit($limit)->select()->toArray();

        return array_map([self::class, 'formatRow'], $rows);
    }

    /** @param array<string, mixed> $row */
    private static function scoreMatch(string $query, array $row): int
    {
        $query = mb_strtolower($query);
        $score = 0;
        foreach (['title', 'summary', 'keywords'] as $field) {
            $text = mb_strtolower(trim((string) ($row[$field] ?? '')));
            if ($text === '') {
                continue;
            }
            if (mb_strpos($query, $text) !== false || mb_strpos($text, $query) !== false) {
                $score += 20;
            }
        }

        $keywordText = mb_strtolower(trim((string) ($row['keywords'] ?? '')));
        foreach (preg_split('/[\s,，、\/]+/u', $keywordText) ?: [] as $kw) {
            $kw = trim($kw);
            if ($kw === '' || mb_strlen($kw) < 2) {
                continue;
            }
            if (mb_strpos($query, $kw) !== false) {
                $score += 8;
            }
        }

        $title = mb_strtolower(trim((string) ($row['title'] ?? '')));
        foreach (preg_split('/[\s,，、\/]+/u', $title) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            if (mb_strpos($query, $part) !== false) {
                $score += 5;
            }
        }

        return $score;
    }

    /** @return array<string, mixed> */
    public static function formatRow(array $row): array
    {
        $row['id']          = (int) ($row['id'] ?? 0);
        $row['entity_id']   = (int) ($row['entity_id'] ?? 0);
        $row['document_id'] = (int) ($row['document_id'] ?? 0);
        $row['sort']        = (int) ($row['sort'] ?? 0);
        $row['type_label']  = self::TYPE_LABELS[(string) ($row['asset_type'] ?? 'other')] ?? '其它';
        $row['scope_label'] = self::scopeLabelText((string) ($row['scope'] ?? ''));
        $row['entity_name'] = self::entityLabel((int) ($row['entity_id'] ?? 0));
        $row['url']         = self::resolveUrl($row);
        self::enrichPresentationFields($row);

        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function enrichPresentationFields(array &$row): void
    {
        $assetType = (string) ($row['asset_type'] ?? 'other');
        $docTitle  = '';
        $preview   = '';
        $mimeKind  = 'none';

        $docId = (int) ($row['document_id'] ?? 0);
        if ($docId > 0) {
            $doc = Document::where('id', $docId)->field('id,url,title,litpic')->find();
            if (is_array($doc)) {
                $docTitle = trim((string) ($doc['title'] ?? ''));
                $litpic   = trim((string) ($doc['litpic'] ?? ''));
                if ($litpic !== '') {
                    $preview  = self::publicAssetPath($litpic);
                    $mimeKind = 'image';
                }
            }
        }

        $path = trim((string) ($row['file_path'] ?? ''));
        $row['file_name'] = $path !== '' ? basename(str_replace('\\', '/', $path)) : '';

        if ($preview === '' && $path !== '') {
            $fileKind = self::fileMimeKind($path);
            if ($fileKind === 'image') {
                $preview  = self::publicAssetPath($path);
                $mimeKind = 'image';
            } elseif ($fileKind !== 'none') {
                $mimeKind = $fileKind;
            }
        }

        if ($mimeKind === 'none') {
            $mimeKind = self::assetTypeDefaultMimeKind($assetType);
        }

        $validUntil = trim((string) ($row['valid_until'] ?? ''));
        $row['is_expiring_soon'] = self::isExpiringWithinDays($validUntil, 30);
        $row['document_title']   = $docTitle;
        $row['preview_url']      = $preview;
        $row['mime_kind']        = $mimeKind;
    }

    private static function publicAssetPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return str_starts_with($path, '/') ? $path : '/' . ltrim($path, '/');
    }

    private static function normalizeStoredFilePath(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            $path = parse_url($raw, PHP_URL_PATH);

            return is_string($path) ? ltrim($path, '/') : $raw;
        }

        return ltrim($raw, '/');
    }

    private static function fileMimeKind(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'], true)) {
            return 'image';
        }
        if ($ext === 'pdf') {
            return 'pdf';
        }
        if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv'], true)) {
            return 'doc';
        }

        return 'none';
    }

    private static function assetTypeDefaultMimeKind(string $assetType): string
    {
        return match ($assetType) {
            'cert', 'personnel' => 'image',
            'financial', 'template' => 'doc',
            'performance', 'product' => 'image',
            default => 'none',
        };
    }

    private static function isExpiringWithinDays(string $validUntil, int $days): bool
    {
        if ($validUntil === '') {
            return false;
        }
        $today = AppTime::today();
        $limit = date('Y-m-d', strtotime($today . ' +' . max(0, $days) . ' days'));

        return $validUntil >= $today && $validUntil <= $limit;
    }

    /** @return list<string> */
    public static function parseScopeList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[,，、\s]+/u', strtolower(trim($raw))) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && in_array($part, self::SCOPES, true) && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @param array<int, string>|string $input
     */
    public static function normalizeScopeStorage(array|string $input): string
    {
        if (is_array($input)) {
            $parts = [];
            foreach ($input as $item) {
                foreach (self::parseScopeList((string) $item) as $scope) {
                    if (!in_array($scope, $parts, true)) {
                        $parts[] = $scope;
                    }
                }
            }

            return implode(',', $parts);
        }

        return implode(',', self::parseScopeList($input));
    }

    public static function scopeLabelText(string $stored): string
    {
        $parts = self::parseScopeList($stored);
        if ($parts === []) {
            return '—';
        }
        $labels = array_map(
            static fn (string $id) => self::SCOPE_LABELS[$id] ?? $id,
            $parts,
        );

        return implode('、', $labels);
    }

    /** @param array<string, mixed> $row */
    private static function resolveUrl(array $row): string
    {
        $docId = (int) ($row['document_id'] ?? 0);
        if ($docId > 0) {
            $doc = Document::where('id', $docId)->field('id,url')->find();
            if (is_array($doc) && trim((string) ($doc['url'] ?? '')) !== '') {
                return (string) $doc['url'];
            }

            return '/document/' . $docId;
        }

        $path = trim((string) ($row['file_path'] ?? ''));
        if ($path !== '') {
            return str_starts_with($path, '/') ? $path : '/' . ltrim($path, '/');
        }

        return '';
    }

    private static function entityLabel(int $entityId): string
    {
        if ($entityId < 1) {
            return '集团共享';
        }
        static $cache = [];
        if ($cache === []) {
            foreach (EnterpriseEntity::where('status', 1)->field('id,name')->select()->toArray() as $ent) {
                $cache[(int) ($ent['id'] ?? 0)] = (string) ($ent['name'] ?? '');
            }
        }

        return $cache[$entityId] ?? '—';
    }

    /** @return array{total:int,active:int,expiring:int} */
    public static function stats(string $scope = ''): array
    {
        if (!self::tableExists()) {
            return ['total' => 0, 'active' => 0, 'expiring' => 0];
        }

        $today = AppTime::today();
        $soon  = AppTime::format('Y-m-d', strtotime('+30 days'));

        $totalQuery = EnterpriseAsset::where('status', '<>', 'archived');
        self::applyScopeFilter($totalQuery, $scope);
        $total = (int) (clone $totalQuery)->count();

        $activeQuery = EnterpriseAsset::where('status', 'active');
        self::applyScopeFilter($activeQuery, $scope);
        $active = (int) (clone $activeQuery)->count();

        $expiringQuery = EnterpriseAsset::where('status', 'active');
        self::applyScopeFilter($expiringQuery, $scope);
        self::applyExpiringSoonFilter($expiringQuery, 30);
        $expiring = (int) (clone $expiringQuery)->count();

        return ['total' => $total, 'active' => $active, 'expiring' => $expiring];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{filename:string,content:string}
     */
    public static function exportAdminCsv(array $params = []): array
    {
        $headers = [
            'ID',
            '标题',
            '类别',
            '主体',
            '适用范围',
            '有效期至',
            '临期',
            '状态',
            '关键词',
            '摘要',
            '文档ID',
            '文件路径',
            '更新时间',
        ];
        $rows    = [];
        $page    = 1;
        $limit   = QueryLimit::LOG_EXPORT_PAGE;
        $maxRows = 50000;

        while (count($rows) < $maxRows) {
            $result = self::listAdmin(
                $page,
                min($limit, $maxRows - count($rows)),
                trim((string) ($params['keyword'] ?? '')),
                (int) ($params['entity_id'] ?? 0),
                trim((string) ($params['asset_type'] ?? '')),
                trim((string) ($params['scope'] ?? '')),
                trim((string) ($params['expiring_only'] ?? '')) === '1',
            );
            foreach ($result['list'] as $row) {
                $rows[] = [
                    $row['id'] ?? '',
                    $row['title'] ?? '',
                    $row['type_label'] ?? $row['asset_type'] ?? '',
                    $row['entity_name'] ?? '',
                    $row['scope_label'] ?? $row['scope'] ?? '',
                    $row['valid_until'] ?? '',
                    !empty($row['is_expiring_soon']) ? '是' : '',
                    $row['status'] ?? '',
                    $row['keywords'] ?? '',
                    $row['summary'] ?? '',
                    $row['document_id'] ?? '',
                    $row['file_path'] ?? '',
                    $row['updated_at'] ?? '',
                ];
            }
            if (($result['list'] ?? []) === [] || count($result['list']) < $limit) {
                break;
            }
            $page++;
        }

        return app(ExportImportService::class)->packCsv('enterprise_resources', $headers, $rows);
    }

    /** @return list<int> */
    private static function inactiveEntityIds(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        if (!DbTable::modelExists(EnterpriseEntity::class)) {
            $cache = [];

            return $cache;
        }
        $cache = array_values(array_unique(array_map(
            static fn ($id) => (int) $id,
            EnterpriseEntity::where('status', '<>', 1)->column('id') ?: [],
        )));

        return $cache;
    }

    /**
     * 插件 / AI 召回：排除已冻结主体名下的经营资料（后台列表仍可管理）。
     *
     * @param \think\db\Query|\think\Model $query
     */
    public static function applyRecallEntityGuard($query): void
    {
        $inactive = self::inactiveEntityIds();
        if ($inactive === []) {
            return;
        }

        $query->where(static function ($q) use ($inactive) {
            $q->where('entity_id', 0)->whereOr(function ($inner) use ($inactive) {
                $inner->where('entity_id', '>', 0)->whereNotIn('entity_id', $inactive);
            });
        });
    }

    /** @param \think\db\Query|\think\Model $query */
    private static function applyExpiringSoonFilter($query, int $days = 30): void
    {
        $today = AppTime::today();
        $soon  = AppTime::format('Y-m-d', strtotime($today . ' +' . max(0, $days) . ' days'));
        $query->where('status', 'active')
            ->whereNotNull('valid_until')
            ->where('valid_until', '>=', $today)
            ->where('valid_until', '<=', $soon);
    }

    /** @param \think\db\Query|\think\Model $query */
    private static function applyScopeFilter($query, string $scope): void
    {
        $scope = strtolower(trim($scope));
        if ($scope === '' || !in_array($scope, self::SCOPES, true)) {
            return;
        }

        $query->where(static function ($q) use ($scope) {
            $q->where('scope', $scope)
                ->whereOr('scope', 'like', $scope . ',%')
                ->whereOr('scope', 'like', '%,' . $scope . ',%')
                ->whereOr('scope', 'like', '%,' . $scope);
        });
    }

    public static function seedDemo(int $entityId): void
    {
        if (!self::tableExists()) {
            return;
        }

        $samples = [
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'cert',
                'scope'       => 'internal',
                'title'       => '营业执照（演示）',
                'summary'     => '有效营业执照扫描件，用于资格证明章节',
                'keywords'    => '营业执照 资质 资格',
                'valid_until' => AppTime::format('Y-m-d', strtotime('+2 years')),
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'cert',
                'scope'       => 'internal',
                'title'       => 'ISO9001 质量管理体系认证',
                'summary'     => '质量管理体系认证证书',
                'keywords'    => 'ISO 认证 资质 质量',
                'valid_until' => AppTime::format('Y-m-d', strtotime('+1 year')),
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'financial',
                'scope'       => 'internal',
                'title'       => '近三年审计报告摘要（演示）',
                'summary'     => '经审计财务报表摘要，供资信章节引用',
                'keywords'    => '财务 审计 资信 报表',
                'valid_until' => AppTime::format('Y-m-d', strtotime('+6 months')),
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'performance',
                'scope'       => 'internal',
                'title'       => '智慧园区物联网平台实施案例',
                'summary'     => '某高新区智慧园区项目，含合同与验收证明',
                'keywords'    => '智慧园区 物联网 业绩 案例 验收',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'product',
                'scope'       => 'product',
                'title'       => '物联网网关产品彩页',
                'summary'     => '边缘网关参数、组网方案与检测报告摘要',
                'keywords'    => '物联网 网关 产品 技术方案',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'personnel',
                'scope'       => 'internal',
                'title'       => '项目经理 PMP 证书',
                'summary'     => '高级项目经理资质与简历',
                'keywords'    => '人员 项目经理 PMP 证书',
            ],
            [
                'entity_id'   => 0,
                'asset_type'  => 'template',
                'scope'       => 'internal',
                'title'       => '投标函 Word 母版',
                'summary'     => '标准投标函、承诺函模板变量占位',
                'keywords'    => '投标函 承诺函 模板 Word',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'cert',
                'scope'       => 'tender',
                'title'       => '投标授权委托书（演示）',
                'summary'     => '法定代表人授权投标代表签署文件',
                'keywords'    => '投标 授权 委托书 标书',
                'valid_until' => AppTime::format('Y-m-d', strtotime('+1 year')),
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'cert',
                'scope'       => 'tender',
                'title'       => '安全生产许可证（演示）',
                'summary'     => '施工类项目常用资质证明',
                'keywords'    => '安全生产 许可证 资质 投标',
                'valid_until' => AppTime::format('Y-m-d', strtotime('+20 days')),
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'performance',
                'scope'       => 'tender',
                'title'       => '政务云平台运维业绩证明',
                'summary'     => '同类政务云项目合同关键页与验收意见',
                'keywords'    => '政务云 运维 业绩 投标 验收',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'financial',
                'scope'       => 'tender',
                'title'       => '银行资信证明（演示）',
                'summary'     => '投标保证金与履约能力说明用',
                'keywords'    => '资信 银行 投标 保证金',
                'valid_until' => AppTime::format('Y-m-d', strtotime('+3 months')),
            ],
            [
                'entity_id'   => 0,
                'asset_type'  => 'template',
                'scope'       => 'tender',
                'title'       => '技术偏离表母版（演示）',
                'summary'     => '标准技术偏离表 Word 模板',
                'keywords'    => '偏离表 技术 模板 标书',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'personnel',
                'scope'       => 'tender',
                'title'       => '技术负责人职称证书（演示）',
                'summary'     => '高级工程师职称与从业经历摘要',
                'keywords'    => '人员 职称 技术负责人 投标',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'other',
                'scope'       => 'tender',
                'title'       => '无重大违法记录声明（演示）',
                'summary'     => '资格预审常用声明函模板',
                'keywords'    => '违法记录 声明 资格 投标',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'cert',
                'scope'       => 'internal,tender',
                'title'       => '高新企业证书（演示·多适用范围）',
                'summary'     => '同时用于内部经营档案与投标资格章节',
                'keywords'    => '高新 资质 投标 内部',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'financial',
                'scope'       => 'tender,product',
                'title'       => '纳税信用 A 级证明（演示·类别测试）',
                'summary'     => '纳税信用等级证明，投标与产品对外均可引用',
                'keywords'    => '纳税 信用 财务 投标',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'performance',
                'scope'       => 'tender',
                'title'       => '教育信息化项目验收报告（演示·类别测试）',
                'summary'     => 'K12 智慧校园项目验收与合同摘要',
                'keywords'    => '教育 信息化 业绩 验收',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'personnel',
                'scope'       => 'internal,tender',
                'title'       => '注册建造师证书（演示·类别测试）',
                'summary'     => '一级建造师注册证书与从业经历',
                'keywords'    => '建造师 人员 证书 投标',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'product',
                'scope'       => 'product',
                'title'       => '数据中台白皮书（演示·类别测试）',
                'summary'     => '产品能力说明与典型客户案例摘要',
                'keywords'    => '数据中台 产品 白皮书',
            ],
            [
                'entity_id'   => 0,
                'asset_type'  => 'template',
                'scope'       => 'tender,internal',
                'title'       => '售后服务承诺函母版（演示·类别测试）',
                'summary'     => '标准售后服务承诺函 Word 变量模板',
                'keywords'    => '售后 承诺函 模板 投标',
            ],
            [
                'entity_id'   => $entityId,
                'asset_type'  => 'other',
                'scope'       => 'internal',
                'title'       => '企业简介一页纸（演示·类别测试）',
                'summary'     => '公司概况、资质与核心能力简介',
                'keywords'    => '简介 企业 其它 经营',
            ],
        ];

        $now = AppTime::now();
        $sort = 0;
        foreach ($samples as $row) {
            $title = (string) ($row['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $exists = EnterpriseAsset::where('title', $title)->find();
            if ($exists !== null) {
                continue;
            }
            $row['status']      = 'active';
            $row['sort']        = $sort;
            $row['document_id'] = 0;
            $row['file_path']   = '';
            $row['created_at']  = $now;
            $row['updated_at']  = $now;
            EnterpriseAsset::insert($row);
            $sort += 10;
        }
    }
}
