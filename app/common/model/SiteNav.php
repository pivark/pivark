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
 * 前台导航 pv_site_nav
 *
 * @property int $id 主键
 * @property int $open_new_tab 1新窗口打开
 * @property string|null $extra_json 导航扩展字段键值JSON
 * @property int $parent_id 上级ID，0为顶级
 * @property int $sort 排序（越小越靠前）
 * @property int $status 0禁用 1启用
 * @property string $created_at 创建时间
 * @property string $nav_type route内部路径 tag标签 external外链 none仅分组
 * @property string $content_kind 入口类型 home|document|product|page|external
 * @property string $target 路径/slug/URL
 * @property string $url_path 前台列表路径
 * @property string $tpl_name 列表页模板
 * @property string $view_tpl_name 内容页默认模板
 * @property string $seo_keywords SEO关键词
 * @property string $seo_description SEO描述
 * @property string $litpic 栏目封面图
 * @property int $read_perm 阅读：0开放 1会员
 * @property int $read_level_id 会员等级ID
 * @property string $title 导航标题
 * @property string $updated_at 更新时间
 * @property-read \app\common\model\MemberLevel $read_level
 * @property-read \app\common\model\SiteNav $parent
 */
class SiteNav extends Model
{
    protected $name = 'site_nav';

    protected $type = [
        'id' => 'integer',
        'open_new_tab' => 'integer',
        'parent_id' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
        'read_perm' => 'integer',
        'read_level_id' => 'integer',
    ];

    public $timestamps = false;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function readLevel(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class, 'read_level_id');
    }
}
