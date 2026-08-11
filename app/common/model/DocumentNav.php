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
 * 文档附加栏目 pv_document_navs（主归属仍为 documents.nav_id）
 *
 * @property int $document_id
 * @property int $id
 * @property int $nav_id
 * @property string|null $created_at
 */
class DocumentNav extends Model
{
    protected $name = 'document_navs';

    protected $type = [
        'document_id' => 'integer',
        'id'          => 'integer',
        'nav_id'      => 'integer',
    ];

    public $timestamps = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function nav(): BelongsTo
    {
        return $this->belongsTo(SiteNav::class, 'nav_id');
    }
}
