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
 * 站点广告位 pv_site_ad_slots
 *
 * @property int $id 主键
 * @property int $sort 排序
 * @property int $status 0禁用 1启用
 * @property string $code 调用标识 slot=
 * @property string $created_at 创建时间
 * @property string $default_creative_type 新建素材默认创意类型
 * @property string $effective_end_at 生效结束，空=不限
 * @property string $effective_start_at 生效开始，空=不限
 * @property string $name 广告位名称
 * @property string $remark 说明
 * @property string $updated_at 更新时间
 */
class SiteAdSlot extends Model
{
    protected $name = 'site_ad_slots';

        protected $type = [
        'id' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];

    public $timestamps = false;
}
