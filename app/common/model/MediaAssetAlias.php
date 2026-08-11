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
 * Class app\common\model\MediaAssetAlias
 *
 * @property int $created_by 字段：created_by
 * @property int $id 主键
 * @property int $media_asset_id media_asset ID
 * @property string $created_at 创建时间
 * @property string $display_name 字段：display_name
 * @property-read \app\common\model\MediaAsset $media_asset
 */
class MediaAssetAlias extends Model
{
    protected $name = 'media_asset_aliases';

        protected $type = [
        'created_by' => 'integer',
        'id' => 'integer',
        'media_asset_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

}
