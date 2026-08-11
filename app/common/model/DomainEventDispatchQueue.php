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
 * Class app\common\model\DomainEventDispatchQueue
 *
 * @property int $attempts 已尝试次数
 * @property int $id 主键
 * @property string $created_at 入队时间
 * @property string $event_name 白名单事件名
 * @property string $last_error 上次错误
 * @property string $payload_json JSON 载荷
 * @property string $updated_at 更新时间
 */
class DomainEventDispatchQueue extends Model
{
    protected $name = 'domain_event_dispatch_queue';

    protected $type = [
        'attempts' => 'integer',
        'id'       => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
