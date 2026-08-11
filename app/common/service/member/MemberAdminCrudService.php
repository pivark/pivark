<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split from MemberService — MemberAdminCrudService
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\member\MemberViewAsService;
use app\common\service\member\MemberFieldService;
use app\common\service\member\MemberLevelService;
use app\common\service\user\UserService;

use app\common\model\MemberFieldValue;
use app\common\model\UserOauthBinding;
use app\common\model\MemberCancelRequest;
use app\common\model\MemberBalanceLog;
use app\common\model\MemberPointLog;

use think\facade\Request;

use app\common\model\Role;
use app\common\model\User;
use app\common\model\UserRole;
use app\common\service\audit\AuditLogService;
use app\common\service\content\ContentSearchService;
use app\common\support\HtmlSanitizer;
use app\common\support\SiteUrl;
use think\facade\Db;

class MemberAdminCrudService
{

    public function __construct(
        private readonly MemberLevelService $memberLevelService,
        private readonly UserService $userService,
        private readonly MemberFieldService $memberFieldService,
        private readonly MemberViewAsService $memberViewAsService,
        private readonly AuditLogService $auditLogService,
        private readonly MemberRoleCheckService $memberRoleCheckService,
        private readonly ContentSearchService $contentSearch,
        private readonly MemberEnterpriseProfileService $memberEnterpriseProfileService,
    ) {
    }

    /**
     * @param int    $statusFilter -1=全部 0=正常 1=黑名单(禁用)
     * @param string $source       来源：manual|mobile|wechat|qq
     * @param string $accountKind  账号类型：personal|enterprise|空=全部
     */
    public function listAdmin(
        int $page = 1,
        int $limit = 15,
        string $keyword = '',
        int $levelId = 0,
        int $statusFilter = -1,
        string $source = '',
        string $dateFrom = '',
        string $dateTo = '',
        string $listFilter = '',
        string $mobile = '',
        string $accountKind = ''
    ) {
        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($memberRoleId < 1) {
            return User::where('id', 0)->paginate(['list_rows' => $limit, 'page' => $page]);
        }

        $query = User::alias('u')
            ->whereExists(static function ($sub) use ($memberRoleId): void {
                $sub->name('user_roles')->alias('ur')
                    ->whereRaw('ur.user_id = u.id')
                    ->where('ur.role_id', $memberRoleId);
            })
            ->with(['memberLevel' => static function ($levelQuery): void {
                $levelQuery->field('id,name');
            }])
            ->field('u.*')
            ->order('u.id', 'desc');

        if ($keyword !== '') {
            $like = $this->contentSearch->likePattern($keyword);
            $query->where(function ($q) use ($like, $keyword) {
                $q->whereLike('u.username', $like)
                    ->whereOr('u.nickname', 'like', $like)
                    ->whereOr('u.email', 'like', $like)
                    ->whereOr('u.mobile', 'like', $like);
                if (ctype_digit($keyword)) {
                    $q->whereOr('u.id', '=', (int) $keyword);
                }
            });
        }
        $mobile = trim($mobile);
        if ($mobile !== '') {
            $query->whereLike('u.mobile', $this->contentSearch->likePattern($mobile));
        }
        if ($levelId > 0) {
            $query->where('u.member_level_id', $levelId);
        }
        $listFilter = strtolower(trim($listFilter));
        if ($listFilter === 'inactive') {
            $query->where('u.status', 0);
        } elseif ($listFilter === 'level_expiring') {
            $query->where('u.status', 1)
                ->whereNotNull('u.member_level_expire_at')
                ->where('u.member_level_expire_at', '<>', '')
                ->where('u.member_level_expire_at', '>=', AppTime::now())
                ->where('u.member_level_expire_at', '<=', AppTime::format('Y-m-d H:i:s', time() + 7 * 86400));
        } elseif ($statusFilter === 0) {
            $query->where('u.status', 1);
        } elseif ($statusFilter === 1) {
            $query->where('u.status', 0);
        }
        if ($dateFrom !== '') {
            $query->where('u.created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $query->where('u.created_at', '<=', $dateTo . ' 23:59:59');
        }
        $source = strtolower(trim($source));
        if ($source === 'manual') {
            $query->whereNotExists(static function ($sub): void {
                $sub->name('user_oauth_bindings')->alias('uob')->whereRaw('uob.user_id = u.id');
            });
        } elseif (in_array($source, ['mobile', 'wechat', 'qq', 'weibo'], true)) {
            if ($source === 'mobile') {
                $query->where('u.mobile', '<>', '')->whereNotNull('u.mobile');
            } else {
                $query->whereExists(static function ($sub) use ($source): void {
                    $sub->name('user_oauth_bindings')->alias('uob')
                        ->whereRaw('uob.user_id = u.id')
                        ->where('uob.provider', $source);
                });
            }
        }
        $accountKind = strtolower(trim($accountKind));
        if (in_array($accountKind, MemberAccountKind::all(), true)) {
            $query->where('u.account_kind', $accountKind);
        }

        return $query->paginate(['list_rows' => $limit, 'page' => $page]);
    }

    /** @param list<array<string, mixed>> $rows
     *  @return list<array<string, mixed>>
     */
    public function enrichListRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        $providerMap = [];
        if ($ids !== []) {
            $bindings = UserOauthBinding::whereIn('user_id', $ids)->select()->toArray();
            foreach ($bindings as $binding) {
                $uid = (int) ($binding['user_id'] ?? 0);
                $provider = (string) ($binding['provider'] ?? '');
                if ($uid > 0 && $provider !== '') {
                    $providerMap[$uid][] = $provider;
                }
            }
        }
        $avatarRaw = array_map(static fn (array $r): string => (string) ($r['avatar'] ?? ''), $rows);
        $avatarUrlMap = $this->userService->publicMediaUrlsIfExist($avatarRaw);
        $enterpriseMap = $this->memberEnterpriseProfileService->mapByUserIds($ids);
        foreach ($rows as &$row) {
            $uid = (int) ($row['id'] ?? 0);
            $providers = $providerMap[$uid] ?? [];
            if (!empty($row['mobile'])) {
                $providers[] = 'mobile';
            }
            $row['oauth_providers'] = array_values(array_unique($providers));
            if (!isset($row['member_level_name']) || $row['member_level_name'] === '') {
                $level = $row['member_level'] ?? $row['memberLevel'] ?? null;
                if (is_array($level)) {
                    $row['member_level_name'] = (string) ($level['name'] ?? '');
                }
            }
            $kind = MemberAccountKind::normalize($row['account_kind'] ?? MemberAccountKind::PERSONAL);
            $row['account_kind'] = $kind;
            $row['account_kind_label'] = MemberAccountKind::label($kind);
            $enterprise = $enterpriseMap[$uid] ?? null;
            $row['company_name'] = is_array($enterprise)
                ? (string) ($enterprise['company_name'] ?? '')
                : '';
            $rawAvatar = (string) ($row['avatar'] ?? '');
            $row['avatar'] = $avatarUrlMap[$rawAvatar] ?? '';
            $row['delete_locked'] = MemberService::isDeleteLockedMember(
                $uid,
                (string) ($row['username'] ?? ''),
            );
            unset($row['member_level'], $row['memberLevel']);
        }
        unset($row);

        return array_values($rows);
    }

