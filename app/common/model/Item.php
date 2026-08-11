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
 * 品项 pv_items
 *
 * @property int $id 主键
 * @property int $nav_id 真分类 site_nav.id，0=未归类
 * @property int $primary_document_id 默认详情文档
 * @property int $sort 排序
 * @property mixed $attrs 规格 JSON
 * @property mixed $flags sellable/purchasable/manufacturable
 * @property string $code 货号
 * @property string $created_at 创建时间
 * @property string $item_type physical/service/digital/component/kit
 * @property string $name 名称
 * @property string $slug 前台 slug
 * @property string $status draft/active/discontinued
 * @property string $updated_at 更新时间
 * @property-read \app\common\model\Document $primary_document
 * @property-read \app\common\model\DocumentItemRef[] $document_refs
 * @property-read \app\common\model\ItemAttrValue[] $attr_values
 * @property-read \app\common\model\ItemTag[] $item_tags
 * @property-read \app\common\model\ItemVariant[] $variants
 * @property-read \app\common\model\SiteNav $nav
 */
class Item extends Model
{
    protected $name = 'items';

    /**
     * 品项表无这些列（简介在 attrs.official_catalog.summary）。
     * Query::update 不走模型事件，故另在 WeappItemGateway::itemUpdateById 剥离。
     *
     * @var list<string>
     */
    public const GHOST_COLUMNS = ['summary', 'description', 'price', 'sale_price'];

    protected $type = [
        'id' => 'integer',
        'nav_id' => 'integer',
        'primary_document_id' => 'integer',
        'sort' => 'integer',
    ];

    public $timestamps = false;

    protected $json = ['attrs', 'flags'];

    protected $jsonAssoc = true;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function withoutGhostColumns(array $data): array
    {
        foreach (self::GHOST_COLUMNS as $col) {
            unset($data[$col]);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updateById(int $id, array $data): int
    {
        if ($id < 1) {
            return 0;
        }
        $data = self::withoutGhostColumns($data);
        if ($data === []) {
            return 0;
        }

        return (int) self::where('id', $id)->update($data);
    }

    public function documentRefs(): HasMany
    {
        return $this->hasMany(DocumentItemRef::class, 'item_id');
    }
    public function primaryDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'primary_document_id');
    }

    public function nav(): BelongsTo
    {
        return $this->belongsTo(SiteNav::class, 'nav_id');
    }

    public function attrValues(): HasMany
    {
        return $this->hasMany(ItemAttrValue::class, 'item_id');
    }

    public function itemTags(): HasMany
    {
        return $this->hasMany(ItemTag::class, 'item_id');
    }

    public function itemNavs(): HasMany
    {
        return $this->hasMany(ItemNav::class, 'item_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class, 'item_id');
    }

}
