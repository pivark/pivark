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
 * Class app\common\model\SchemaMigration
 *
 * @property int $id 主键
 * @property string $applied_at 执行时间
 * @property string $name 迁移脚本名
 */
class SchemaMigration extends Model
{
    protected $name = 'schema_migrations';

        protected $type = [
        'id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
