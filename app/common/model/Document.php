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
 * 文档 pv_documents
 *
 * @property \app\common\model\DocumentAttrFlag[] $attr_flags 文档属性 CSV：headline,recommend,push,bold,has_image,external
 * @property int $author_id 后台作者用户 ID
 * @property int $click 点击数
 * @property int $external_open_new_tab 外链打开方式：0当前窗口 1新窗口
 * @property int $id 主键
 * @property int $nav_id 真分类 site_nav.id，0=未归类
 * @property int $read_level_id 最低会员等级ID，0=仅登录
 * @property int $read_perm 阅读权限：0开放 1受限（登录或等级）
 * @property int $status 状态：0草稿 1发布
 * @property string $author_name 前台展示署名
 * @property string $content PC 正文（富文本 HTML 或 Markdown 源码）
 * @property string $content_mobile 手机端正文；空则前台用 PC 正文
 * @property string $created_at 创建时间
 * @property string $deleted_at 软删除时间，NULL=未删
 * @property string $external_url 外链地址；勾选 external 且前台打开时跳转
 * @property string $html_name 自定义 URL 段；空则 /documents/{id}
 * @property string $litpic 缩略图
 * @property string $published_at 发布时间
 * @property string $schedule_offline_at 定时下架时间
 * @property string $schedule_publish_at 定时发布时间
 * @property string $search_text 全文检索纯文本（保存时从正文抽取，非 HTML）
 * @property string $seo_description SEO 描述
 * @property string $seo_keywords SEO 关键词
 * @property string $seo_title SEO 标题
 * @property string $source 来源说明
 * @property string|null $extra_json 文档扩展字段键值JSON
 * @property string $subtitle 副标题
 * @property string $summary 摘要（列表/API用）
 * @property string $title 文档标题
 * @property string $tpl_name 前台模板文件名，如 document_view.php
 * @property string $updated_at 更新时间
 * @property string $url_path 前台自定义路径，留空走 /documents
 * @property-read \app\common\model\AiChunk[] $ai_chunks
 * @property-read \app\common\model\DocumentItemRef[] $item_refs
 * @property-read \app\common\model\DocumentTag[] $document_tags
 * @property-read \app\common\model\MemberLevel $read_level
 * @property-read \app\common\model\SiteNav $nav
 * @property-read \app\common\model\User $author
 */
class Document extends Model
{
    protected $name = 'documents';

    protected $type = [
        'author_id' => 'integer',
        'click' => 'integer',
        'external_open_new_tab' => 'integer',
        'id' => 'integer',
        'nav_id' => 'integer',
        'read_level_id' => 'integer',
        'read_perm' => 'integer',
        'status' => 'integer',
    ];

    public $timestamps = false;

    public function itemRefs(): HasMany
    {
        return $this->hasMany(DocumentItemRef::class, 'document_id');
    }

    public function documentTags(): HasMany
    {
        return $this->hasMany(DocumentTag::class, 'document_id');
    }

    public function documentNavs(): HasMany
    {
        return $this->hasMany(DocumentNav::class, 'document_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function nav(): BelongsTo
    {
        return $this->belongsTo(SiteNav::class, 'nav_id');
    }

    public function readLevel(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class, 'read_level_id');
    }

    public function attrFlags(): HasMany
    {
        return $this->hasMany(DocumentAttrFlag::class, 'document_id');
    }

    public function aiChunks(): HasMany
    {
        return $this->hasMany(AiChunk::class, 'document_id');
    }

}
