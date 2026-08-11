<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\static;

use app\common\support\AppTime;

/** 静态化容量规划（商务/运维：worker 数、cron、耗时反推） */
final class StaticCapacityPlanner
{

    /** 无 benchmark 时的保守默认（篇/秒/单 worker） */
    public const DEFAULT_DOCS_PER_SEC_CONSERVATIVE = 1.5;

    /** 模板较轻、SSD 时的参考上限 */
    public const DEFAULT_DOCS_PER_SEC_OPTIMISTIC = 6.0;

    /**
     * @return array{
     *   articles:int,
     *   cores:int,
     *   target_hours:float,
     *   docs_per_sec_per_worker:float,
     *   recommended_workers:int,
     *   effective_workers:int,
     *   est_hours_one_worker:float,
     *   est_hours_planned:float,
     *   cron_batch:int,
     *   cron_interval_min:int,
     *   cron_workers_equiv:float,
     *   disk_gb_est:float,
     *   notes:list<string>
     * }
     */
    public function plan(
        int $articles,
        int $cores,
        float $targetHours,
        float $docsPerSecPerWorker = 0.0,
        int $cronBatch = 500,
        int $cronIntervalMin = 2,
    ): array {
        $articles    = max(1, $articles);
        $cores       = max(1, min(128, $cores));
        $targetHours = max(0.5, $targetHours);
        $rate        = $docsPerSecPerWorker > 0
            ? $docsPerSecPerWorker
            : self::DEFAULT_DOCS_PER_SEC_CONSERVATIVE;

        $maxWorkersByCpu = max(1, $cores * 2);
        $needWorkers     = (int) ceil($articles / ($targetHours * 3600 * $rate));
        $recommended     = min($maxWorkersByCpu, max(1, $needWorkers));
        $effective       = min($recommended, $needWorkers > 0 ? $needWorkers : 1);

        $estOneWorker = $articles / max(0.1, $rate) / 3600;
        $estPlanned   = $articles / max(0.1, $rate * $recommended) / 3600;

        $cronPerHour = ($cronBatch / max(1, $cronIntervalMin)) * 60;
        $cronEquiv   = round($cronPerHour / 3600, 2);

        $avgKbPerPage = 48;
        $diskGb       = round($articles * $avgKbPerPage / 1024 / 1024, 2);

        $notes = [];
        if ($needWorkers > $maxWorkersByCpu) {
            $notes[] = sprintf(
                '按目标 %s 小时算需约 %d 个 worker，建议增至 %d 核或延长窗口至约 %.1f 小时',
                $targetHours,
                $needWorkers,
                (int) ceil($needWorkers / 2),
                $articles / ($maxWorkersByCpu * $rate * 3600)
            );
        }
        if ($docsPerSecPerWorker <= 0) {
            $notes[] = '未提供实测吞吐，按保守默认 ' . $rate . ' 篇/秒/worker 估算；请运行 npm run static:benchmark 校准';
        }

        return [
            'articles'                  => $articles,
            'cores'                     => $cores,
            'target_hours'              => $targetHours,
            'docs_per_sec_per_worker'   => round($rate, 3),
            'recommended_workers'       => $recommended,
            'effective_workers'         => $effective,
            'est_hours_one_worker'      => round($estOneWorker, 2),
            'est_hours_planned'         => round($estPlanned, 2),
            'cron_batch'                => max(50, $cronBatch),
            'cron_interval_min'         => max(1, $cronIntervalMin),
            'cron_workers_equiv'        => $cronEquiv,
            'disk_gb_est'               => $diskGb,
            'notes'                     => $notes,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function toMarkdown(array $plan, string $siteLabel = 'Pivark 站点'): string
    {
        $lines   = [];
        $lines[] = '# 静态化容量规划 — ' . $siteLabel;
        $lines[] = '';
        $lines[] = '> 生成时间：' . AppTime::now();
        $lines[] = '';
        $lines[] = '## 输入';
        $lines[] = '';
        $lines[] = '| 项 | 值 |';
        $lines[] = '|---|---|';
        $lines[] = '| 已发布文档数 | ' . (int) ($plan['articles'] ?? 0) . ' |';
        $lines[] = '| CPU 核数 | ' . (int) ($plan['cores'] ?? 0) . ' |';
        $lines[] = '| 目标完成时间 | ' . (float) ($plan['target_hours'] ?? 0) . ' 小时 |';
        $lines[] = '| 实测吞吐 | ' . (float) ($plan['docs_per_sec_per_worker'] ?? 0) . ' 篇/秒/worker |';
        $lines[] = '';
        $lines[] = '## 建议配置';
        $lines[] = '';
        $lines[] = '| 项 | 建议 |';
        $lines[] = '|---|---|';
        $lines[] = '| 并行 worker 进程数 | **' . (int) ($plan['recommended_workers'] ?? 1) . '** |';
        $lines[] = '| 每 worker `--batch` | 500～800 |';
        $lines[] = '| cron `static_build_queue_drain` | batch=' . (int) ($plan['cron_batch'] ?? 500) . '，间隔 ' . (int) ($plan['cron_interval_min'] ?? 2) . ' 分钟 |';
        $lines[] = '| 预估磁盘（HTML） | ~' . (float) ($plan['disk_gb_est'] ?? 0) . ' GB |';
        $lines[] = '| 单 worker 全量耗时 | ~' . (float) ($plan['est_hours_one_worker'] ?? 0) . ' 小时 |';
        $lines[] = '| 按建议 worker 耗时 | ~' . (float) ($plan['est_hours_planned'] ?? 0) . ' 小时 |';
        $lines[] = '';
        $notes = $plan['notes'] ?? [];
        if (is_array($notes) && $notes !== []) {
            $lines[] = '## 说明';
            $lines[] = '';
            foreach ($notes as $note) {
                $lines[] = '- ' . (string) $note;
            }
            $lines[] = '';
        }
        $lines[] = '## 执行命令（运维）';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = 'php migrations/run.php';
        $lines[] = '# 完整开发仓可用静态生成 worker；发行包请用后台「静态生成」或发行说明中的运维方式';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## 访问层';
        $lines[] = '';
        $lines[] = '- 源站 Nginx 直出 `public/` 下静态 HTML';
        $lines[] = '- 前置 CDN 回源；动态接口与静态分离';
        $lines[] = '- 搜索走 Meilisearch，不占 MySQL 前台检索';

        return implode("\n", $lines) . "\n";
    }
}
