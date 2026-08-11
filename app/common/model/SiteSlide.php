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
 * 站点广告素材 pv_site_slides（兼容旧「幻灯片」）
 *
 * @property int $id 主键
 * @property int $open_new_tab 1新窗口打开
 * @property int $sort 排序（越小越靠前）
 * @property int $status 0禁用 1启用
 * @property string $created_at 创建时间
 * @property string $creative_type 创意类型：carousel/single_image/image_text/html
 * @property string $display_scope 展示范围 all=全站 home=仅首页（全屏/悼念类）
 * @property string $effective_end_at 生效结束，空=不限
 * @property string $effective_start_at 生效开始，空=不限
 * @property string $html_body HTML/第三方代码（creative_type=html）
 * @property string $image_url 图片 URL
 * @property string $link_text 按钮文字
 * @property string $link_url 跳转链接
 * @property string $slot 字段：slot
 * @property string $subtitle 副标题/描述
 * @property string $title 标题
 * @property string $updated_at 更新时间
 */
class SiteSlide extends Model
{
    protected $name = 'site_slides';

        protected $type = [
        'id' => 'integer',
        'open_new_tab' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
    ];

    public $timestamps = false;
}
