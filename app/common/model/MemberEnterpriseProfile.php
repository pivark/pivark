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
 * 会员企业资料
 *
 * @property int $id
 * @property int $user_id
 * @property string $company_name
 * @property string $contact_name
 * @property string $contact_phone
 * @property string $usci
 * @property string $job_title
 * @property string $company_email
 */
class MemberEnterpriseProfile extends Model
{
    protected $name = 'member_enterprise_profiles';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'created_at';

    protected $updateTime = 'updated_at';
}
