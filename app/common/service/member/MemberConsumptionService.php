<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\MoneyMath;

use app\common\model\Document;
use app\common\model\MemberBalanceLog;
use app\common\model\MemberPointLog;
use app\common\model\PaymentOrder;
use app\common\service\front\FrontUrlRuleService;
use app\common\service\member\PluginMemberConsumptionRegistry;
use app\common\service\weapp\WeappMemberGateway;
use app\common\service\weapp\WeappPluginGateway;
use app\common\support\DbTable;
use app\common\support\ModelRelationLoad;
use think\db\Query;
use think\facade\Db;

/** 会员消费记录（余额/积分/下载等，后台查询） */
class MemberConsumptionService
{

    public function __construct(
        private readonly MemberConfigService $memberConfig,
        private readonly FrontUrlRuleService $frontUrl,
        private readonly WeappMemberGateway $weappMemberGateway,
        private readonly WeappPluginGateway $weappPluginGateway,
        private readonly PluginMemberConsumptionRegistry $consumptionRegistry,
    ) {
    }

    /** @var array<string, string> */
    public const BIZ_LABELS = [
        'balance' => '余额消费',
        'points'  => '积分消费',
    ];

    /**
     * @return array{list:list<array<string,mixed>>,total:int}
     */
    public function listAdmin(int $page = 1, int $limit = 20, int $userId = 0, string $bizType = '', string $keyword = ''): array
    {
        $this->ensureConsumptionPluginsLoaded();
        $page    = max(1, $page);
        $limit   = min(max($limit, 1), 100);
        $bizType = $this->normalizeBizType($bizType);
        $keyword = trim($keyword);

        if ($userId > 0) {
            return $this->listViaOrm($page, $limit, $userId, $bizType, $keyword);
        }
        if ($keyword !== '' && ctype_digit($keyword)) {
            return $this->listViaOrm($page, $limit, (int) $keyword, $bizType, '');
        }
        if ($keyword !== '') {
            return $this->listViaOrm($page, $limit, 0, $bizType, $keyword);
        }

        return $this->listViaUnion($page, $limit, $bizType);
    }

    /**
     * @return array{list:list<array<string,mixed>>,total:int}
     */
    public function listForUser(int $userId, int $page = 1, int $limit = 20, string $bizType = ''): array
    {
        if ($userId < 1) {
            return ['list' => [], 'total' => 0];
        }

        return $this->listAdmin($page, $limit, $userId, $bizType);
    }

    /**
     * 全站消费汇总：SQL UNION 分页（各源 indexed 子查询 + 外层排序 LIMIT）。
     *
     * @return array{list:list<array<string,mixed>>,total:int}
     */
    private function listViaUnion(int $page, int $limit, string $bizType): array
    {
        $branchLimit = $this->unionBranchLimit($page, $limit);
        $parts       = $this->buildUnionParts($bizType, $branchLimit);
        if ($parts === []) {
            return ['list' => [], 'total' => 0];
        }

        $unionSql = implode(' UNION ALL ', $parts);
        $offset   = ($page - 1) * $limit;
        $sql      = "SELECT * FROM ({$unionSql}) AS mc ORDER BY mc.created_at DESC, mc.row_key DESC LIMIT "
            . (int) $limit . ' OFFSET ' . (int) $offset;

        $rows = Db::query($sql);
        $rows = array_map(
            static fn (mixed $row): array => is_array($row) ? $row : (array) $row,
            $rows ?: [],
        );
        $this->hydrateUnionRows($rows);

        $list = $this->mapConsumptionList($rows);

        return [
            'list'  => $list,
            'total' => $this->countSiteWideTotal($bizType, ''),
        ];
    }

    private function unionBranchLimit(int $page, int $limit): int
    {
        return min(5000, max($limit * 4, $page * $limit * 2));
    }