    /**
     * @return ServiceResult
     */
    public function createAdminBatch(string $usernamesText, string $password, int $memberLevelId): ServiceResult
    {
        $password = trim($password);
        if (strlen($password) < 8) {
            return ServiceResult::fail('密码至少 8 位');
        }
        if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password)) {
            return ServiceResult::fail('密码须包含字母与数字');
        }
        if ($memberLevelId > 0 && !$this->memberLevelService->isValidLevelId($memberLevelId)) {
            return ServiceResult::fail('会员等级无效');
        }
        $levelId = $memberLevelId > 0 ? $memberLevelId : $this->memberLevelService->defaultLevelId();

        $lines = preg_split('/\r\n|\r|\n|,/u', $usernamesText) ?: [];
        $created = 0;
        $skipped = [];
        foreach ($lines as $line) {
            $username = trim($line);
            if ($username === '') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
                $skipped[] = ['username' => $username, 'reason' => '格式不符'];
                continue;
            }
            $res = $this->createAdmin([
                'username'         => $username,
                'password'         => $password,
                'nickname'         => $username,
                'member_level_id'  => $levelId,
                'status'           => 1,
            ]);
            if ($res->isOk()) {
                $created++;
            } else {
                $skipped[] = [
                    'username' => $username,
                    'reason'   => $this->batchSkipReason($res->message()),
                ];
            }
        }
        if ($created < 1 && $skipped === []) {
            return ServiceResult::fail('请填写至少一个有效用户名');
        }

        $skipCount = count($skipped);
        if ($created > 0) {
            $msg = "已成功创建 {$created} 个会员";
            if ($skipCount > 0) {
                $msg .= "，{$skipCount} 个未创建";
            }
        } else {
            $msg = $skipCount > 0 ? "未创建会员（{$skipCount} 个失败）" : '未创建会员';
        }

        return ServiceResult::ok(['created' => $created, 'skipped' => $skipped], $msg);
    }

    private function batchSkipReason(?string $message): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return '失败';
        }

        return match ($message) {
            '用户名已存在' => '已存在',
            '邮箱已被使用' => '邮箱占用',
            '会员角色未初始化' => '角色未就绪',
            '会员等级无效' => '等级无效',
            '前台会员请使用「会员管理」创建，勿在系统用户中添加' => '请走会员管理',
            default => mb_strlen($message) > 12 ? mb_substr($message, 0, 12) . '…' : $message,
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function createAdmin(array $data): ServiceResult
    {
        $memberRoleId = Role::activeIdByCode(MemberService::ROLE_CODE);
        if ($memberRoleId < 1) {
            return ServiceResult::fail('会员角色未初始化');
        }

        $data['role_ids'] = [$memberRoleId];

        $levelId = max(0, (int) ($data['member_level_id'] ?? 0));
        if ($levelId > 0 && !$this->memberLevelService->isValidLevelId($levelId)) {
            return ServiceResult::fail('会员等级无效');
        }

        $res = $this->userService->create($data, $levelId > 0 ? $levelId : $this->memberLevelService->defaultLevelId());
        if ($res->isOk()) {
            $username = trim((string) ($data['username'] ?? ''));
            if ($username !== '') {
                $uid = (int) User::where('username', $username)->value('id');
                if ($uid > 0) {
                    $kindSync = $this->syncAccountKindAndEnterprise($uid, $data, true);
                    if (!$kindSync->isOk()) {
                        return $kindSync;
                    }
                    $this->memberFieldService->validateAndSyncUser($uid, $data, 'admin');
                }
            }
        }

        return $res;
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function updateAdmin(int $id, array $data): ServiceResult
    {
        if (!$this->memberRoleCheckService->hasMemberRole($id)) {
            return ServiceResult::fail('非前台会员账号');
        }

        // 会员角色已绑定；勿经 UserService 改 role_ids（会误拦「须至少选择一个后台用户组」）
        unset($data['role_ids'], $data['roles']);

        $levelId = max(0, (int) ($data['member_level_id'] ?? 0));
        unset($data['member_level_id']);
        if ($levelId > 0 && !$this->memberLevelService->isValidLevelId($levelId)) {
            return ServiceResult::fail('会员等级无效');
        }

        $remark = array_key_exists('member_remark', $data)
            ? HtmlSanitizer::cleanPlainText((string) $data['member_remark'], 500)
            : null;
        $daysExtend = max(0, (int) ($data['member_days_extend'] ?? 0));
        unset($data['member_remark'], $data['member_days_extend'], $data['account_kind_label']);
        // 企业字段 / 类型不进 UserService::update allowField
        $enterpriseKeys = ['company_name', 'contact_name', 'contact_phone', 'usci', 'job_title', 'company_email', 'account_kind', 'kind'];
        $kindPayload = [];
        foreach ($enterpriseKeys as $ek) {
            if (array_key_exists($ek, $data)) {
                $kindPayload[$ek] = $data[$ek];
                unset($data[$ek]);
            }
        }

        $res = $this->userService->update($id, $data);
        if (!$res->isOk()) {
            return $res;
        }

        $extra = [];
        if ($levelId > 0) {
            $extra['member_level_id'] = $levelId;
        }
        if ($remark !== null) {
            $extra['member_remark'] = $remark;
        }
        if ($daysExtend > 0) {
            $extra['member_level_expire_at'] = $this->extendLevelExpireAt($id, $daysExtend);
        }
        if ($extra !== []) {
            User::where('id', $id)->update($extra);
        }

        $kindSync = $this->syncAccountKindAndEnterprise($id, $kindPayload, false);
        if (!$kindSync->isOk()) {
            return $kindSync;
        }

        $fieldSync = $this->memberFieldService->validateAndSyncUser($id, $data, 'admin');
        if (!$fieldSync->isOk()) {
            return $fieldSync;
        }

        return $res;
    }

    /**
     * 后台创建/更新：同步 account_kind 与企业资料。
     *
     * @param array<string, mixed> $data
     */
    private function syncAccountKindAndEnterprise(int $userId, array $data, bool $creating): ServiceResult
    {
        if ($userId < 1) {
            return ServiceResult::ok();
        }
        $hasKindKey = array_key_exists('account_kind', $data) || array_key_exists('kind', $data);
        $current = MemberAccountKind::normalize(
            User::where('id', $userId)->value('account_kind') ?? MemberAccountKind::PERSONAL
        );
        $kind = $hasKindKey
            ? MemberAccountKind::normalize($data['account_kind'] ?? $data['kind'] ?? $current)
            : $current;
        if ($creating && !$hasKindKey) {
            $kind = MemberAccountKind::PERSONAL;
        }

        if ($kind !== $current || ($creating && $hasKindKey)) {
            User::where('id', $userId)->update(['account_kind' => $kind]);
        }

        if ($kind === MemberAccountKind::ENTERPRISE) {
            $requireCore = $creating || $hasKindKey || $this->enterprisePayloadPresent($data);
            if ($requireCore || $this->enterprisePayloadPresent($data)) {
                $entCheck = $this->memberEnterpriseProfileService->validatePayload($data, true);
                if (!$entCheck->isOk()) {
                    return ServiceResult::fail($entCheck->message());
                }
                $this->memberEnterpriseProfileService->upsert($userId, $entCheck->dataArray());
            }
        } elseif ($hasKindKey && $kind === MemberAccountKind::PERSONAL && $current === MemberAccountKind::ENTERPRISE) {
            $this->memberEnterpriseProfileService->deleteByUserId($userId);
        }

        return ServiceResult::ok();
    }

    /** @param array<string, mixed> $data */
    private function enterprisePayloadPresent(array $data): bool
    {
        foreach (['company_name', 'contact_name', 'contact_phone', 'usci', 'job_title', 'company_email'] as $k) {
            if (trim((string) ($data[$k] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * 在现有到期日或当前时间基础上顺延会员天数。
     * 行锁内读→算→写，避免并发两次顺延都基于同一基线而丢天数。
     */
    public function extendLevelExpireAt(int $userId, int $days): string
    {
        $days = max(0, $days);
        if ($userId < 1 || $days < 1) {
            $current = $userId > 0
                ? User::where('id', $userId)->value('member_level_expire_at')
                : null;

            return ($current !== null && $current !== '')
                ? (string) $current
                : AppTime::format('Y-m-d H:i:s', time());
        }

        $expire = '';
        Db::transaction(function () use ($userId, $days, &$expire): void {
            $row = User::where('id', $userId)->lock(true)->find();
            $base = time();
            $current = null;
            if ($row instanceof User) {
                $current = $row->getAttr('member_level_expire_at');
            } elseif (is_array($row)) {
                $current = $row['member_level_expire_at'] ?? null;
            }
            if ($current !== null && $current !== '') {
                $ts = strtotime((string) $current);
                if ($ts !== false && $ts > $base) {
                    $base = $ts;
                }
            }
            $expire = AppTime::format('Y-m-d H:i:s', $base + $days * 86400);
            User::where('id', $userId)->update([
                'member_level_expire_at' => $expire,
            ]);
        });

        return $expire !== ''
            ? $expire
            : AppTime::format('Y-m-d H:i:s', time() + $days * 86400);
    }

    public function levelDaysLeft(?string $expireAt): ?int
    {
        if ($expireAt === null || $expireAt === '') {
            return null;
        }
        $ts = strtotime($expireAt);
        if ($ts === false) {
            return null;
        }
        $left = (int) ceil(($ts - time()) / 86400);

        return max(0, $left);
    }

    public function findUserForAdminForm(int $userId): ?User
    {
        if ($userId < 1 || !$this->memberRoleCheckService->hasMemberRole($userId)) {
            return null;
        }

        $user = User::find($userId);

        return $user instanceof User ? $user : null;
    }

    /** @return array<string, mixed>|null */
    public function detailForAdmin(int $userId): ?array
    {
        if ($userId < 1 || !$this->memberRoleCheckService->hasMemberRole($userId)) {
            return null;
        }
        $user = User::find($userId);
        if (!$user instanceof User) {
            return null;
        }
        $row = $user->toArray();
        $levelId = (int) ($row['member_level_id'] ?? 0);
        $row['member_level_id'] = $levelId;
        $row['member_level_name'] = $this->memberLevelService->getName($levelId);
        $row['member_points'] = (int) ($row['member_points'] ?? 0);
        $row['member_balance'] = round((float) ($row['member_balance'] ?? 0), 2);
        $row['member_remark'] = (string) ($row['member_remark'] ?? '');
        $expireAt = $row['member_level_expire_at'] ?? null;
        $row['member_level_days_left'] = $this->levelDaysLeft(is_string($expireAt) ? $expireAt : null);
        $row['member_center_url'] = $this->memberViewAsService->issueEnterCenterUrl($userId)
            ?? SiteUrl::memberCenter();

        $bindings = [];
        $oauthRows = UserOauthBinding::where('user_id', $userId)->select()->toArray();
        foreach ($oauthRows as $oauth) {
            $bindings[] = [
                'provider'     => (string) ($oauth['provider'] ?? ''),
                'provider_uid' => (string) ($oauth['provider_uid'] ?? ''),
                'nickname'     => (string) ($oauth['nickname'] ?? ''),
            ];
        }
        if (!empty($row['mobile'])) {
            $bindings[] = ['provider' => 'mobile', 'provider_uid' => (string) $row['mobile'], 'nickname' => ''];
        }
        $row['oauth_bindings'] = $bindings;

        $fieldValues = [];
        $valueMap = $this->memberFieldService->valuesMapForUser($userId);
        foreach ($this->memberFieldService->listActiveAll() as $field) {
            $fid = (int) ($field['id'] ?? 0);
            $key = (string) ($field['field_key'] ?? '');
            if ($key !== '') {
                $fieldValues[$key] = (string) ($valueMap[$fid] ?? '');
            }
        }
        $row['custom_field_values'] = $fieldValues;
        $kind = MemberAccountKind::normalize($row['account_kind'] ?? MemberAccountKind::PERSONAL);
        $row['account_kind'] = $kind;
        $row['account_kind_label'] = MemberAccountKind::label($kind);
        $row['enterprise'] = $this->memberEnterpriseProfileService->findByUserId($userId);
        $row['company_name'] = is_array($row['enterprise'])
            ? (string) ($row['enterprise']['company_name'] ?? '')
            : '';
        $row['delete_locked'] = MemberService::isDeleteLockedMember(
            $userId,
            (string) ($row['username'] ?? ''),
        );

        return $row;
    }

    /**
     * @return ServiceResult
     */
    public function deleteAdmin(int $id): ServiceResult
    {
        if (!$this->memberRoleCheckService->hasMemberRole($id)) {
            return ServiceResult::fail('非前台会员账号');
        }
        $user = User::find($id);
        if (!$user instanceof User) {
            return ServiceResult::fail('会员不存在');
        }
        if (MemberService::isDeleteLockedMember($id, (string) $user->username)) {
            return ServiceResult::fail('官方自营账号不可删除');
        }

        Db::startTrans();
        try {
            UserOauthBinding::where('user_id', $id)->delete();
            MemberFieldValue::where('user_id', $id)->delete();
            MemberPointLog::where('user_id', $id)->delete();
            MemberBalanceLog::where('user_id', $id)->delete();
            MemberCancelRequest::where('user_id', $id)->delete();
            $this->memberEnterpriseProfileService->deleteByUserId($id);
            UserRole::syncForUser($id, []);
            $user->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();

            return ServiceResult::fail('删除失败');
        }

        $this->auditLogService->operate('删除会员', 'admin.member', ['user_id' => $id, 'username' => (string) $user->username]);

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @param list<int|string> $ids
     * @return ServiceResult
     */
    public function deleteAdminBatch(array $ids): ServiceResult
    {
        $ok = 0;
        $fail = 0;
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id < 1) {
                continue;
            }
            $res = $this->deleteAdmin($id);
            if ($res->isOk()) {
                $ok++;
            } else {
                $fail++;
            }
        }
        if ($ok < 1 && $fail < 1) {
            return ServiceResult::fail('请选择要删除的会员');
        }
        if ($fail > 0) {
            return ServiceResult::ok(null, "已删除 {$ok} 个，失败 {$fail} 个");
        }

        return ServiceResult::ok(null, "已删除 {$ok} 个会员");
    }
}
