<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\model;

use think\Model;
use think\model\relation\BelongsTo;

/**
 * Class app\common\model\AdminUserNavScope
 *
 * @property int $id 主键
 * @property int $nav_id 可管理栏目ID（含子孙）
 * @property int $user_id 后台用户ID
 * @property string $created_at 创建时间
 * @property-read \app\common\model\SiteNav $nav
 * @property-read \app\common\model\User $user
 */
class AdminUserNavScope extends Model
{
    protected $name = 'admin_user_nav_scopes';

    protected $type = [
        'id'      => 'integer',
        'nav_id'  => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public function nav(): BelongsTo
    {
        return $this->belongsTo(SiteNav::class, 'nav_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
