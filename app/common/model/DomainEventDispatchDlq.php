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
 * Class app\common\model\DomainEventDispatchDlq
 *
 * @property int $attempts 失败前尝试次数
 * @property int $id 主键
 * @property int $queue_id 原队列 id
 * @property string $event_name 事件名
 * @property string $failed_at 迁入 DLQ 时间
 * @property string $last_error 末次错误
 * @property string $payload_json JSON 载荷
 * @property-read \app\common\model\DomainEventDispatchQueue $queue
 */
class DomainEventDispatchDlq extends Model
{
    protected $name = 'domain_event_dispatch_dlq';

    protected $type = [
        'attempts'        => 'integer',
        'id'              => 'integer',
        'queue_id'        => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function queue(): BelongsTo
    {
        return $this->belongsTo(DomainEventDispatchQueue::class, 'queue_id');
    }

}
