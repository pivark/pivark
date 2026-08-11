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
 * Class app\common\model\PasswordResetToken
 *
 * @property int $id 主键
 * @property int $user_id 用户 ID
 * @property string $created_at 创建时间
 * @property string $email 字段：email
 * @property string $expires_at 时间
 * @property string $request_ip 字段：request_ip
 * @property string $token_hash 字段：token_hash
 * @property string $used_at 时间
 * @property-read \app\common\model\User $user
 */
class PasswordResetToken extends Model
{
    protected $name = 'password_reset_tokens';

        protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
