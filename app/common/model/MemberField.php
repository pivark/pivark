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
 * Class app\common\model\MemberField
 *
 * @property int $id 主键
 * @property int $is_required 是否必填：0否 1是
 * @property int $show_profile 个人中心可编辑
 * @property int $show_register 注册页显示
 * @property int $sort 排序
 * @property int $status 状态：0禁用 1启用
 * @property string $created_at 创建时间
 * @property string $default_value 默认值
 * @property string $field_key 字段标识（英文）
 * @property string $field_type 类型：text/textarea/select/number
 * @property string $label 字段名称
 * @property string $options select 选项，逗号分隔
 * @property string $updated_at 更新时间
 */
class MemberField extends Model
{
    protected $name = 'member_fields';

        protected $type = [
        'id' => 'integer',
        'is_required' => 'integer',
        'show_profile' => 'integer',
        'show_register' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
