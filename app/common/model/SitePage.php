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
 * 单页 pv_site_pages
 *
 * @property int $id 主键
 * @property int $status 0禁用 1启用
 * @property string $content 单页正文 HTML
 * @property string $created_at 创建时间
 * @property string $path 前台访问路径（不含域名，如 guanyu）
 * @property string $seo_description SEO描述
 * @property string $seo_keywords SEO关键词
 * @property string $seo_title SEO标题
 * @property string $title 页面标题
 * @property string $tpl_name 模板文件名（不含.php，如 about）
 * @property string $updated_at 更新时间
 */
class SitePage extends Model
{
    protected $name = 'site_pages';

        protected $type = [
        'id' => 'integer',
        'status' => 'integer',
    ];

    public $timestamps = false;
}
