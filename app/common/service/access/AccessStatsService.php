<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\access;

use app\common\support\AppTime;
use app\common\support\QueryLimit;

use app\common\support\ServiceResult;
use app\common\model\StatsHeatmapDaily;
use app\common\model\StatsBehaviorDaily;
use app\common\model\StatsCrawlerDaily;
use app\common\model\StatsDaily;
use app\common\model\StatsHit;


use app\common\service\config\ConfigService;
use app\common\service\hook\HookService;
use app\common\service\template\TemplateEngine;
use think\facade\Request;
use app\common\support\DbTable;

/** 访问统计（PV/UV、热门路径） */
class AccessStatsService
{

    public function __construct(
        private readonly HookService $hookService,
        private readonly ConfigService $configService,
    ) {
    }

    public const CONFIG_ENABLED = 'stats_enabled';
    public const CONFIG_SAMPLE  = 'stats_sample_rate';
    public const CONFIG_HEATMAP = 'stats_heatmap_enabled';

    private static bool $booted = false;

    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $this->hookService->on('document.view', function (array $payload): void {
            $this->record([
                'path'        => (string) ($payload['path'] ?? ''),
                'referer'     => (string) ($payload['referer'] ?? ''),
                'object_type' => 'document',
                'object_id'   => (int) ($payload['document_id'] ?? 0),
            ]);
        });
        $this->hookService->on('front.page', function (array $payload): void {
            $this->record([
                'path'        => (string) ($payload['path'] ?? ''),
                'referer'     => (string) ($payload['referer'] ?? ''),
                'object_type' => (string) ($payload['object_type'] ?? 'page'),
                'object_id'   => (int) ($payload['object_id'] ?? 0),
            ]);
        });
    }

    public function isEnabled(): bool
    {
        return (int) $this->configService->get(self::CONFIG_ENABLED, 1) === 1;
    }

    /**
     * @param array{path?:string,referer?:string,object_type?:string,object_id?:int} $ctx
     */
    public function record(array $ctx): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $rate = max(1, min(100, (int) $this->configService->get(self::CONFIG_SAMPLE, 100)));
        if ($rate < 100 && random_int(1, 100) > $rate) {
            return;
        }

        $ip   = (string) Request::ip();
        $ua   = substr((string) Request::header('user-agent', ''), 0, 500);
        $now  = AppTime::now();
        $date = AppTime::today();

        $botKey = $this->classifyBot($ua);
        if ($botKey !== '') {
            $this->incrementCrawlerDaily($date, $botKey);
        }

        StatsHit::insert([
            'path'        => mb_substr((string) ($ctx['path'] ?? ''), 0, 500),
            'referer'     => mb_substr((string) ($ctx['referer'] ?? ''), 0, 500),
            'object_type' => mb_substr((string) ($ctx['object_type'] ?? ''), 0, 32),
            'object_id'   => max(0, (int) ($ctx['object_id'] ?? 0)),
            'ip_hash'     => hash('sha256', $ip),
            'ua_hash'     => hash('sha256', $ua),
            'created_at'  => $now,
        ]);

        $row = StatsDaily::where('stat_date', $date)->find();
        if ($row) {
            StatsDaily::where('id', (int) $row['id'])->inc('pv')->update([
                'updated_at' => $now,
            ]);
        } else {
            StatsDaily::insert([
                'stat_date'  => $date,
                'pv'         => 1,
                'uv'         => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array{today:array<string,int>,week:array<string,int>,top_paths:list<array{path:string,pv:int}>}
     */
    public function dashboard(): array
    {
        $today     = AppTime::today();
        $weekStart = AppTime::format('Y-m-d', strtotime('-6 days'));
        $todayRow  = StatsDaily::where('stat_date', $today)->find();
        $weekPv    = (int) StatsDaily::where('stat_date', '>=', $weekStart)->sum('pv');
        $weekUv    = (int) StatsDaily::where('stat_date', '>=', $weekStart)->sum('uv');

        $top = StatsHit::field('path, COUNT(*) AS pv')
            ->where('created_at', '>=', $weekStart . ' 00:00:00')
            ->group('path')
            ->order('pv', 'desc')
            ->limit(QueryLimit::STATS_TOP_SMALL)
            ->select()
            ->toArray();

        return [
            'today' => [
                'pv' => (int) ($todayRow['pv'] ?? 0),
                'uv' => (int) ($todayRow['uv'] ?? 0),
            ],
            'week' => [
                'pv' => $weekPv,
                'uv' => $weekUv,
            ],
            'top_paths' => array_map(static fn ($r) => [
                'path' => (string) ($r['path'] ?? ''),
                'pv'   => (int) ($r['pv'] ?? 0),
            ], $top),
        ];
    }

    /**
     * @return array{enabled:int,sample_rate:int,heatmap_enabled:int}
     */
    public function config(): array
    {
        return [
            'enabled'          => (int) $this->configService->get(self::CONFIG_ENABLED, 1),
            'sample_rate'      => (int) $this->configService->get(self::CONFIG_SAMPLE, 100),
            'heatmap_enabled'  => (int) $this->configService->get(self::CONFIG_HEATMAP, 0),
        ];
    }

    /**
     * 前台行为信标：停留时长、跳出、点击热力格
     *
     * @param array{path?:string,dwell_sec?:int,bounced?:int,click_x?:int,click_y?:int} $payload
     * @return ServiceResult
     */
    public function recordBeacon(array $payload): ServiceResult
    {
        if (!$this->isEnabled()) {
            return ServiceResult::fail('统计已关闭');
        }

        $path = mb_substr(trim((string) ($payload['path'] ?? '/')), 0, 500);
        if ($path === '') {
            $path = '/';
        }
        $dwell   = max(0, min(7200, (int) ($payload['dwell_sec'] ?? 0)));
        $bounced = !empty($payload['bounced']) ? 1 : 0;
        $date    = AppTime::today();

        $this->incrementBehaviorDaily($date, $path, $dwell, $bounced);

        if ((int) $this->configService->get(self::CONFIG_HEATMAP, 0) === 1) {
            $x = max(0, min(100, (int) ($payload['click_x'] ?? -1)));
            $y = max(0, min(100, (int) ($payload['click_y'] ?? -1)));
            if ($x >= 0 && $y >= 0) {
                $this->incrementHeatmapDaily($date, $path, (int) floor($x / 10) * 10 . '_' . (int) floor($y / 10) * 10);
            }
        }

        return ServiceResult::ok(null, 'ok');
    }

    /**
     * @return array{
     *   crawlers:list<array{bot_key:string,hits:int}>,
     *   behavior:list<array{path:string,visits:int,bounces:int,avg_dwell:int,bounce_rate:float}>,
     *   heatmap:list<array{path:string,cell_key:string,hits:int}>
     * }
     */
    public function analyticsDashboard(): array
    {
        $weekStart = AppTime::format('Y-m-d', strtotime('-6 days'));

        $crawlers = [];
        if (DbTable::modelExists(StatsCrawlerDaily::class)) {
            $rows = StatsCrawlerDaily::where('stat_date', '>=', $weekStart)
                ->field('bot_key, SUM(hits) AS hits')
                ->group('bot_key')
                ->order('hits', 'desc')
                ->select()
                ->toArray();
            foreach ($rows as $r) {
                $crawlers[] = [
                    'bot_key' => (string) ($r['bot_key'] ?? ''),
                    'hits'    => (int) ($r['hits'] ?? 0),
                ];
            }
        }

        $behavior = [];
        if (DbTable::modelExists(StatsBehaviorDaily::class)) {
            $rows = StatsBehaviorDaily::where('stat_date', '>=', $weekStart)
                ->field('path, SUM(visits) AS visits, SUM(bounces) AS bounces, SUM(dwell_total_sec) AS dwell')
                ->group('path')
                ->order('visits', 'desc')
                ->limit(QueryLimit::STATS_TOP_MEDIUM)
                ->select()
                ->toArray();
            foreach ($rows as $r) {
                $visits = (int) ($r['visits'] ?? 0);
                $bounces = (int) ($r['bounces'] ?? 0);
                $dwell = (int) ($r['dwell'] ?? 0);
                $behavior[] = [
                    'path'         => (string) ($r['path'] ?? ''),
                    'visits'       => $visits,
                    'bounces'      => $bounces,
                    'avg_dwell'    => $visits > 0 ? (int) round($dwell / $visits) : 0,
                    'bounce_rate'  => $visits > 0 ? round($bounces / $visits, 3) : 0.0,
                ];
            }
        }

        $heatmap = [];
        if (DbTable::modelExists(StatsHeatmapDaily::class)) {
            $rows = StatsHeatmapDaily::where('stat_date', '>=', $weekStart)
                ->field('path, cell_key, SUM(hits) AS hits')
                ->group('path, cell_key')
                ->order('hits', 'desc')
                ->limit(QueryLimit::MINIPROGRAM_WIDGET_MAX)
                ->select()
                ->toArray();
            foreach ($rows as $r) {
                $heatmap[] = [
                    'path'     => (string) ($r['path'] ?? ''),
                    'cell_key' => (string) ($r['cell_key'] ?? ''),
                    'hits'     => (int) ($r['hits'] ?? 0),
                ];
            }
        }

        return [
            'crawlers' => $crawlers,
            'behavior' => $behavior,
            'heatmap'  => $heatmap,
        ];
    }

    public function saveConfig(array $data): ServiceResult
    {
        $this->configService->set(self::CONFIG_ENABLED, !empty($data['enabled']) ? '1' : '0');
        $rate = max(1, min(100, (int) ($data['sample_rate'] ?? 100)));
        $this->configService->set(self::CONFIG_SAMPLE, (string) $rate);
        $this->configService->set(self::CONFIG_HEATMAP, !empty($data['heatmap_enabled']) ? '1' : '0');

        return ServiceResult::ok(null, '保存成功');
    }

    private function classifyBot(string $ua): string
    {
        $u = strtolower($ua);
        if ($u === '') {
            return '';
        }
        $map = [
            'baiduspider' => 'baidu',
            'googlebot'   => 'google',
            'bingbot'     => 'bing',
            'sogou'       => 'sogou',
            '360spider'   => '360',
            'yandex'      => 'yandex',
            'petalbot'    => 'petal',
        ];
        foreach ($map as $needle => $key) {
            if (str_contains($u, $needle)) {
                return $key;
            }
        }
        if (preg_match('/bot|spider|crawl|slurp/i', $ua)) {
            return 'other_bot';
        }

        return '';
    }

    private function incrementCrawlerDaily(string $date, string $botKey): void
    {
        if (!DbTable::modelExists(StatsCrawlerDaily::class)) {
            return;
        }
        $row = StatsCrawlerDaily::where(['stat_date' => $date, 'bot_key' => $botKey])->find();
        if ($row) {
            StatsCrawlerDaily::where('id', (int) $row['id'])->inc('hits')->update([
                'updated_at' => AppTime::now(),
            ]);
        } else {
            StatsCrawlerDaily::insert([
                'stat_date'  => $date,
                'bot_key'    => $botKey,
                'hits'       => 1,
                'created_at' => AppTime::now(),
                'updated_at' => AppTime::now(),
            ]);
        }
    }

    private function incrementBehaviorDaily(string $date, string $path, int $dwell, int $bounced): void
    {
        if (!DbTable::modelExists(StatsBehaviorDaily::class)) {
            return;
        }
        $row = StatsBehaviorDaily::where(['stat_date' => $date, 'path' => $path])->find();
        if ($row) {
            StatsBehaviorDaily::where('id', (int) $row['id'])->update([
                'visits'          => (int) $row['visits'] + 1,
                'bounces'         => (int) $row['bounces'] + $bounced,
                'dwell_total_sec' => (int) $row['dwell_total_sec'] + $dwell,
                'updated_at'      => AppTime::now(),
            ]);
        } else {
            StatsBehaviorDaily::insert([
                'stat_date'       => $date,
                'path'            => $path,
                'visits'          => 1,
                'bounces'         => $bounced,
                'dwell_total_sec' => $dwell,
                'created_at'      => AppTime::now(),
                'updated_at'      => AppTime::now(),
            ]);
        }
    }

    private function incrementHeatmapDaily(string $date, string $path, string $cellKey): void
    {
        if (!DbTable::modelExists(StatsHeatmapDaily::class)) {
            return;
        }
        $row = StatsHeatmapDaily::where([
            'stat_date' => $date,
            'path'      => $path,
            'cell_key'  => $cellKey,
        ])->find();
        if ($row) {
            StatsHeatmapDaily::where('id', (int) $row['id'])->inc('hits')->update([
                'updated_at' => AppTime::now(),
            ]);
        } else {
            StatsHeatmapDaily::insert([
                'stat_date'  => $date,
                'path'       => $path,
                'cell_key'   => $cellKey,
                'hits'       => 1,
                'created_at' => AppTime::now(),
                'updated_at' => AppTime::now(),
            ]);
        }
    }
}