    /**
     * @return list<string>
     */
    private function buildUnionParts(string $bizType, int $branchLimit): array
    {
        $branchLimit = max(1, min(5000, $branchLimit));
        $parts       = [];
        $registry    = $this->consumptionRegistry();
        $pointTypes  = $registry->pointBizTypes();
        $paidTypes   = $registry->paidBizTypes();

        if ($bizType === '' || $bizType === 'balance') {
            $part = $this->unionBalancePart($branchLimit);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if ($bizType === '' || $bizType === 'points' || in_array($bizType, $pointTypes, true)) {
            $part = $this->unionPointPart($bizType, $branchLimit);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if ($bizType === '' || in_array($bizType, $paidTypes, true)) {
            $part = $this->unionDownloadPurchasePart($branchLimit);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if ($bizType === '' || in_array($bizType, $this->payOrderPaidBizTypes(), true)) {
            $part = (string) $this->invokeConsumptionBridge('unionPayOrderPart', [$branchLimit, $bizType], '');
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    private function unionBalancePart(int $branchLimit): string
    {
        if (!DbTable::modelExists(MemberBalanceLog::class)) {
            return '';
        }
        $table = (new MemberBalanceLog())->getTable();

        return "(SELECT CONCAT('balance-', `id`) AS row_key, 'balance' AS biz_type, `id` AS ref_id, `user_id`,"
            . " ABS(`delta`) AS amount, 'yuan' AS unit, `reason` AS title, '' AS extra, `created_at`"
            . " FROM `{$table}` WHERE `delta` < 0 AND `admin_id` = 0"
            . " ORDER BY `created_at` DESC, `id` DESC LIMIT {$branchLimit})";
    }

    private function unionPointPart(string $bizTypeFilter, int $branchLimit): string
    {
        if (!DbTable::modelExists(MemberPointLog::class)) {
            return '';
        }
        $registry       = $this->consumptionRegistry();
        $pluginPointTypes = $registry->pointBizTypes();
        if ($bizTypeFilter !== '' && $bizTypeFilter !== 'points' && !in_array($bizTypeFilter, $pluginPointTypes, true)) {
            return '';
        }
        $table = (new MemberPointLog())->getTable();
        $where = '`delta` < 0 AND `admin_id` = 0';
        if ($bizTypeFilter === 'points') {
            $exclude = $this->pointUnlockMatchSql();
            if ($exclude !== '') {
                $where .= " AND NOT ({$exclude})";
            }
        } elseif (in_array($bizTypeFilter, $pluginPointTypes, true)) {
            $match = $this->pointUnlockMatchSqlForBizType($bizTypeFilter);
            if ($match === '0') {
                return '';
            }
            $where .= " AND {$match}";
        }
        $bizExpr = $this->pointBizTypeCaseExpr();

        return "(SELECT CONCAT('points-', `id`) AS row_key, {$bizExpr} AS biz_type, `id` AS ref_id, `user_id`,"
            . " ABS(`delta`) AS amount, 'points' AS unit, `reason` AS title, '' AS extra, `created_at`"
            . " FROM `{$table}` WHERE {$where}"
            . " ORDER BY `created_at` DESC, `id` DESC LIMIT {$branchLimit})";
    }

    private function unionDownloadPurchasePart(int $branchLimit): string
    {
        return (string) $this->invokeConsumptionBridge('unionPurchasePart', [$branchLimit], '');
    }

    /**
     * @return list<string>
     */
    private function payOrderPaidBizTypes(): array
    {
        $types = [];
        foreach ($this->consumptionRegistry()->identifiers() as $identifier) {
            $meta = $this->consumptionRegistry()->meta($identifier);
            if (($meta['paid_source'] ?? '') !== 'pay_order') {
                continue;
            }
            $type = trim((string) ($meta['paid_biz_type'] ?? ''));
            if ($type !== '') {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    private function isPayOrderBizType(string $bizType): bool
    {
        return $bizType !== '' && in_array($bizType, $this->payOrderPaidBizTypes(), true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function hydrateUnionRows(array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        $userIds = [];
        $payIds  = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = $uid;
            }
            if ($this->isPayOrderBizType((string) ($row['biz_type'] ?? ''))) {
                $refId = (int) ($row['ref_id'] ?? 0);
                if ($refId > 0) {
                    $payIds[$refId] = $refId;
                }
            }
        }

        $users = [];
        if ($userIds !== []) {
            $users = ModelRelationLoad::indexUsersBasicByIds(array_values($userIds));
        }

        $payTitles = [];
        if ($payIds !== [] && DbTable::modelExists(PaymentOrder::class)) {
            foreach (PaymentOrder::whereIn('id', array_values($payIds))
                ->field('id,payload_json')
                ->select()
                ->toArray() as $payRow) {
                if (!is_array($payRow)) {
                    continue;
                }
                $payload = json_decode((string) ($payRow['payload_json'] ?? ''), true);
                $title   = is_array($payload) ? trim((string) ($payload['title'] ?? '')) : '';
                if ($title !== '') {
                    $payTitles[(int) $payRow['id']] = $title;
                }
            }
        }

        foreach ($rows as &$row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0 && isset($users[$uid])) {
                $row['username'] = (string) ($users[$uid]['username'] ?? '');
                $row['nickname'] = (string) ($users[$uid]['nickname'] ?? '');
            }
            if ($this->isPayOrderBizType((string) ($row['biz_type'] ?? ''))) {
                $refId = (int) ($row['ref_id'] ?? 0);
                if ($refId > 0 && isset($payTitles[$refId])) {
                    $row['title'] = $payTitles[$refId];
                }
            }
        }
        unset($row);
    }

    /**
     * 单会员 / 带关键词：各源 ORM 查询后在 PHP 合并排序分页。
     *
     * @return array{list:list<array<string,mixed>>,total:int}
     */
    private function listViaOrm(int $page, int $limit, int $userId, string $bizType, string $keyword): array
    {
        $rows = [];
        if ($bizType === '' || $bizType === 'balance') {
            $rows = array_merge($rows, $this->fetchBalanceRows($userId, $keyword));
        }
        $registry   = $this->consumptionRegistry();
        $pointTypes = $registry->pointBizTypes();
        $paidTypes  = $registry->paidBizTypes();
        if ($bizType === '' || $bizType === 'points' || in_array($bizType, $pointTypes, true)) {
            $rows = array_merge($rows, $this->fetchPointRows($userId, $keyword, $bizType));
        }
        if ($bizType === '' || in_array($bizType, $paidTypes, true)) {
            $rows = array_merge($rows, $this->fetchDownloadPurchaseRows($userId, $keyword));
        }
        if ($bizType === '' || in_array($bizType, $this->payOrderPaidBizTypes(), true)) {
            $rows = array_merge(
                $rows,
                (array) $this->invokeConsumptionBridge('fetchPayOrderRows', [$userId, $keyword, $bizType], []),
            );
        }

        if ($bizType !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => (string) ($row['biz_type'] ?? '') === $bizType
            ));
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));

            return $cmp !== 0 ? $cmp : strcmp((string) ($b['row_key'] ?? ''), (string) ($a['row_key'] ?? ''));
        });

        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $limit, $limit);
        $list  = $this->mapConsumptionList($slice);

        return ['list' => $list, 'total' => $total];
    }

    private function countSiteWideTotal(string $bizType, string $keyword): int
    {
        if ($keyword !== '') {
            return 0;
        }
        $total = 0;
        if ($bizType === '' || $bizType === 'balance') {
            $total += $this->countBalanceConsumption(0, '');
        }
        $registry   = $this->consumptionRegistry();
        $pointTypes = $registry->pointBizTypes();
        $paidTypes  = $registry->paidBizTypes();
        if ($bizType === '' || $bizType === 'points' || in_array($bizType, $pointTypes, true)) {
            $total += $this->countPointConsumption(0, '', $bizType);
        }
        if ($bizType === '' || in_array($bizType, $paidTypes, true)) {
            $total += $this->countDownloadPurchaseConsumption(0, '');
        }
        if ($bizType === '' || in_array($bizType, $this->payOrderPaidBizTypes(), true)) {
            $total += (int) $this->invokeConsumptionBridge('countPayOrders', [0, '', $bizType], 0);
        }

        return $total;
    }

    private function countBalanceConsumption(int $userId, string $keyword): int
    {
        if (!DbTable::modelExists(MemberBalanceLog::class)) {
            return 0;
        }
        $q = MemberBalanceLog::where('delta', '<', 0)->where('admin_id', 0);
        $this->applyUserScope($q, $userId, 'user_id');
        $this->applyKeywordFilterWithRelations($q, $keyword, 'reason', 'user_id');

        return (int) $q->count();
    }

    private function countPointConsumption(int $userId, string $keyword, string $bizTypeFilter): int
    {
        if (!DbTable::modelExists(MemberPointLog::class)) {
            return 0;
        }
        $registry         = $this->consumptionRegistry();
        $pluginPointTypes = $registry->pointBizTypes();
        if ($bizTypeFilter !== '' && $bizTypeFilter !== 'points' && !in_array($bizTypeFilter, $pluginPointTypes, true)) {
            return 0;
        }
        $q = MemberPointLog::where('delta', '<', 0)->where('admin_id', 0);
        $this->applyUserScope($q, $userId, 'user_id');
        $this->applyKeywordFilterWithRelations($q, $keyword, 'reason', 'user_id');
        $this->applyPointUnlockBizFilter($q, $bizTypeFilter);

        return (int) $q->count();
    }

    private function countDownloadPurchaseConsumption(int $userId, string $keyword): int
    {
        $result = $this->invokeConsumptionBridge(
            'countPurchases',
            [
                $userId,
                $keyword,
                fn (Query $q, int $uid, string $field) => $this->applyUserScope($q, $uid, $field),
                fn (Query $q, string $kw, ?string $local, string $userField, ?string $rel) => $this->applyKeywordFilterWithRelations($q, $kw, $local, $userField, $rel),
            ],
            0,
        );

        return (int) $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchBalanceRows(int $userId, string $keyword): array
    {
        if (!DbTable::modelExists(MemberBalanceLog::class)) {
            return [];
        }

        $q = MemberBalanceLog::with(['user' => static function ($userQuery): void {
            $userQuery->field('id,username,nickname');
        }])
            ->where('delta', '<', 0)
            ->where('admin_id', 0)
            ->order('id', 'desc');
        $this->applyUserScope($q, $userId, 'user_id');
        $this->applyKeywordFilterWithRelations($q, $keyword, 'reason', 'user_id');

        $out = [];
        foreach ($q->field('id,user_id,delta,reason,created_at')->select() as $item) {
            $row = ModelRelationLoad::mergeBelongsTo($item, 'user', ['username', 'nickname']);
            $out[] = [
                'row_key'    => 'balance-' . $row['id'],
                'biz_type'   => 'balance',
                'ref_id'     => (int) $row['id'],
                'user_id'    => (int) $row['user_id'],
                'amount'     => abs((float) ($row['delta'] ?? 0)),
                'unit'       => 'yuan',
                'title'      => (string) ($row['reason'] ?? ''),
                'extra'      => '',
                'created_at' => (string) ($row['created_at'] ?? ''),
                'username'   => (string) ($row['username'] ?? ''),
                'nickname'   => (string) ($row['nickname'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPointRows(int $userId, string $keyword, string $bizTypeFilter): array
    {
        if (!DbTable::modelExists(MemberPointLog::class)) {
            return [];
        }

        $q = MemberPointLog::with(['user' => static function ($userQuery): void {
            $userQuery->field('id,username,nickname');
        }])
            ->where('delta', '<', 0)
            ->where('admin_id', 0)
            ->order('id', 'desc');
        $this->applyUserScope($q, $userId, 'user_id');
        $this->applyKeywordFilterWithRelations($q, $keyword, 'reason', 'user_id');

        $out = [];
        foreach ($q->field('id,user_id,delta,reason,created_at')->select() as $item) {
            $row    = ModelRelationLoad::mergeBelongsTo($item, 'user', ['username', 'nickname']);
            $reason = (string) ($row['reason'] ?? '');
            $biz    = $this->resolvePointBizType($reason);
            $pointTypes = $this->consumptionRegistry()->pointBizTypes();
            if ($bizTypeFilter === 'points' && in_array($biz, $pointTypes, true)) {
                continue;
            }
            if ($bizTypeFilter !== '' && $bizTypeFilter !== 'points' && $biz !== $bizTypeFilter) {
                continue;
            }
            $out[] = [
                'row_key'    => 'points-' . $row['id'],
                'biz_type'   => $biz,
                'ref_id'     => (int) $row['id'],
                'user_id'    => (int) $row['user_id'],
                'amount'     => abs((int) ($row['delta'] ?? 0)),
                'unit'       => 'points',
                'title'      => $reason,
                'extra'      => '',
                'created_at' => (string) ($row['created_at'] ?? ''),
                'username'   => (string) ($row['username'] ?? ''),
                'nickname'   => (string) ($row['nickname'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchDownloadPurchaseRows(int $userId, string $keyword): array
    {
        $result = $this->invokeConsumptionBridge(
            'fetchPurchaseRows',
            [
                $userId,
                $keyword,
                fn (Query $q, int $uid, string $field) => $this->applyUserScope($q, $uid, $field),
                fn (Query $q, string $kw, ?string $local, string $userField, ?string $rel) => $this->applyKeywordFilterWithRelations($q, $kw, $local, $userField, $rel),
            ],
            [],
        );

        return is_array($result) ? $result : [];
    }

    private function applyUserScope(Query $query, int $userId, string $userIdField): void
    {
        if ($userId > 0) {
            $query->where($userIdField, $userId);
        }
    }

    private function applyKeywordFilterWithRelations(
        Query $query,
        string $keyword,
        ?string $localTitleField,
        string $userIdField = 'user_id',
        ?string $relatedTitleRelation = null,
        string $relatedTitleField = 'title',
    ): void {
        if ($keyword === '') {
            return;
        }
        if (ctype_digit($keyword)) {
            $query->where($userIdField, (int) $keyword);

            return;
        }
        $like = '%' . addcslashes($keyword, '%_\\') . '%';
        $userIds = $this->weappMemberGateway->memberUserIdsByUsernameNicknameLike($like);
        $query->where(function (Query $sub) use ($like, $localTitleField, $relatedTitleRelation, $relatedTitleField, $userIdField, $userIds): void {
            $hasClause = false;
            if ($userIds !== []) {
                $sub->whereIn($userIdField, $userIds);
                $hasClause = true;
            }
            if ($localTitleField !== null && $localTitleField !== '') {
                if ($hasClause) {
                    $sub->whereOr($localTitleField, 'like', $like);
                } else {
                    $sub->where($localTitleField, 'like', $like);
                    $hasClause = true;
                }
            }
            if ($relatedTitleRelation === 'bundle' && $this->invokeConsumptionBridge('bundleTableReady', [], false)) {
                $bundleIds = $this->invokeConsumptionBridge('bundleIdsByTitleLike', [$like], []);
                $bundleIds = is_array($bundleIds) ? $bundleIds : [];
                if ($bundleIds !== []) {
                    if ($hasClause) {
                        $sub->whereOr('bundle_id', 'in', $bundleIds);
                    } else {
                        $sub->whereIn('bundle_id', $bundleIds);
                        $hasClause = true;
                    }
                }
            }
            if (!$hasClause) {
                $sub->where($userIdField, 0);
            }
        });
    }

    private function normalizeBizType(string $bizType): string
    {
        $bizType = preg_replace('/[^a-z_]/', '', $bizType) ?? '';

        return in_array($bizType, $this->knownBizTypes(), true) ? $bizType : '';
    }

    /** @return list<string> */
    private function knownBizTypes(): array
    {
        $registry = $this->consumptionRegistry();

        return array_values(array_unique(array_merge(
            array_keys(self::BIZ_LABELS),
            $registry->pointBizTypes(),
            $registry->paidBizTypes(),
        )));
    }

    private function bizLabel(string $biz): string
    {
        $fromRegistry = $this->consumptionRegistry()->bizLabel($biz);
        if ($fromRegistry !== $biz) {
            return $fromRegistry;
        }

        return self::BIZ_LABELS[$biz] ?? $biz;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function mapConsumptionList(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $downloadCtx = $this->buildPluginEnrichmentContext($rows);
        $list        = [];
        foreach ($rows as $row) {
            $list[] = $this->mapConsumptionRow($row, $downloadCtx);
        }

        return $list;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{
     *   bundles: array<int, array<string, mixed>>,
     *   documents: array<int, array<string, mixed>>
     * }
     */
    private function buildPluginEnrichmentContext(array $rows): array
    {
        $bundleIds   = [];
        $documentIds = [];
        foreach ($rows as $row) {
            $biz    = (string) ($row['biz_type'] ?? '');
            $reason = (string) ($row['title'] ?? '');
            if (!$this->isPointPluginConsumption($biz, $reason)) {
                continue;
            }
            $parsed = $this->parsePointUnlockViaRegistry($reason);
            if ($parsed['bundle_id'] > 0) {
                $bundleIds[$parsed['bundle_id']] = $parsed['bundle_id'];
            }
            if ($parsed['document_id'] > 0) {
                $documentIds[$parsed['document_id']] = $parsed['document_id'];
            }
        }

        $bundles = [];
        if ($bundleIds !== []) {
            $loaded = $this->invokeConsumptionBridge('bundlesByIds', [array_values($bundleIds)], []);
            $bundles = is_array($loaded) ? $loaded : [];
            foreach ($bundles as $bundleRow) {
                if (!is_array($bundleRow)) {
                    continue;
                }
                $docId = (int) ($bundleRow['document_id'] ?? 0);
                if ($docId > 0) {
                    $documentIds[$docId] = $docId;
                }
            }
        }

        $documents = [];
        if ($documentIds !== [] && DbTable::modelExists(Document::class)) {
            foreach (Document::whereIn('id', array_values($documentIds))
                ->whereNull('deleted_at')
                ->field('id,title,url_path,html_name,status')
                ->select()
                ->toArray() as $docRow) {
                if (!is_array($docRow)) {
                    continue;
                }
                $id = (int) ($docRow['id'] ?? 0);
                if ($id > 0) {
                    $documents[$id] = $docRow;
                }
            }
        }

        return ['bundles' => $bundles, 'documents' => $documents];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapConsumptionRow(array $row, array $downloadCtx = ['bundles' => [], 'documents' => []]): array
    {
        $biz  = (string) ($row['biz_type'] ?? '');
        $unit = (string) ($row['unit'] ?? '');
        $amt  = $this->formatAmount($row);
        $base = [
            'row_key'     => (string) ($row['row_key'] ?? ''),
            'biz_type'    => $biz,
            'biz_label'   => $this->bizLabel($biz),
            'ref_id'      => (int) ($row['ref_id'] ?? 0),
            'user_id'     => (int) ($row['user_id'] ?? 0),
            'amount'      => $amt,
            'amount_text' => $unit === 'points' ? $amt . ' ' . $this->memberConfig->pointsLabel() : '¥' . $amt,
            'unit'        => $unit,
            'title'       => (string) ($row['title'] ?? ''),
            'extra'       => (string) ($row['extra'] ?? ''),
            'created_at'  => (string) ($row['created_at'] ?? ''),
            'username'    => (string) ($row['username'] ?? ''),
            'nickname'    => (string) ($row['nickname'] ?? ''),
        ];

        return $this->enrichViaPluginRegistry($base, $downloadCtx);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{bundles: array<int, array<string, mixed>>, documents: array<int, array<string, mixed>>} $downloadCtx
     * @return array<string, mixed>
     */
    private function enrichViaPluginRegistry(array $row, array $ctx = ['bundles' => [], 'documents' => []]): array
    {
        $reason = (string) ($row['title'] ?? '');
        $biz    = (string) ($row['biz_type'] ?? '');
        if (!$this->isPointPluginConsumption($biz, $reason)) {
            $row['title_display'] = $reason;
            $row['detail_html']   = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
            $row['has_doc_link']  = 0;

            return $row;
        }
        foreach ($this->consumptionRegistry()->identifiers() as $identifier) {
            $next = $this->consumptionRegistry()->invoke($identifier, 'enrichConsumptionRow', [$row, $ctx], null);
            if (is_array($next)) {
                return $next;
            }
        }
        $row['title_display'] = $reason;
        $row['detail_html']   = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
        $row['has_doc_link']  = 0;

        return $row;
    }

    private function isPointPluginConsumption(string $biz, string $reason): bool
    {
        if (in_array($biz, $this->consumptionRegistry()->pointBizTypes(), true)) {
            return true;
        }

        return $this->isPluginPointUnlockReason($reason);
    }

    private function isPluginPointUnlockReason(string $reason): bool
    {
        $registry = $this->consumptionRegistry();
        foreach ($registry->identifiers() as $identifier) {
            if ((bool) $registry->invoke($identifier, 'matchesPointUnlockReason', [$reason], false)) {
                return true;
            }
        }
        foreach ($registry->pointUnlockPrefixes() as $prefix) {
            if ($prefix !== '' && str_starts_with($reason, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function resolvePointBizType(string $reason): string
    {
        $registry = $this->consumptionRegistry();
        foreach ($registry->identifiers() as $identifier) {
            $biz = $registry->invoke($identifier, 'pointBizTypeForReason', [$reason], '');
            if (is_string($biz) && $biz !== '') {
                return $biz;
            }
        }

        return 'points';
    }

    /**
     * @return array{bundle_id:int,document_id:int,bundle_title:string}
     */
    private function parsePointUnlockViaRegistry(string $reason): array
    {
        $registry = $this->consumptionRegistry();
        foreach ($registry->identifiers() as $identifier) {
            $parsed = $registry->invoke($identifier, 'parsePointUnlockReason', [$reason], null);
            if (!is_array($parsed)) {
                continue;
            }
            $bundleId   = (int) ($parsed['bundle_id'] ?? 0);
            $documentId = (int) ($parsed['document_id'] ?? 0);
            $title      = trim((string) ($parsed['bundle_title'] ?? ''));
            if ($bundleId > 0 || $documentId > 0 || $title !== '') {
                return [
                    'bundle_id'    => $bundleId,
                    'document_id'  => $documentId,
                    'bundle_title' => $title,
                ];
            }
        }

        return ['bundle_id' => 0, 'document_id' => 0, 'bundle_title' => ''];
    }

    private function buildPluginDetailHtml(
        string $docUrl,
        string $docTitle,
        string $subtitle,
        string $fallback
    ): string {
        if ($docUrl !== '' && $docTitle !== '') {
            $html = '<a href="' . htmlspecialchars($docUrl, ENT_QUOTES, 'UTF-8') . '" class="fw-semibold text-decoration-none">'
                . htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') . '</a>';
            if ($subtitle !== '') {
                $html .= '<div class="text-muted mt-1">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</div>';
            }

            return $html;
        }

        $text = $fallback !== '' ? $fallback : $subtitle;

        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatAmount(array $row): string
    {
        $unit = (string) ($row['unit'] ?? '');
        $amt  = $row['amount'] ?? 0;
        if ($unit === 'points') {
            return (string) (int) $amt;
        }

        return MoneyMath::formatPlain((float) $amt);
    }

    /**
     * @param list<mixed> $args
     */
    private function invokeConsumptionBridge(string $method, array $args = [], mixed $default = null): mixed
    {
        $registry = $this->consumptionRegistry();
        if ($registry->identifiers() === []) {
            return $default;
        }
        if ($method === 'unionPurchasePart' || $method === 'unionPayOrderPart') {
            return $registry->invokeAll($method, $args, $default);
        }
        if ($method === 'countPurchases' || $method === 'countPayOrders') {
            $sum = 0;
            foreach ($registry->identifiers() as $identifier) {
                $sum += (int) $registry->invoke($identifier, $method, $args, 0);
            }

            return $sum;
        }
        if (in_array($method, ['fetchPurchaseRows', 'fetchPayOrderRows', 'bundleIdsByTitleLike'], true)) {
            $merged = [];
            foreach ($registry->identifiers() as $identifier) {
                $part = $registry->invoke($identifier, $method, $args, []);
                if (is_array($part)) {
                    $merged = array_merge($merged, $part);
                }
            }

            return $merged;
        }
        if ($method === 'bundlesByIds') {
            $merged = [];
            foreach ($registry->identifiers() as $identifier) {
                $part = $registry->invoke($identifier, $method, $args, []);
                if (is_array($part)) {
                    $merged = array_replace($merged, $part);
                }
            }

            return $merged;
        }
        if ($method === 'bundleTableReady') {
            foreach ($registry->identifiers() as $identifier) {
                if ($registry->invoke($identifier, $method, $args, false)) {
                    return true;
                }
            }

            return $default;
        }

        return $registry->invokeAll($method, $args, $default);
    }

    private function consumptionRegistry(): PluginMemberConsumptionRegistry
    {
        return $this->consumptionRegistry;
    }

    private function pointUnlockMatchSql(): string
    {
        $patterns = $this->consumptionRegistry()->pointUnlockLikePatterns();
        if ($patterns === []) {
            return '';
        }
        $parts = array_map(
            static fn (string $pattern): string => "`reason` LIKE '" . addslashes($pattern) . "'",
            $patterns,
        );

        return '(' . implode(' OR ', $parts) . ')';
    }

    private function pointUnlockMatchSqlForBizType(string $bizType): string
    {
        $registry = $this->consumptionRegistry();
        foreach ($registry->identifiers() as $identifier) {
            $meta = $registry->meta($identifier);
            if (trim((string) ($meta['point_biz_type'] ?? '')) !== $bizType) {
                continue;
            }
            $parts = [];
            foreach ($meta['point_unlock_like'] ?? [] as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern !== '') {
                    $parts[] = "`reason` LIKE '" . addslashes($pattern) . "'";
                }
            }
            foreach ($meta['point_unlock_prefixes'] ?? [] as $prefix) {
                $prefix = trim((string) $prefix);
                if ($prefix !== '') {
                    $parts[] = "`reason` LIKE '" . addslashes($prefix) . "%'";
                }
            }
            if ($parts !== []) {
                return '(' . implode(' OR ', $parts) . ')';
            }
        }

        return '0';
    }

    private function pointBizTypeCaseExpr(): string
    {
        $registry = $this->consumptionRegistry();
        $whens    = [];
        foreach ($registry->identifiers() as $identifier) {
            $meta    = $registry->meta($identifier);
            $bizType = trim((string) ($meta['point_biz_type'] ?? ''));
            if ($bizType === '') {
                continue;
            }
            $parts = [];
            foreach ($meta['point_unlock_like'] ?? [] as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern !== '') {
                    $parts[] = "`reason` LIKE '" . addslashes($pattern) . "'";
                }
            }
            foreach ($meta['point_unlock_prefixes'] ?? [] as $prefix) {
                $prefix = trim((string) $prefix);
                if ($prefix !== '') {
                    $parts[] = "`reason` LIKE '" . addslashes($prefix) . "%'";
                }
            }
            if ($parts !== []) {
                $whens[] = 'WHEN ' . implode(' OR ', $parts) . " THEN '" . addslashes($bizType) . "'";
            }
        }
        if ($whens === []) {
            return "'points'";
        }

        return 'CASE ' . implode(' ', $whens) . " ELSE 'points' END";
    }

    private function applyPointUnlockBizFilter(Query $query, string $bizTypeFilter): void
    {
        $registry         = $this->consumptionRegistry();
        $pluginPointTypes = $registry->pointBizTypes();
        if ($bizTypeFilter === 'points') {
            foreach ($registry->pointUnlockPrefixes() as $prefix) {
                if ($prefix !== '') {
                    $query->where('reason', 'not like', $prefix . '%');
                }
            }

            return;
        }
        if (!in_array($bizTypeFilter, $pluginPointTypes, true)) {
            return;
        }
        $query->where(function (Query $sub) use ($bizTypeFilter, $registry): void {
            $meta       = null;
            foreach ($registry->identifiers() as $identifier) {
                $candidate = $registry->meta($identifier);
                if (trim((string) ($candidate['point_biz_type'] ?? '')) === $bizTypeFilter) {
                    $meta = $candidate;
                    break;
                }
            }
            if (!is_array($meta)) {
                $sub->where('id', 0);

                return;
            }
            $first = true;
            foreach ($meta['point_unlock_like'] ?? [] as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern === '') {
                    continue;
                }
                if ($first) {
                    $sub->whereLike('reason', $pattern);
                    $first = false;
                } else {
                    $sub->whereOr('reason', 'like', $pattern);
                }
            }
            foreach ($meta['point_unlock_prefixes'] ?? [] as $prefix) {
                $prefix = trim((string) $prefix);
                if ($prefix === '') {
                    continue;
                }
                if ($first) {
                    $sub->whereLike('reason', $prefix . '%');
                    $first = false;
                } else {
                    $sub->whereOr('reason', 'like', $prefix . '%');
                }
            }
            if ($first) {
                $sub->where('id', 0);
            }
        });
    }

    /** @return array<string, string> */
    public function bizFilterOptions(string $pointsName = '积分'): array
    {
        $this->ensureConsumptionPluginsLoaded();
        $options = [
            ''        => '全部',
            'balance' => self::BIZ_LABELS['balance'],
            'points'  => $pointsName . '消费',
        ];
        $registry = $this->consumptionRegistry();
        foreach ($registry->identifiers() as $identifier) {
            $meta      = $registry->meta($identifier);
            $pointType = trim((string) ($meta['point_biz_type'] ?? ''));
            if ($pointType !== '') {
                $label = trim((string) ($meta['point_biz_label'] ?? ''));
                $options[$pointType] = $label !== ''
                    ? str_replace('积分', $pointsName, $label)
                    : ($pointsName . '·' . $identifier);
            }
            $paidType = trim((string) ($meta['paid_biz_type'] ?? ''));
            if ($paidType !== '') {
                $label = trim((string) ($meta['paid_biz_label'] ?? ''));
                $options[$paidType] = $label !== '' ? $label : ('付费·' . $identifier);
            }
        }

        return $options;
    }

    private function ensureConsumptionPluginsLoaded(): void
    {
        foreach ($this->consumptionRegistry->identifiers() as $identifier) {
            $this->weappPluginGateway->pluginRegisterAutoloadPublic($identifier);
        }
    }
}
