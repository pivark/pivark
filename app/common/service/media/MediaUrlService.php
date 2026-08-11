<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\media;

use app\common\service\config\ConfigService;
use app\common\support\AppTime;
use app\common\support\LocalFile;
use app\common\support\ProjectPaths;
use app\common\support\ServiceResult;
use app\common\support\SiteUrl;
use think\facade\Db;
use think\facade\Request;

/**
 * 站内地址模式 SSOT（media_url_mode）：素材 uploads + 正文内本站链接。
 * relative = 路径不带域名；absolute = 带 site_url。
 * 前台出站路径经 SiteUrl::public() 跟同一开关；OG/sitemap/二维码走 SiteUrl::absolute。
 */
final class MediaUrlService
{
    public const CONFIG_KEY = 'media_url_mode';

    public const MODE_RELATIVE = 'relative';

    public const MODE_ABSOLUTE = 'absolute';

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public function mode(): string
    {
        return $this->normalizeMode((string) $this->configService->get(self::CONFIG_KEY, self::MODE_RELATIVE));
    }

    public function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return $mode === self::MODE_ABSOLUTE ? self::MODE_ABSOLUTE : self::MODE_RELATIVE;
    }

    public function isAbsolute(): bool
    {
        return $this->mode() === self::MODE_ABSOLUTE;
    }

    public function isRelative(): bool
    {
        return !$this->isAbsolute();
    }

    /**
     * 上传回写、素材库列表、封面/二维码等单字段落库。
     * 仅处理本站 uploads；外链原样返回。
     */
    public function formatForStorage(string $pathOrUrl): string
    {
        $pathOrUrl = trim($pathOrUrl);
        if ($pathOrUrl === '') {
            return '';
        }

        $uploads = $this->extractUploadsPublicPath($pathOrUrl);
        if ($uploads === null) {
            return $pathOrUrl;
        }

        if ($this->isRelative()) {
            return $uploads;
        }

        return SiteUrl::public($uploads);
    }

    /**
     * 正文 / JSON / 配置长文本：按当前模式重写本站 uploads 与本站内容链接。
     * 外站 CDN / 外链不改。
     */
    public function rewriteText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // 1) 本站绝对 URL → 路径（uploads 与栏目/文章链接）
        $normalized = preg_replace_callback(
            '#(?:https?:)?//([^/\s"\'<>]+)(/[^\s"\'<>]*)#i',
            function (array $m): string {
                $host = strtolower((string) ($m[1] ?? ''));
                $path = (string) ($m[2] ?? '');
                if ($host === '' || $path === '' || !$this->isLocalMediaHost($host)) {
                    return $m[0];
                }

                return $path === '' ? '/' : $path;
            },
            $text
        );
        if (!is_string($normalized)) {
            $normalized = $text;
        }

        if ($this->isRelative()) {
            return $normalized;
        }

        $home = rtrim(SiteUrl::configuredPublicHome(), '/');
        if ($home === '' || !preg_match('#^https?://#i', $home)) {
            $scheme = SiteUrl::publicScheme();
            $host   = trim((string) Request::host());
            if ($host === '') {
                return $normalized;
            }
            $home = $scheme . '://' . $host;
        }

        // 2) absolute：href/src/url() 中的站内根路径加域名（勿动 //cdn 协议相对）
        $rewritten = preg_replace_callback(
            '#((?:href|src)\s*=\s*["\']|url\()(/)(?!/)#i',
            static fn (array $m): string => $m[1] . $home . $m[2],
            $normalized
        );

        return is_string($rewritten) ? $rewritten : $normalized;
    }

    /**
     * 抽出站内 uploads 的公开路径（以 /uploads/ 开头）；外链/非本站返回 null。
     */
    public function extractUploadsPublicPath(string $pathOrUrl): ?string
    {
        $input = trim(str_replace('\\', '/', $pathOrUrl));
        if ($input === '' || str_contains($input, '..')) {
            return null;
        }

        if (str_starts_with($input, '//')) {
            $input = 'https:' . $input;
        }

        if (preg_match('#^https?://#i', $input) === 1) {
            $parts = parse_url($input);
            if (!is_array($parts)) {
                return null;
            }
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host === '' || !$this->isLocalMediaHost($host)) {
                return null;
            }
            $path = (string) ($parts['path'] ?? '');
            if ($path === '' || !str_contains($path, '/uploads/')) {
                return null;
            }
            $pos = stripos($path, '/uploads/');
            if ($pos === false) {
                return null;
            }
            $path  = substr($path, $pos);
            $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

            return $this->cleanUploadsPath($path . $query);
        }

        if (str_starts_with($input, 'uploads/')) {
            $input = '/' . $input;
        }

        if (!str_contains($input, '/uploads/')) {
            return null;
        }

        $pos = stripos($input, '/uploads/');
        if ($pos === false) {
            return null;
        }

        return $this->cleanUploadsPath(substr($input, $pos));
    }

    /** 本站 / 局域网 / 已登记域名：才允许剥域名收成相对 uploads */
    public function isLocalMediaHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host) === 1) {
            return true;
        }

        $known = [];
        $reqHost = strtolower(trim((string) Request::host()));
        if ($reqHost !== '') {
            $known[$reqHost] = true;
        }

        $siteUrl = trim((string) $this->configService->get('site_url', ''));
        if ($siteUrl !== '') {
            $parts = parse_url(preg_match('#^https?://#i', $siteUrl) ? $siteUrl : 'http://' . $siteUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                $known[strtolower((string) $parts['host'])] = true;
            }
        }

        try {
            $domains = Db::name('site_domains')->where('status', 1)->column('domain');
            if (is_array($domains)) {
                foreach ($domains as $d) {
                    $d = strtolower(trim((string) $d));
                    if ($d !== '') {
                        $known[$d] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // site_domains 未就绪时仅用 site_url / 请求 Host
        }

        return isset($known[$host]);
    }

    /**
     * 每拍按 id 窗口扫描行数（禁止对 LONGTEXT 做 LIKE 全表过滤——6 万+ 文档会单拍 20s+ 被网关掐死）。
     * 命中与否在 PHP 内判断；空改也推进 last_id。
     */
    private const REWRITE_CHUNK = 80;

    /**
     * CLI / 脚本：同步跑完重写（内部走 start+tick，不另开管道）。
     *
     * @return array{mode:string,tables:array<string,int>,total:int,errors:array<string,string>}
     */
    public function rewriteDatabase(): array
    {
        $start = $this->startRewriteJob();
        if (!$start->isOk()) {
            throw new \RuntimeException($start->message() !== '' ? $start->message() : '无法启动重写任务');
        }
        $view   = $start->dataArray();
        $jobId  = (string) ($view['job_id'] ?? '');
        $cursor = (int) ($view['cursor'] ?? 0);
        if ($jobId === '' && (string) ($view['status'] ?? '') === 'finished') {
            return [
                'mode'   => (string) ($view['mode'] ?? $this->mode()),
                'tables' => is_array($view['tables'] ?? null) ? $view['tables'] : [],
                'total'  => (int) ($view['total'] ?? 0),
                'errors' => is_array($view['errors'] ?? null) ? $view['errors'] : [],
            ];
        }
        $guard = 0;
        while ($guard < 100000) {
            $guard++;
            $tick = $this->tickRewriteJob($jobId, $cursor);
            if (!$tick->isOk()) {
                throw new \RuntimeException($tick->message() !== '' ? $tick->message() : '重写失败');
            }
            $view   = $tick->dataArray();
            $status = (string) ($view['status'] ?? '');
            if ($status === 'finished') {
                return [
                    'mode'   => (string) ($view['mode'] ?? $this->mode()),
                    'tables' => is_array($view['tables'] ?? null) ? $view['tables'] : [],
                    'total'  => (int) ($view['total'] ?? 0),
                    'errors' => is_array($view['errors'] ?? null) ? $view['errors'] : [],
                ];
            }
            if ($status === 'failed' || $status === 'cancelled') {
                throw new \RuntimeException((string) ($view['error'] ?? ($tick->message() !== '' ? $tick->message() : '重写失败')));
            }
            $cursor = (int) ($view['cursor'] ?? $cursor);
        }

        throw new \RuntimeException('重写超时：拍数过多，请用后台进度任务续跑');
    }

    /** 启动可进度重写任务 */
    public function startRewriteJob(): ServiceResult
    {
        @ini_set('memory_limit', '512M');

        if ($this->isAbsolute()) {
            $probe = SiteUrl::public('/uploads/__pivark_media_url_probe__');
            if (!preg_match('#^https?://#i', $probe)) {
                return ServiceResult::fail(
                    '绝对地址模式需要先在「网站设置」填写完整「网站网址」（含 http:// 或 https://）。'
                    . '当前无法生成带域名的绝对地址，请保存后再重写。'
                );
            }
        }

        $units = $this->rewriteWorkUnits();
        if ($units === []) {
            return ServiceResult::ok([
                'job_id'      => '',
                'status'      => 'finished',
                'cursor'      => 0,
                'percent'     => 100,
                'mode'        => $this->mode(),
                'total'       => 0,
                'tables'      => [],
                'errors'      => [],
                'current'     => '',
                'status_text' => '无需重写的表字段',
            ], '无需重写');
        }

        // 同表多列会各扫一遍，估算按「字段单位」累加行数（仅进度条用）
        $rowsEstimate = 0;
        foreach ($units as $unit) {
            $t = (string) ($unit['table'] ?? '');
            if ($t === '') {
                continue;
            }
            try {
                $rowsEstimate += (int) Db::name($t)->count();
            } catch (\Throwable) {
                // 估算失败不挡启动
            }
        }

        $jobId = 'mur_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $job   = [
            'job_id'         => $jobId,
            'status'         => 'running',
            'mode'           => $this->mode(),
            'cursor'         => 0,
            'unit_index'     => 0,
            'last_id'        => 0,
            'unit_max_id'    => 0,
            'scanned'        => 0,
            'rows_estimate'  => $rowsEstimate,
            'units'          => $units,
            'tables'         => [],
            'total'          => 0,
            'errors'         => [],
            'error'          => '',
            'current'        => $units[0]['table'] . '.' . $units[0]['column'],
            'created_at'     => AppTime::format('c'),
            'updated_at'     => AppTime::format('c'),
        ];
        $this->writeRewriteJob($job);

        return ServiceResult::ok($this->publicRewriteJobView($job), '已启动重写');
    }

    /** 推进一拍（CAS：expectedCursor 须等于当前 cursor） */
    public function tickRewriteJob(string $jobId, int $expectedCursor): ServiceResult
    {
        $jobId = $this->normalizeRewriteJobId($jobId);
        if ($jobId === '') {
            return ServiceResult::fail('任务 ID 无效');
        }

        $lockPath = $this->rewriteJobPath($jobId) . '.lock';
        LocalFile::mkdirIfMissing(dirname($lockPath));
        $lockFh = fopen($lockPath, 'c+');
        if ($lockFh === false) {
            return ServiceResult::fail('无法锁定重写任务');
        }
        if (!flock($lockFh, LOCK_EX)) {
            fclose($lockFh);

            return ServiceResult::fail('重写任务忙碌，请稍后重试');
        }

        try {
            $job = $this->readRewriteJob($jobId);
            if ($job === null) {
                return ServiceResult::fail('任务不存在或已过期');
            }
            $status = (string) ($job['status'] ?? '');
            if ($status === 'finished') {
                return ServiceResult::ok($this->publicRewriteJobView($job), '已完成');
            }
            if ($status === 'failed') {
                return ServiceResult::fail((string) ($job['error'] ?? '任务已失败'), data: $this->publicRewriteJobView($job));
            }
            if ($status === 'cancelled') {
                return ServiceResult::fail('任务已取消', data: $this->publicRewriteJobView($job));
            }

            $jobCursor = (int) ($job['cursor'] ?? 0);
            if ($expectedCursor < $jobCursor) {
                return ServiceResult::ok($this->publicRewriteJobView($job), '进行中');
            }
            if ($expectedCursor > $jobCursor) {
                return ServiceResult::fail('进度不同步，请刷新后重试', data: $this->publicRewriteJobView($job));
            }

            @set_time_limit(120);
            try {
                $job = $this->advanceRewriteJobOneChunk($job);
            } catch (\Throwable $e) {
                $job['status']     = 'failed';
                $job['error']      = '库表处理失败：' . mb_substr(trim($e->getMessage()), 0, 160);
                $job['updated_at'] = AppTime::format('c');
                $this->writeRewriteJob($job);

                return ServiceResult::fail((string) $job['error'], data: $this->publicRewriteJobView($job));
            }

            $job['cursor']     = $jobCursor + 1;
            $job['updated_at'] = AppTime::format('c');
            $this->writeRewriteJob($job);

            $msg = ((string) ($job['status'] ?? '')) === 'finished' ? '重写完成' : '进行中';

            return ServiceResult::ok($this->publicRewriteJobView($job), $msg);
        } finally {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
            if (is_file($lockPath)) {
                unlink($lockPath);
            }
        }
    }

    public function rewriteJobStatus(string $jobId): ServiceResult
    {
        $jobId = $this->normalizeRewriteJobId($jobId);
        $job   = $jobId !== '' ? $this->readRewriteJob($jobId) : null;
        if ($job === null) {
            return ServiceResult::fail('任务不存在或已过期');
        }

        return ServiceResult::ok($this->publicRewriteJobView($job));
    }

    public function cancelRewriteJob(string $jobId): ServiceResult
    {
        $jobId = $this->normalizeRewriteJobId($jobId);
        $job   = $jobId !== '' ? $this->readRewriteJob($jobId) : null;
        if ($job === null) {
            return ServiceResult::fail('任务不存在或已过期');
        }
        if (in_array((string) ($job['status'] ?? ''), ['finished', 'failed', 'cancelled'], true)) {
            return ServiceResult::ok($this->publicRewriteJobView($job), '任务已结束');
        }
        $job['status']     = 'cancelled';
        $job['error']      = '已取消';
        $job['updated_at'] = AppTime::format('c');
        $this->writeRewriteJob($job);

        return ServiceResult::ok($this->publicRewriteJobView($job), '已取消');
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function advanceRewriteJobOneChunk(array $job): array
    {
        $units = $job['units'] ?? [];
        if (!is_array($units) || $units === []) {
            $job['status']  = 'finished';
            $job['current'] = '';

            return $job;
        }

        $unitIndex = (int) ($job['unit_index'] ?? 0);
        if ($unitIndex >= count($units)) {
            $job['status']  = 'finished';
            $job['current'] = '';

            return $job;
        }

        $unit  = $units[$unitIndex];
        $table = (string) ($unit['table'] ?? '');
        $col   = (string) ($unit['column'] ?? '');
        $job['current'] = $table . '.' . $col;

        if ((int) ($job['unit_max_id'] ?? 0) < 1 || (int) ($job['last_id'] ?? 0) === 0) {
            $job['unit_max_id'] = max(0, (int) Db::name($table)->max('id'));
        }

        $lastId = (int) ($job['last_id'] ?? 0);
        $chunk  = $this->rewriteColumnChunkOnce($table, $col, $lastId, self::REWRITE_CHUNK);
        $job['last_id'] = $chunk['last_id'];
        $job['scanned'] = (int) ($job['scanned'] ?? 0) + (int) $chunk['scanned'];
        $changed = (int) $chunk['changed'];
        if ($changed > 0) {
            $tables = is_array($job['tables'] ?? null) ? $job['tables'] : [];
            $tables[$table] = (int) ($tables[$table] ?? 0) + $changed;
            $job['tables']  = $tables;
            $job['total']   = (int) ($job['total'] ?? 0) + $changed;
        }

        if ($chunk['done']) {
            $job['unit_index']  = $unitIndex + 1;
            $job['last_id']     = 0;
            $job['unit_max_id'] = 0;
            if ($job['unit_index'] >= count($units)) {
                $job['status']  = 'finished';
                $job['current'] = '';
            } else {
                $next = $units[$job['unit_index']];
                $job['current'] = (string) ($next['table'] ?? '') . '.' . (string) ($next['column'] ?? '');
            }
        }

        return $job;
    }

    /**
     * @return array{changed:int,last_id:int,done:bool,scanned:int}
     */
    private function rewriteColumnChunkOnce(string $table, string $col, int $lastId, int $limit): array
    {
        $limit = max(1, min(200, $limit));

        // configs：必须带 key，禁止把 site_url 等「站址真源」收成相对路径
        $fields = $table === 'configs' ? ['id', 'key', $col] : ['id', $col];

        // 只按主键窗口取行：大表 LONGTEXT 上 LIKE 过滤会单拍卡死（实测 6 万文档 ~20s/count）
        $rows = Db::name($table)
            ->where('id', '>', $lastId)
            ->order('id', 'asc')
            ->limit($limit)
            ->field($fields)
            ->select();
        if ($rows === null || (is_countable($rows) && count($rows) === 0)) {
            return ['changed' => 0, 'last_id' => $lastId, 'done' => true, 'scanned' => 0];
        }

        $changed = 0;
        $batch   = 0;
        $maxId   = $lastId;
        foreach ($rows as $row) {
            $arr = is_array($row) ? $row : (array) $row;
            $id  = (int) ($arr['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $maxId = $id;
            $batch++;

            $old = (string) ($arr[$col] ?? '');
            if ($old === '') {
                continue;
            }
            if ($table === 'configs' && !$this->shouldRewriteConfigValue((string) ($arr['key'] ?? ''), $old)) {
                continue;
            }
            $looksMediaOrLink = str_contains($old, 'uploads')
                || str_contains($old, 'http://')
                || str_contains($old, 'https://')
                || preg_match('#(?:href|src)\s*=#i', $old) === 1;
            if (!$looksMediaOrLink) {
                continue;
            }
            $new = in_array($col, ['value', 'content', 'content_mobile'], true)
                ? $this->rewriteText($old)
                : $this->formatForStorage($old);
            if ($new === $old) {
                continue;
            }
            Db::name($table)->where('id', $id)->update([$col => $new]);
            $changed++;
        }

        return [
            'changed' => $changed,
            'last_id' => $maxId,
            'done'    => $batch < $limit,
            'scanned' => $batch,
        ];
    }

    /**
     * configs.value 重写闸：站址/协议真源与非素材纯 URL 禁止改写（相对模式曾把 site_url 收成 /）。
     */
    private function shouldRewriteConfigValue(string $key, string $value): bool
    {
        $key = trim($key);
        if ($key === '' || $key === 'site_url' || $key === 'site_force_https') {
            return false;
        }
        // 只动素材路径或富文本；纯「https://域名/」类配置保留绝对形态
        if (str_contains($value, '/uploads/') || preg_match('#(?:href|src)\s*=#i', $value) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return list<array{table:string,column:string}>
     */
    private function rewriteWorkUnits(): array
    {
        $specs = [
            'documents'           => ['content', 'content_mobile', 'litpic'],
            'tags'                => ['litpic'],
            'media_assets'        => ['url'],
            'site_slides'         => ['image_url'],
            'site_links'          => ['logo_url'],
            'float_contact_items' => ['qrcode'],
            'configs'             => ['value'],
            'site_pages'          => ['content'],
        ];
        $units = [];
        foreach ($specs as $table => $columns) {
            $physical = $this->physicalTableName($table);
            if ($physical === '' || !$this->tableExists($physical) || !$this->columnExists($physical, 'id')) {
                continue;
            }
            foreach ($columns as $col) {
                if ($this->columnExists($physical, $col)) {
                    $units[] = ['table' => $table, 'column' => $col];
                }
            }
        }

        return $units;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function publicRewriteJobView(array $job): array
    {
        $units     = is_array($job['units'] ?? null) ? $job['units'] : [];
        $unitTotal = count($units);
        $unitIndex = (int) ($job['unit_index'] ?? 0);
        $scanned   = (int) ($job['scanned'] ?? 0);
        $estimate  = max(0, (int) ($job['rows_estimate'] ?? 0));
        if ($estimate > 0) {
            $percent = (int) min(99, max(0, (int) floor(($scanned / $estimate) * 100)));
        } else {
            $lastId    = (int) ($job['last_id'] ?? 0);
            $unitMaxId = max(0, (int) ($job['unit_max_id'] ?? 0));
            $within    = ($unitMaxId > 0 && $lastId > 0)
                ? min(0.999, $lastId / $unitMaxId)
                : 0.0;
            $percent = $unitTotal < 1
                ? 100
                : (int) min(99, max(0, (int) floor((($unitIndex + $within) / $unitTotal) * 100)));
        }
        if ((string) ($job['status'] ?? '') === 'finished') {
            $percent = 100;
        }

        $current = (string) ($job['current'] ?? '');
        $runningText = '正在处理 ' . $current
            . '（字段 ' . min($unitIndex + 1, max(1, $unitTotal)) . '/' . $unitTotal
            . '，已扫 ' . $scanned . ($estimate > 0 ? '/' . $estimate : '')
            . ' 行，已更新 ' . (int) ($job['total'] ?? 0) . ' 行）';

        return [
            'job_id'      => (string) ($job['job_id'] ?? ''),
            'status'      => (string) ($job['status'] ?? ''),
            'cursor'      => (int) ($job['cursor'] ?? 0),
            'percent'     => $percent,
            'mode'        => (string) ($job['mode'] ?? ''),
            'total'       => (int) ($job['total'] ?? 0),
            'tables'      => is_array($job['tables'] ?? null) ? $job['tables'] : [],
            'errors'      => is_array($job['errors'] ?? null) ? $job['errors'] : [],
            'error'       => (string) ($job['error'] ?? ''),
            'current'     => $current,
            'unit_index'  => $unitIndex,
            'unit_total'  => $unitTotal,
            'scanned'     => $scanned,
            'status_text' => match ((string) ($job['status'] ?? '')) {
                'finished'  => '重写完成，共更新 ' . (int) ($job['total'] ?? 0) . ' 行',
                'failed'    => (string) ($job['error'] ?? '重写失败'),
                'cancelled' => '已取消',
                default     => $runningText,
            },
        ];
    }

    private function normalizeRewriteJobId(string $jobId): string
    {
        $jobId = trim($jobId);

        return preg_match('/^mur_[a-zA-Z0-9_]+$/', $jobId) === 1 ? $jobId : '';
    }

    private function rewriteJobDir(): string
    {
        $dir = rtrim(ProjectPaths::runtimeDir(), '/\\') . DIRECTORY_SEPARATOR . 'media_url_rewrite_jobs';
        LocalFile::mkdirIfMissing($dir);

        return $dir;
    }

    private function rewriteJobPath(string $jobId): string
    {
        return $this->rewriteJobDir() . DIRECTORY_SEPARATOR . $jobId . '.json';
    }

    /** @return array<string,mixed>|null */
    private function readRewriteJob(string $jobId): ?array
    {
        $path = $this->rewriteJobPath($jobId);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $job */
    private function writeRewriteJob(array $job): void
    {
        $id = $this->normalizeRewriteJobId((string) ($job['job_id'] ?? ''));
        if ($id === '') {
            return;
        }
        $job['job_id'] = $id;
        file_put_contents(
            $this->rewriteJobPath($id),
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private function physicalTableName(string $logical): string
    {
        $logical = trim($logical);
        if ($logical === '' || !preg_match('/^[a-z0-9_]+$/i', $logical)) {
            return '';
        }
        $prefix = (string) (config('database.connections.mysql.prefix') ?? '');

        return $prefix . $logical;
    }

    private function tableExists(string $physical): bool
    {
        $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $physical);
        $rows = Db::query("SHOW TABLES LIKE '" . str_replace("'", "''", $safe) . "'");

        return is_array($rows) && $rows !== [];
    }

    private function columnExists(string $physical, string $column): bool
    {
        $rows = Db::query(
            'SELECT 1 AS ok FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1',
            [$physical, $column]
        );

        return is_array($rows) && $rows !== [];
    }

    private function cleanUploadsPath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        if (!str_starts_with($path, '/uploads/')) {
            return null;
        }

        return $path;
    }
}
