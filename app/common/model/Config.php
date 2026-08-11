<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\common\model;

use think\Model;

/**
 * Class app\common\model\Config
 *
 * @property int $id 主键
 * @property string $created_at 创建时间
 * @property string $description 配置说明
 * @property string $group 配置分组
 * @property string $key 配置键名
 * @property string $type 值类型（string/json/number/boolean）
 * @property string $updated_at 更新时间
 * @property string $value 配置值
 */
class Config extends Model
{
    protected $name = 'configs';

        protected $type = [
        'id' => 'integer',
    ];

    /** 表主键为 id；业务查询用 where('key', …)，勿将 key 当作 PK */
    protected $pk = 'id';

    public $timestamps = false;

    /** configs.value 列最大长度（与表结构一致） */
    public const MAX_VALUE_BYTES = 65535;

    public static function getAll()
    {
        return self::select()->column('value', 'key');
    }

    /**
     * @throws \InvalidArgumentException 值超长
     */
    public static function setValue(string $key, $value): void
    {
        $stored = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
        if (strlen($stored) > self::MAX_VALUE_BYTES) {
            throw new \InvalidArgumentException('配置项「' . $key . '」超过最大长度 ' . self::MAX_VALUE_BYTES);
        }

        $config = self::where('key', $key)->find();
        if ($config) {
            $config->value = $stored;
            $config->save();
        } else {
            self::create(['key' => $key, 'value' => $stored]);
        }
    }

    public static function deleteByPrefix(string $prefix): int
    {
        return self::where('key', 'like', $prefix . '%')->delete();
    }

    /**
     * 按精确键名删除（非前缀）。
     *
     * @param list<string> $keys
     */
    public static function deleteByKeys(array $keys): int
    {
        $keys = array_values(array_unique(array_filter(array_map(
            static fn ($k): string => trim((string) $k),
            $keys
        ), static fn (string $k): bool => $k !== '')));
        if ($keys === []) {
            return 0;
        }

        return (int) self::whereIn('key', $keys)->delete();
    }
}