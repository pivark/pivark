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
 * 媒体资产索引 pv_media_assets
 *
 * @property int $file_size 字节
 * @property int $id 主键
 * @property int $ref_count 引用次数
 * @property string $content_hash SHA256 十六进制
 * @property string $created_at 入库时间
 * @property string $ext 扩展名
 * @property string $mime MIME
 * @property string $original_name 原始文件名
 * @property string $path 相对路径 uploads/…
 * @property string $scene 上传场景
 * @property string $updated_at 更新时间
 * @property string $url 访问 URL
 */
class MediaAsset extends Model
{
    protected $name = 'media_assets';

        protected $type = [
        'file_size' => 'integer',
        'id' => 'integer',
        'ref_count' => 'integer',
    ];

    public $timestamps = false;
}
