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
 * Class app\common\model\MediaAssetRef
 *
 * @property int $id 主键
 * @property int $media_asset_id media_assets.id
 * @property int $ref_id 业务主键，如 document_id
 * @property string $created_at 创建时间
 * @property string $field_key 字段或子项标识
 * @property string $path_snapshot 冗余 path 便于查询
 * @property string $ref_type document_litpic|document_content|document_download|document_video|library
 * @property-read \app\common\model\MediaAsset $media_asset
 */
class MediaAssetRef extends Model
{
    protected $name = 'media_asset_refs';

        protected $type = [
        'id' => 'integer',
        'media_asset_id' => 'integer',
        'ref_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

}
