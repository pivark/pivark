<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\common\service\user;

use app\common\support\ServiceResult;
use app\common\service\user\PermissionService;
use app\common\service\admin\AdminTagScopeService;
use app\common\service\audit\AuditLogService;
use app\common\service\theme\ThemeService;
use app\common\service\member\MemberService;
use app\common\service\content\ContentSearchService;
use app\common\model\Role;
use app\common\model\User;
use app\common\model\UserRole;
use think\facade\Db;
use think\facade\Session;

/** 后台用户管理 */
class UserService
{

    public function __construct(
        private readonly AdminTagScopeService $adminTagScopeService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @param int    $page    页码
     * @param int    $limit   每页
     * @param string $keyword 搜索
     * @return \think\Paginator
     */
    public function getList(int $page = 1, int $limit = 15, string $keyword = '')
    {
        $backofficeRoleIds = Role::where('status', 1)
            ->where('code', '<>', MemberService::ROLE_CODE)
            ->column('id');
        if ($backofficeRoleIds === []) {
            return User::where('id', 0)->paginate($limit, false, ['page' => $page]);
        }

        $query = User::alias('u')
            ->where(function ($q) use ($backofficeRoleIds) {
                $q->whereIn('u.id', static function ($sub) use ($backofficeRoleIds) {
                    $sub->name('user_roles')->whereIn('role_id', $backofficeRoleIds)->field('user_id');
                })->whereOr(function ($q2) {
                    $q2->whereNotIn('u.id', static function ($sub) {
                        $sub->name('user_roles')->field('user_id');
                    });
                });
            });
        $superRoleId = Role::activeIdByCode('super_admin');
        if ($superRoleId > 0) {
            $rolesTable = Db::name('user_roles')->getTable();
            $query->orderRaw(
                "EXISTS (SELECT 1 FROM `{$rolesTable}` ur WHERE ur.user_id = u.id AND ur.role_id = ?) DESC, u.id DESC",
                [$superRoleId],
            );
        } else {
            $query->order('u.id', 'desc');
        }
        if ($keyword !== '') {
            $query->whereLike('u.username|u.email|u.mobile|u.nickname', app(ContentSearchService::class)->likePattern($keyword));
        }

        return $query->paginate($limit, false, ['page' => $page]);
    }

    /**
     * @param array<string, mixed> $data
     * @param int                  $memberLevelId 前台会员等级，0=不写入
     * @return ServiceResult
     */
    public function create(array $data, int $memberLevelId = 0): ServiceResult
    {
        $exists = User::where('username', $data['username'])->find();
        if ($exists) {
            return ServiceResult::fail('用户名已存在');
        }
        $email = $this->nullableContact($data['email'] ?? null);
        if ($email !== null) {
            $exists = User::where('email', $email)->find();
            if ($exists) {
                return ServiceResult::fail('邮箱已被使用');
            }
        }

        $roleIds = $this->parseRoleIds($data['role_ids'] ?? $data['roles'] ?? []);
        if ($deny = $this->denyRoleAssignment($roleIds)) {
            return $deny;
        }
        if ($memberLevelId < 1) {
            if ($roleIds === []) {
                return ServiceResult::fail('请至少选择一个用户组');
            }
            if (!app(PermissionService::class)->roleIdsIncludeBackoffice($roleIds)) {
                return ServiceResult::fail('前台会员请使用「会员管理」创建，勿在系统用户中添加');
            }
        }

        Db::startTrans();
        try {
            $create = [
                'username' => $data['username'],
                'password' => password_hash((string) $data['password'], PASSWORD_BCRYPT),
                'nickname' => $data['nickname'] ?? $data['username'],
                'email'    => $email,
                'mobile'   => $this->nullableContact($data['mobile'] ?? null),
                'status'   => (int) ($data['status'] ?? 1),
            ];
            if (array_key_exists('avatar', $data)) {
                $create['avatar'] = $this->normalizeAvatar($data['avatar']);
            }
            $user = User::create($create);
            if ($roleIds !== []) {
                UserRole::syncForUser((int) $user->id, $roleIds);
            }
            if ($memberLevelId > 0) {
                User::where('id', (int) $user->id)->update(['member_level_id' => $memberLevelId]);
            }
            $this->adminTagScopeService->saveForUser(
                (int) $user->id,
                $this->parseTagScopeIds($data['tag_scope_ids'] ?? [])
            );
            $this->adminTagScopeService->saveNavForUser(
                (int) $user->id,
                $this->parseTagScopeIds($data['nav_scope_ids'] ?? [])
            );
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ServiceResult::fail('创建失败');
        }

        $this->auditLogService->operate('创建用户', 'admin.user', ['user_id' => (int) $user->id, 'username' => $user->username]);

        return ServiceResult::ok(['id' => (int) $user->id], '创建成功');
    }

    /**
     * @param int                $id
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function update(int $id, array $data): ServiceResult
    {
        $user = User::find($id);
        if (!$user) {
            return ServiceResult::fail('用户不存在');
        }

        if (isset($data['password']) && $data['password'] !== '') {
            // 后台用户管理 = 管理员重置密码，不验原密（自助改密走 /spa/change-password）
            $data['password'] = password_hash((string) $data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }

        $roleIds = null;
        if (array_key_exists('role_ids', $data)) {
            $roleIds = $this->parseRoleIds($data['role_ids']);
            if ($roleIds === []) {
                return ServiceResult::fail('请至少选择一个用户组');
            }
            if (!app(PermissionService::class)->roleIdsIncludeBackoffice($roleIds)) {
                return ServiceResult::fail('须至少选择一个后台用户组');
            }
            if ($deny = $this->denyRoleAssignment($roleIds)) {
                return $deny;
            }
            if (app(PermissionService::class)->isSuperAdmin($id) && $this->countSuperAdmins() <= 1) {
                $superId = Role::activeIdByCode('super_admin');
                if ($superId > 0 && !in_array($superId, $roleIds, true)) {
                    return ServiceResult::fail('不能移除唯一超级管理员的用户组');
                }
            }
        }
        unset($data['role_ids'], $data['roles'], $data['id'], $data['old_password']);
        if (array_key_exists('email', $data)) {
            $data['email'] = $this->nullableContact($data['email']);
        }
        if (array_key_exists('mobile', $data)) {
            $data['mobile'] = $this->nullableContact($data['mobile']);
        }
        if (array_key_exists('avatar', $data)) {
            $data['avatar'] = $this->normalizeAvatar($data['avatar']);
        }

        Db::startTrans();
        try {
            $user->allowField(['username', 'password', 'nickname', 'email', 'mobile', 'status', 'avatar'])->save($data);
            if ($roleIds !== null) {
                UserRole::syncForUser($id, $roleIds);
            }
            if (array_key_exists('tag_scope_ids', $data)) {
                $this->adminTagScopeService->saveForUser($id, $this->parseTagScopeIds($data['tag_scope_ids'] ?? []));
            }
            if (array_key_exists('nav_scope_ids', $data)) {
                $this->adminTagScopeService->saveNavForUser($id, $this->parseTagScopeIds($data['nav_scope_ids'] ?? []));
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ServiceResult::fail('更新失败');
        }

        $this->auditLogService->operate('更新用户', 'admin.user', ['user_id' => $id]);

        return ServiceResult::ok(null, '更新成功');
    }

    /**
     * @param int $id
     * @return ServiceResult
     */
    public function delete(int $id): ServiceResult
    {
        $user = User::find($id);
        if (!$user) {
            return ServiceResult::fail('用户不存在');
        }
        if ($user->username === 'admin') {
            return ServiceResult::fail('系统用户不可删除');
        }
        if (app(PermissionService::class)->isMemberOnlyAccount($id)) {
            return ServiceResult::fail('该账号为前台会员，请在会员管理中操作');
        }
        if (app(PermissionService::class)->isSuperAdmin($id) && $this->countSuperAdmins() <= 1) {
            return ServiceResult::fail('不能删除唯一超级管理员');
        }
        $username = (string) $user->username;
        UserRole::syncForUser($id, []);
        $user->delete();
        $this->auditLogService->operate('删除用户', 'admin.user', ['user_id' => $id, 'username' => $username]);
        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @param int $userId
     * @return list<int>
     */
    public function getRoleIdsForUser(int $userId): array
    {
        return UserRole::getRoleIdsByUserId($userId);
    }

    private function countSuperAdmins(): int
    {
        $roleId = Role::activeIdByCode('super_admin');
        if ($roleId < 1) {
            return 0;
        }
        return UserRole::countByRoleId($roleId);
    }

    /**
     * @param list<int> $roleIds
     */
    private function denyRoleAssignment(array $roleIds): ?ServiceResult
    {
        $admin = Session::get('admin_user');
        $operatorId = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
        if ($operatorId < 1) {
            // CLI / 迁移 / 无后台会话：禁止指派超管；普通会员角色允许创建
            $superId = Role::activeIdByCode('super_admin');
            if ($superId > 0 && in_array($superId, $roleIds, true)) {
                return ServiceResult::fail('未登录');
            }

            return null;
        }

        return app(PermissionService::class)->denyIfAssigningSuperAdmin($operatorId, $roleIds);
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function parseRoleIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    /**
     * @param mixed $raw
     * @return list<int>
     */
    private function parseTagScopeIds($raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $raw))));
    }

    public function normalizeAvatarPublic(mixed $value): ?string
    {
        return $this->normalizeAvatar($value);
    }

    /** 本地 /uploads、/static 资源不存在时返回 null，避免前台 404 */
    public function publicMediaUrlIfExists(mixed $value): ?string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url) === 1 || str_starts_with($url, '//')) {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && $path !== '' && (str_starts_with($path, '/static/') || str_starts_with($path, '/uploads/'))) {
                return $this->publicMediaUrlIfExists($path) !== null ? $url : null;
            }

            return $url;
        }
        if (!str_starts_with($url, '/')) {
            $url = '/' . ltrim($url, '/');
        }
        if (preg_match('#^/static/theme/([^/]+)/(.+)$#', $url, $matches) === 1) {
            return app(ThemeService::class)->resolveAssetAbsolutePath($matches[1], $matches[2]) !== null ? $url : null;
        }
        if (str_starts_with($url, '/uploads/') || str_starts_with($url, '/static/')) {
            $file = ROOT_PATH . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $url);

            return is_file($file) ? $url : null;
        }

        return $url;
    }

    /**
     * @param list<mixed> $values
     * @return array<string, ?string>
     */
    public function publicMediaUrlsIfExist(array $values): array
    {
        $map = [];
        foreach ($values as $value) {
            $key = trim((string) ($value ?? ''));
            if ($key === '' || array_key_exists($key, $map)) {
                continue;
            }
            $map[$key] = $this->publicMediaUrlIfExists($value);
        }

        return $map;
    }

    private function normalizeAvatar(mixed $value): ?string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return null;
        }
        if (mb_strlen($url) > 255) {
            $url = mb_substr($url, 0, 255);
        }

        return $url;
    }

    private function nullableContact(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * @return list<int>
     */
    public function getTagScopeIdsForUser(int $userId): array
    {
        return $this->adminTagScopeService->scopedTagIdsForUser($userId);
    }

    /**
     * @return list<int>
     */
    public function getNavScopeIdsForUser(int $userId): array
    {
        return $this->adminTagScopeService->scopedNavIdsForUser($userId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function enrichListRowsWithRoleLabels(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $userIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows,
        ))));
        if ($userIds === []) {
            return $rows;
        }

        $memberCode = MemberService::ROLE_CODE;
        $bindings   = Db::name('user_roles')
            ->alias('ur')
            ->join('roles r', 'r.id = ur.role_id')
            ->whereIn('ur.user_id', $userIds)
            ->where('r.status', 1)
            ->where('r.code', '<>', $memberCode)
            ->order('r.id', 'asc')
            ->field('ur.user_id, r.name')
            ->select()
            ->toArray();

        $map = [];
        foreach ($bindings as $binding) {
            if (!is_array($binding)) {
                continue;
            }
            $uid = (int) ($binding['user_id'] ?? 0);
            $name = trim((string) ($binding['name'] ?? ''));
            if ($uid < 1 || $name === '') {
                continue;
            }
            $map[$uid][] = $name;
        }

        foreach ($rows as &$row) {
            $uid = (int) ($row['id'] ?? 0);
            $names = $map[$uid] ?? [];
            $row['role_names'] = $names;
            $row['role_labels'] = $names !== [] ? implode('、', $names) : '—';
        }
        unset($row);

        return $rows;
    }

    public function findForForm(int $id): ?User
    {
        if ($id < 1) {
            return null;
        }
        $user = User::find($id);

        return $user instanceof User ? $user : null;
    }

    public function recordLogin(int $userId, string $ip): void
    {
        if ($userId < 1) {
            return;
        }
        User::updateLoginInfo($userId, $ip);
    }
}
