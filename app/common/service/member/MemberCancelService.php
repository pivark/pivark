<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\model\MemberCancelRequest;

use app\common\model\User;
use app\common\model\UserRole;
use app\common\support\HtmlSanitizer;
use app\common\support\ModelRelationLoad;
use think\facade\Db;

/** 会员注销申请 */
class MemberCancelService
{

    public function __construct(
        private readonly MemberService $members,
        private readonly MemberConfigService $memberConfig,
    ) {
    }

    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

    /**
     * @return array{list:list<array>,total:int}
     */
    public function listAdmin(int $page = 1, int $limit = 20, int $status = -1, string $keyword = ''): array
    {
        $query = MemberCancelRequest::with(['user' => static function ($userQuery): void {
            $userQuery->field('id,username,nickname,email,mobile');
        }])->order('id', 'desc');
        if ($status >= 0) {
            $query->where('status', $status);
        }
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->where(function ($sub) use ($like): void {
                $sub->whereLike('reason', $like);
                $sub->whereOr(function ($userScope) use ($like): void {
                    $userScope->whereHas('user', static function ($userQuery) use ($like): void {
                        $userQuery->whereLike('username|nickname|email|mobile', $like);
                    });
                });
            });
        }
        $paginator = $query->paginate(['list_rows' => $limit, 'page' => $page]);
        $list      = ModelRelationLoad::mapBelongsTo(
            $paginator->items(),
            'user',
            ['username', 'nickname', 'email', 'mobile'],
        );

        return ['list' => $list, 'total' => (int) $paginator->total()];
    }

    /** @return ServiceResult */
    public function requestPublic(int $userId, string $reason): ServiceResult
    {
        if ($userId < 1 || !$this->members->hasMemberRole($userId)) {
            return ServiceResult::fail('请先登录');
        }
        if (!$this->memberConfig->isCancelOpen()) {
            return ServiceResult::fail('暂未开放自助注销');
        }
        $pending = MemberCancelRequest::where('user_id', $userId)->where('status', self::STATUS_PENDING)->count();
        if ($pending > 0) {
            return ServiceResult::fail('已有待审核的注销申请');
        }
        $reason = HtmlSanitizer::cleanPlainText(trim($reason), 500);
        if ($reason === '') {
            return ServiceResult::fail('请填写注销原因');
        }

        MemberCancelRequest::insert([
            'user_id'    => $userId,
            'reason'     => $reason,
            'status'     => self::STATUS_PENDING,
            'created_at' => AppTime::now(),
        ]);

        return ServiceResult::ok(null, '注销申请已提交，请等待审核');
    }

    /**
     * @return ServiceResult
     */
    public function handleAdmin(int $id, int $action, string $remark, int $adminId): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数错误');
        }
        $row = MemberCancelRequest::where('id', $id)->find();
        if (!$row || (int) ($row['status'] ?? -1) !== self::STATUS_PENDING) {
            return ServiceResult::fail('申请不存在或已处理');
        }
        $userId = (int) ($row['user_id'] ?? 0);
        $remark = HtmlSanitizer::cleanPlainText($remark, 200);
        $now = AppTime::now();

        if ($action === self::STATUS_APPROVED) {
            Db::startTrans();
            try {
                MemberCancelRequest::where('id', $id)->update([
                    'status'       => self::STATUS_APPROVED,
                    'admin_remark' => $remark,
                    'handled_by'   => $adminId,
                    'handled_at'   => $now,
                ]);
                User::where('id', $userId)->update(['status' => 0]);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();

                return ServiceResult::fail('处理失败');
            }

            return ServiceResult::ok(null, '已通过，账号已禁用');
        }

        if ($action === self::STATUS_REJECTED) {
            MemberCancelRequest::where('id', $id)->update([
                'status'       => self::STATUS_REJECTED,
                'admin_remark' => $remark !== '' ? $remark : '已驳回',
                'handled_by'   => $adminId,
                'handled_at'   => $now,
            ]);

            return ServiceResult::ok(null, '已驳回');
        }

        return ServiceResult::fail('无效操作');
    }

    public function statusLabel(int $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED => '已通过',
            self::STATUS_REJECTED => '已驳回',
            default => '待审核',
        };
    }
}
