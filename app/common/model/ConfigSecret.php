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
 * 系统凭据（加密存储 · 阶段 G）
 *
 * @property int $id 主键
 * @property string $key configs 键名
 * @property string $updated_at 更新时间
 * @property string $value AppCipher 加密值
 */
class ConfigSecret extends Model
{
    protected $name = 'config_secrets';

    protected $pk = 'id';

    protected $type = [
        'id' => 'integer',
    ];

    public $timestamps = false;

    public static function findByKey(string $key): ?self
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        return self::where('key', $key)->find();
    }
}
