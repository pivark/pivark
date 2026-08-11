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
 * Class app\common\model\FloatContactItem
 *
 * @property int $id 主键
 * @property int $sort 排序，越小越靠前
 * @property int $status 1启用 0禁用
 * @property string $contact_type qq|phone|wechat|email|link
 * @property string $created_at 创建时间
 * @property string $label 显示名称
 * @property string $qrcode 微信二维码图片路径
 * @property string $tip 副标题/工作时间
 * @property string $updated_at 更新时间
 * @property string $value 号码/账号/链接
 */
class FloatContactItem extends Model
{
    protected $name = 'float_contact_items';

    protected $type = [
        'id'     => 'integer',
        'sort'   => 'integer',
        'status' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
