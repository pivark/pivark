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
 * Class app\common\model\UserOauthBinding
 *
 * @property int $id 主键
 * @property int $user_id 本站用户 ID
 * @property mixed $extra_json 原始 profile
 * @property string $avatar 头像 URL
 * @property string $created_at 绑定时间
 * @property string $nickname 第三方昵称
 * @property string $provider 平台标识
 * @property string $provider_uid 第三方用户 ID
 * @property string $union_id 同生态合并 ID
 * @property string $updated_at 更新时间
 * @property-read \app\common\model\User $user
 */
class UserOauthBinding extends Model
{
    protected $name = 'user_oauth_bindings';

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
