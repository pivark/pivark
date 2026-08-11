<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\service\config\ConfigService;
use app\common\service\infra\BackupService;
use think\facade\Request;
use think\Response;

class Backup extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly BackupService $backup,
        private readonly ConfigService $config,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $list = $this->backup->listBackups();

            return AdminApiResponse::list(['total'          => count($list),
                'list'           => $list,
                'retention_days' => max(7, (int) $this->config->get('backup_retention_days', 30))]);
        }

        return $this->renderView('backup/index', [
            'list' => $this->backup->listBackups(),
        ]);
    }

    public function tables()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $meta = $this->backup->listDatabaseTables();

        return AdminApiResponse::list(['database' => $meta['database'],
            'prefix'   => $meta['prefix'],
            'total'    => $meta['total'],
            'list'     => $meta['list']]);
    }

    public function create()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $typeParam = trim((string) Request::post('type', ''));
        if ($typeParam !== '') {
            $types = [$typeParam];
        } else {
            $types = self::normalizePostStringList(Request::post('types/a', Request::post('types', [])));
        }
        $tables = self::normalizePostStringList(Request::post('tables/a', Request::post('tables', [])));

        return AdminApiResponse::admin($this->backup->create(
            $types !== [] ? $types : ['database'],
            $tables !== [] ? $tables : null,
        ));
    }

    public function startJob()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $type   = trim((string) Request::post('type', 'database'));
        $tables = self::normalizePostStringList(Request::post('tables/a', Request::post('tables', [])));

        return AdminApiResponse::admin($this->backup->startJob(
            $type !== '' ? $type : 'database',
            $tables !== [] ? $tables : null,
        ));
    }

    public function tickJob()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId  = trim((string) Request::post('job_id', Request::post('id', '')));
        $batch  = max(1, (int) Request::post('batch_size', 1));
        $cursor = Request::post('cursor', null);
        $cursor = $cursor === null || $cursor === '' ? null : (int) $cursor;

        return AdminApiResponse::admin($this->backup->tickJob($jobId, $batch, $cursor));
    }

    public function jobStatus()
    {
        if (!Request::isAjax() && !Request::isGet()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId = trim((string) Request::get('job_id', Request::get('id', '')));

        return AdminApiResponse::admin($this->backup->getJob($jobId));
    }

    public function cancelJob()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId = trim((string) Request::post('job_id', Request::post('id', '')));

        return AdminApiResponse::admin($this->backup->cancelJob($jobId));
    }

    public function startRestoreJob()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $name = trim((string) Request::post('name', ''));

        return AdminApiResponse::admin($this->backup->startRestoreJob($name));
    }

    public function download()
    {
        $name = trim((string) Request::get('name', ''));
        $path = $this->backup->resolveFile($name);
        if ($path === null) {
            return AdminApiResponse::fail('文件不存在');
        }

        return Response::create($path, 'file', 200)
            ->name(basename($path))
            ->mimeType(self::mimeTypeForBackup(basename($path)));
    }

    public function restore()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $name = trim((string) Request::post('name', ''));

        if (str_starts_with(basename($name), 'uploads_')) {
            return AdminApiResponse::admin($this->backup->restoreUploads($name));
        }

        return AdminApiResponse::admin($this->backup->restoreDatabase($name));
    }

    private static function mimeTypeForBackup(string $name): string
    {
        return str_ends_with(strtolower($name), '.zip')
            ? 'application/zip'
            : 'application/sql';
    }

    public function delete()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $name = trim((string) Request::post('name', ''));

        return AdminApiResponse::admin($this->backup->delete($name));
    }

    /**
     * @return list<string>
     */
    private static function normalizePostStringList(mixed $value): array
    {
        if (!is_array($value)) {
            $text = trim((string) $value);

            return $text !== '' ? [$text] : [];
        }

        $out = [];
        $walk = static function (mixed $item) use (&$out, &$walk): void {
            if (is_array($item)) {
                foreach ($item as $child) {
                    $walk($child);
                }

                return;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $out[] = $text;
            }
        };
        foreach ($value as $item) {
            $walk($item);
        }

        return array_values(array_unique($out));
    }
}
