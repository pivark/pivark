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
 * 插件注册 pv_plugins
 *
 * @property float $price 标价（0=免费）
 * @property int $enabled 是否已启用（且授权有效）
 * @property int $id 主键
 * @property int $installed 是否已安装
 * @property int $period_days 订阅天数，NULL=永久
 * @property int $status 状态：0停用 1启用
 * @property string $author 作者
 * @property string $commercial_model 商业模式：free/paid/subscription
 * @property string $created_at 创建时间
 * @property string $description 插件描述
 * @property string $edition 版本归属：community/enterprise
 * @property string $identifier 插件标识（唯一）
 * @property string $instance_id 安装实例 ID
 * @property string $kind 插件形态
 * @property string $name 插件名称
 * @property string $package 包 ID vendor/slug
 * @property string $updated_at 更新时间
 * @property string $version 当前版本
 */
class Plugin extends Model
{
    protected $name = 'plugins';

        protected $type = [
        'enabled' => 'integer',
        'id' => 'integer',
        'installed' => 'integer',
        'period_days' => 'integer',
        'price' => 'float',
        'status' => 'integer',
    ];

    public $timestamps = false;
}
