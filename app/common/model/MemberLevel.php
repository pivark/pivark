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

/**
 * 会员等级
 *
 * @property int $id 主键
 * @property int $is_default 是否注册默认等级：0否 1是
 * @property int $rank 权限权重，越大越高
 * @property int $status 状态：0禁用 1启用
 * @property string $created_at 创建时间
 * @property string $name 等级名称
 * @property string $updated_at 更新时间
 */
class MemberLevel extends Model
{
    protected $name = 'member_levels';

        protected $type = [
        'id' => 'integer',
        'is_default' => 'integer',
        'rank' => 'integer',
        'status' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
