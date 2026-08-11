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
 * Class app\common\model\LogsArchive
 *
 * @property int $duration 耗时ms
 * @property int $id 原 logs.id
 * @property int $result 0失败 1成功
 * @property int $user_id 操作用户ID
 * @property string $action 操作行为
 * @property string $archived_at 归档时间
 * @property string $created_at 原创建时间
 * @property string $ip 操作IP
 * @property string $module 操作模块
 * @property string $request_method 请求方法
 * @property string $request_params 请求参数
 * @property string $request_url 请求URL
 * @property string $type 日志类型
 * @property string $user_agent UA
 * @property string $username 操作用户名
 * @property-read \app\common\model\User $user
 */
class LogsArchive extends Model
{
    protected $name = 'logs_archive';

        protected $type = [
        'duration' => 'integer',
        'id' => 'integer',
        'result' => 'integer',
        'user_id' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
