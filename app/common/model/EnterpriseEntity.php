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
 * 公司经营主体（多法人/集团共享）· 表 weapp_tender_entities 历史名保留
 *
 * @property int $id
 * @property int $is_default
 * @property int $status
 * @property string $address
 * @property string $contact_phone
 * @property string $created_at
 * @property string $credit_code
 * @property string $legal_person
 * @property string $name
 * @property string $updated_at
 */
class EnterpriseEntity extends Model
{
    protected $name = 'weapp_tender_entities';

    protected $type = [
        'id'         => 'integer',
        'is_default' => 'integer',
        'status'     => 'integer',
    ];

    protected $autoWriteTimestamp = false;
}
