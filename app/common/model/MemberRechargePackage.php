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
 * Class app\common\model\MemberRechargePackage
 *
 * @property float $grant_balance 余额类到账金额，0=按售价
 * @property float $price 售价（元）
 * @property int $days 有效天数，0=永久
 * @property int $id 主键
 * @property int $level_id 赠送/升级到的等级ID
 * @property int $points 赠送积分
 * @property int $sort 排序
 * @property int $status 状态：0下架 1上架
 * @property string $created_at 创建时间
 * @property string $description 说明
 * @property string $package_type membership|points|balance
 * @property string $title 套餐名称
 * @property string $updated_at 更新时间
 * @property-read \app\common\model\MemberLevel $level
 */
class MemberRechargePackage extends Model
{
    protected $name = 'member_recharge_packages';

        protected $type = [
        'days' => 'integer',
        'grant_balance' => 'float',
        'id' => 'integer',
        'level_id' => 'integer',
        'points' => 'integer',
        'price' => 'float',
        'sort' => 'integer',
        'status' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function level(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class, 'level_id');
    }

}
