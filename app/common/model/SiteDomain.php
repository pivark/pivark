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
 * 同站多域 pv_site_domains
 *
 * @property int $id 主键
 * @property int $is_primary 1=主域
 * @property int $sort 排序
 * @property int $status 0禁用 1启用
 * @property int $tag_group_id 绑定的标签分组
 * @property string $created_at 创建时间
 * @property string $default_tag_slug 该域首页可选直达标签 slug
 * @property string $host 访问域名（不含协议与路径）
 * @property string $updated_at 更新时间
 * @property-read \app\common\model\TagGroup $tag_group
 */
class SiteDomain extends Model
{
    protected $name = 'site_domains';

        protected $type = [
        'id' => 'integer',
        'is_primary' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
        'tag_group_id' => 'integer',
    ];

    public $timestamps = false;
    public function tagGroup(): BelongsTo
    {
        return $this->belongsTo(TagGroup::class, 'tag_group_id');
    }

}
