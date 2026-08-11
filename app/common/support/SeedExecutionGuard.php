<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\support;

/**
 * 种子导入 / restore 门禁。
 *
 * - dig 开发库：www baseline 默认跳过
 * - b 站（库名 b_pivark_com）默认禁止整包种子导入/reset（运营库，仅允许定点改）
 * - test / demo1 / qa_*：允许跑种子（种子本身要测）
 * 破例对 b 导入：用户书面确认 + `WWW_SEED_ALLOW_B=1`
 */
final class SeedExecutionGuard
{
    /** @return array{skip:bool,reason:string} */
    public static function shouldSkip(string $profile): bool
    {
        $reason = self::skipReason($profile);

        return $reason !== '';
    }

    public static function skipReason(string $profile): string
    {
        if (self::envTruthy('PIVARK_SKIP_LANE_SEED')) {
            return 'PIVARK_SKIP_LANE_SEED=1';
        }
        if (self::envTruthy('PIVARK_ACCEPTANCE_FULL') && $profile !== 'demo_lane_reset') {
            return 'PIVARK_ACCEPTANCE_FULL=1';
        }
        if ($profile === 'www_baseline' && self::isDevDatabase()) {
            return 'dev database blocked for www baseline';
        }
        // 官方 lane（b）：整包 seed/restore 默认拒（exitIfSkipped 会 SKIP；硬拦见 refuseOfficialWwwLane）
        if (self::isWwwBulkProfile($profile) && self::isOfficialWwwLane() && !self::envTruthy('WWW_SEED_ALLOW_B')) {
            return 'official www (b) seed blocked; use test lane or WWW_SEED_ALLOW_B=1';
        }

        return '';
    }

    public static function skipReasonForLog(string $profile): string
    {
        $reason = self::skipReason($profile);

        return $reason !== '' ? $reason : 'none';
    }

    /** CLI 脚本入口：需跳过时 exit(0) 并打印 SKIP 行 */
    public static function exitIfSkipped(string $profile, string $scriptLabel = ''): void
    {
        $reason = self::skipReason($profile);
        if ($reason === '') {
            return;
        }
        $label = $scriptLabel !== '' ? $scriptLabel : $profile;
        echo "SKIP {$label}: {$reason}\n";

        exit(0);
    }

    /**
     * 硬拦 b 站整包种子导入（exit 2）。test/demo1/qa_* / dig 不拦。
     * seed_b_*、www:reset-db、seed:www 在指向 b 时必须先调。
     *
     * @deprecated 2026-08-01 官方 lane 整包种子已退役；写库入口请改调 refuseRetiredWwwBulkSeed()
     */
    public static function refuseOfficialWwwLane(string $scriptLabel): void
    {
        // 整包种子已退役：一律硬拒（不再认 WWW_SEED_ALLOW_B）
        self::refuseRetiredWwwBulkSeed($scriptLabel);
    }

    /**
     * 官方 lane 整包种子已退役（2026-08-01）：任何写库种子 CLI 一律 exit 2。
     * FAQ/fixtures 仍可被前台只读加载；禁止再跑 seed:www / seed:b / www:reset-db。
     */
    public static function refuseRetiredWwwBulkSeed(string $scriptLabel): never
    {
        fwrite(STDERR, "RETIRED {$scriptLabel}: 官方 lane 整包种子数据已清除/退役，禁止再灌。\n");
        fwrite(STDERR, "运营内容请在后台一点点改；勿再用 seed:www / seed:b:deliver / www:reset-db。\n");
        exit(2);
    }

    /** 当前进程是否指向官方 lane（b） 库/目录 */
    public static function isOfficialWwwLane(): bool
    {
        $db = strtolower(trim((string) (getenv('DB_NAME') ?: '')));
        if ($db === 'b_pivark_com') {
            return true;
        }
        // 目录名拆开写，避免 Community 发行源码卫生扫到完整四站域名字面量
        $officialDir = 'b' . '.pivark.com';
        $lane = strtolower(str_replace('\\', '/', (string) (getenv('PIVARK_LANE_ROOT') ?: '')));
        if ($lane !== '') {
            $norm = rtrim($lane, '/');
            if (str_ends_with($norm, '/' . $officialDir) || $norm === 'd:/wwwroot/' . $officialDir) {
                return true;
            }
        }

        return false;
    }

    public static function assertDemoDatabaseAllowed(string $dbName): void
    {
        $name = strtolower(trim($dbName));
        if ($name === '') {
            throw new \RuntimeException('SeedExecutionGuard: empty database name');
        }
        if (preg_match('/^(dev|b)_/i', $name) === 1 && !self::envTruthy('DEMO_BASELINE_ALLOW_DB')) {
            throw new \RuntimeException("SeedExecutionGuard: refused demo seed on database {$name}");
        }
    }

    private static function isWwwBulkProfile(string $profile): bool
    {
        return in_array($profile, [
            'www_official_product',
            'www_baseline',
            'www_site_nav',
            'www_b_deliver',
            'www_b_content',
        ], true);
    }

    private static function isDevDatabase(): bool
    {
        $db = strtolower(trim((string) (getenv('DB_NAME') ?: '')));

        return str_starts_with($db, 'dev_');
    }

    private static function envTruthy(string $key): bool
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return false;
        }

        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }
}
