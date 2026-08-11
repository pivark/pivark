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
use think\model\relation\HasMany;

/**
 * 标签 pv_tags
 *
 * @property int $group_id 所属分组ID
 * @property int $id 主键
 * @property int $nav_sort 导航排序（越小越靠前）
 * @property int $parent_id 父级标签ID，0为顶级栏目
 * @property int $read_level_id 受限时会员等级ID，0仅登录
 * @property int $read_perm 专题阅读：0开放 1受限
 * @property int $status 状态：0禁用 1启用
 * @property int $use_count 使用次数
 * @property string $created_at 创建时间
 * @property string $description 导语/简介
 * @property string|null $extra_json 频道扩展字段键值JSON
 * @property string $kind topic主题标签 label标注标签
 * @property string $litpic 封面图
 * @property string $name 标签名称
 * @property string $seo_description SEO描述
 * @property string $seo_keywords SEO关键词
 * @property string $seo_title SEO标题
 * @property string $slug URL/API标识（唯一）
 * @property string $tpl_name 列表页模板文件名
 * @property string $view_tpl_name 内容页默认模板（文档/品项详情）
 * @property string $updated_at 更新时间
 * @property string $url_path 前台访问路径
 * @property-read \app\common\model\DocumentTag[] $document_tags
 * @property-read \app\common\model\MemberLevel $read_level
 * @property-read \app\common\model\Tag $parent
 * @property-read \app\common\model\TagGroup $tag_group
 */
class Tag extends Model
{
    protected $name = 'tags';

        protected $type = [
        'group_id' => 'integer',
        'id' => 'integer',
        'nav_sort' => 'integer',
        'parent_id' => 'integer',
        'read_level_id' => 'integer',
        'read_perm' => 'integer',
        'status' => 'integer',
        'use_count' => 'integer',
    ];

    public $timestamps = false;

    public function documentTags(): HasMany
    {
        return $this->hasMany(DocumentTag::class, 'tag_id');
    }
    public function tagGroup(): BelongsTo
    {
        return $this->belongsTo(TagGroup::class, 'group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function readLevel(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class, 'read_level_id');
    }

}
