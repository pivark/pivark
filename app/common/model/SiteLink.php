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
 * 友情链接 pv_site_links
 *
 * @property int $id 主键
 * @property int $open_new_tab 1新窗口打开
 * @property int $sort 排序（越小越靠前）
 * @property int $status 0禁用 1启用
 * @property string $created_at 创建时间
 * @property string $logo_url Logo 图片（可选）
 * @property string $title 链接名称
 * @property string $updated_at 更新时间
 * @property string $url 链接地址
 */
class SiteLink extends Model
{
    protected $name = 'site_links';

        protected $type = [
        'id' => 'integer',
        'open_new_tab' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];

    public $timestamps = false;
}
