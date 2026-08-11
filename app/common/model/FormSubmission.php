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
 * Class app\common\model\FormSubmission
 *
 * @property int $form_id 表单 ID
 * @property int $id 主键
 * @property int $status 0未读 1已读 2已处理
 * @property mixed $payload_json 提交内容
 * @property string $created_at 提交时间
 * @property string $ip_hash IP 哈希
 * @property-read \app\common\model\Form $form
 */
class FormSubmission extends Model
{
    protected $name = 'form_submissions';

        protected $type = [
        'form_id' => 'integer',
        'id' => 'integer',
        'status' => 'integer',
    ];

    protected $autoWriteTimestamp = false;
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

}
