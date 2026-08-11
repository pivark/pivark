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
 * Class app\common\model\DomainEventLog
 *
 * @property int $id 主键
 * @property string $created_at 创建时间
 * @property string $event_name 事件名
 * @property string $payload_json payload JSON
 * @property string $source 来源 core|error|插件标识
 */
class DomainEventLog extends Model
{
    protected $name = 'domain_event_log';

        protected $type = [
        'id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
