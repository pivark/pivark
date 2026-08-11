<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\common\model;

use app\common\support\AppTime;
use think\Model;
use think\model\relation\BelongsTo;
use think\model\relation\HasMany;

// app/common/model/User.php — 用户模型

/**
 * Class app\common\model\User
 *
 * @property float $member_balance 会员余额（元）
 * @property int $id 主键
 * @property int $member_growth 成长值
 * @property int $member_level_id 会员等级ID，0=未分配
 * @property int $member_points 会员积分余额
 * @property int $must_change_password 1=登录后须改密
 * @property int $security_level 密级：1公开~5绝密
 * @property int $status 状态：0禁用 1正常
 * @property int $totp_enabled TOTP enabled
 * @property string $avatar 头像URL
 * @property string $created_at 创建时间
 * @property string $email 邮箱
 * @property string $email_verified_at 邮箱验证通过时间
 * @property string $email_verify_sent_at 验证邮件发送时间
 * @property string $email_verify_token 注册邮件验证令牌
 * @property string $last_login_ip 最后登录IP
 * @property string $last_login_time 最后登录时间
 * @property string $member_level_expire_at 会员等级到期时间，NULL=永久
 * @property string $member_remark 会员备注
 * @property string $mobile 手机号
 * @property string $nickname 昵称
 * @property string $password 密码（bcrypt）
 * @property string $register_ip 注册 IP
 * @property string $totp_secret TOTP secret(base32)
 * @property string $updated_at 更新时间
 * @property string $username 用户名（唯一）
 * @property-read \app\common\model\MemberBalanceLog[] $balance_logs
 * @property-read \app\common\model\MemberLevel $member_level
 * @property-read \app\common\model\MemberPointLog[] $point_logs
 * @property-read \app\common\model\UserOauthBinding[] $oauth_bindings
 * @property-read \app\common\model\UserRole[] $user_roles
 */
class User extends Model
{
    protected $name = 'users';

    /** @var list<string> */
    protected $hidden = ['password', 'totp_secret', 'email_verify_token'];

        protected $type = [
        'id' => 'integer',
        'member_balance' => 'float',
        'member_growth' => 'integer',
        'member_level_id' => 'integer',
        'member_points' => 'integer',
        'must_change_password' => 'integer',
        'security_level' => 'integer',
        'status' => 'integer',
    ];

    protected $autoWriteTimestamp = false;

    public static function findByUsername(string $username): ?User
    {
        return self::where('username', $username)
            ->where('status', 1)
            ->find();
    }

    public static function updateLoginInfo(int $userId, string $ip): void
    {
        self::where('id', $userId)->update([
            'last_login_ip'   => $ip,
            'last_login_time' => AppTime::now(),
        ]);
    }

    public function balanceLogs(): HasMany
    {
        return $this->hasMany(MemberBalanceLog::class, 'user_id');
    }

    public function pointLogs(): HasMany
    {
        return $this->hasMany(MemberPointLog::class, 'user_id');
    }
    public function memberLevel(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class, 'member_level_id');
    }

    public function oauthBindings(): HasMany
    {
        return $this->hasMany(UserOauthBinding::class, 'user_id');
    }

    /** 勿命名 roles — 与 pv_roles 表/Role 模型冲突，whereHas 会报 ROLES 表达式错误 */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'user_id');
    }

}
