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
 * Class app\common\model\SearchQueryLog
 *
 * @property int $doc_count 文档命中数
 * @property int $hit_count 命中条数
 * @property int $id 主键
 * @property int $product_count 品项命中数
 * @property int $zero_result 是否零结果：0否 1是
 * @property string $created_at 创建时间
 * @property string $keyword 搜索关键词
 * @property string $mode 模式
 */
class SearchQueryLog extends Model
{
    protected $name = 'search_query_log';

        protected $type = [
        'doc_count' => 'integer',
        'hit_count' => 'integer',
        'id' => 'integer',
        'product_count' => 'integer',
        'zero_result' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
