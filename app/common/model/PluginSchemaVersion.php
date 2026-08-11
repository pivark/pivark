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
 * 插件 schema 版本（表 weapp_plugin_schema_versions · 原 PluginSchemaVersion）
 *
 * @property int $id 主键
 * @property int $version 已应用最高台阶版本
 * @property string $applied_at 最后应用时间
 * @property string $identifier 插件 identifier
 */
class PluginSchemaVersion extends Model
{
    protected $name = 'weapp_plugin_schema_versions';

    protected $type = [
        'id'      => 'integer',
        'version' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
