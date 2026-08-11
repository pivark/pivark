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
 * 企业经营资料索引 · 表 weapp_tender_assets（历史表名）
 *
 * @property int $document_id
 * @property int $entity_id
 * @property int $id
 * @property int $sort
 * @property string $asset_type
 * @property string $created_at
 * @property string $file_path
 * @property string $keywords
 * @property string $scope
 * @property string $status
 * @property string $summary
 * @property string $title
 * @property string $updated_at
 * @property string $valid_until
 */
class EnterpriseAsset extends Model
{
    protected $name = 'weapp_tender_assets';

    protected $type = [
        'document_id' => 'integer',
        'entity_id'   => 'integer',
        'id'          => 'integer',
        'sort'        => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(EnterpriseEntity::class, 'entity_id');
    }
}
