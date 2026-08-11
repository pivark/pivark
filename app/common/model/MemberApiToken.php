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
 * Class app\common\model\MemberApiToken
 *
 * @property int $id 主键
 * @property int $user_id 会员用户 ID
 * @property string $client 客户端标识
 * @property string $created_at 创建时间
 * @property string $expires_at 过期时间
 * @property string $last_used_at 最近使用
 * @property string $token_hash SHA256(token)
 * @property-read \app\common\model\User $user
 */
class MemberApiToken extends Model
{
    protected $name = 'member_api_tokens';

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
