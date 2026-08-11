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
 * Class app\common\model\MemberFieldValue
 *
 * @property int $field_id 字段ID
 * @property int $id 主键
 * @property int $user_id 会员用户ID
 * @property string $updated_at 更新时间
 * @property string $value 字段值
 * @property-read \app\common\model\MemberField $field
 * @property-read \app\common\model\User $user
 */
class MemberFieldValue extends Model
{
    protected $name = 'member_field_values';

        protected $type = [
        'field_id' => 'integer',
        'id' => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function field(): BelongsTo
    {
        return $this->belongsTo(MemberField::class, 'field_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
