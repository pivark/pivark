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
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\release\CoreUpdateRemoteService;
use app\common\service\release\CoreUpdateApplyService;
use app\common\service\release\ReleaseFilesFingerprintService;
use think\facade\Request;
use think\Response;

class Upgrade extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly CoreUpdateApplyService $coreUpdateApply,
        private readonly CoreUpdateRemoteService $coreUpdateRemote,
        private readonly ReleaseFilesFingerprintService $releaseFilesFingerprint,
    ) {
        parent::__construct($csrf);
    }

    /** GET /admin/system/upgrade/meta */
    public function meta()
    {
        $coreUpdate = $this->coreUpdateApply->meta();
        $compatibility = is_array($coreUpdate['compatibility'] ?? null)
            ? $coreUpdate['compatibility']
            : [];

        return AdminApiResponse::fromResult(ServiceResult::ok([
                'version'         => 'v' . (string) ($coreUpdate['current'] ?? ''),
                'php_version'     => PHP_VERSION,
                'core_update'     => $coreUpdate,
                'compatibility'   => $compatibility,
                'ladder'          => is_array($coreUpdate['ladder'] ?? null) ? $coreUpdate['ladder'] : [],
                'user_panel'      => $this->coreUpdateApply->userPanel($coreUpdate),
            ]));
    }

    /** GET upgrade/check — 检查核心版本更新（原 Spa::coreUpdateCheck） */
    public function check(): Response
    {
        $refresh = in_array(strtolower((string) Request::get('refresh', '')), ['1', 'true', 'yes'], true);

        return AdminApiResponse::admin(ServiceResult::ok($this->coreUpdateRemote->check($refresh)));
    }

    /**
     * GET upgrade/files-compare — 本站内核文件 vs 官方发行指纹
     * 真源：官方货源 /static/release/fingerprints/{version}.json（非 Gitee）
     */
    public function filesCompare(): Response
    {
        $refresh = in_array(strtolower((string) Request::get('refresh', '')), ['1', 'true', 'yes'], true);

        return AdminApiResponse::admin($this->releaseFilesFingerprint->compareToOfficial($refresh));
    }

    /**
     * POST upgrade/files-compare/apply — 勾选修复：delete_extra / restore_missing / replace_modified
     */
    public function filesCompareApply(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $op = trim((string) Request::post('op', ''));
        $paths = Request::post('paths', []);
        if (is_string($paths)) {
            $decoded = json_decode($paths, true);
            $paths = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($paths)) {
            $paths = [];
        }

        return AdminApiResponse::admin(
            $this->releaseFilesFingerprint->applyCompareActions($op, $paths),
        );
    }

    /**
     * GET upgrade/impact-preview — 升前预览：本站 vs 目标版指纹（将增/改/删）
     */
    public function impactPreview(): Response
    {
        $refresh = in_array(strtolower((string) Request::get('refresh', '')), ['1', 'true', 'yes'], true);
        $target = trim((string) Request::get('target', ''));

        return AdminApiResponse::admin(
            $this->releaseFilesFingerprint->previewUpgradeImpact($target, $refresh),
        );
    }

    /** POST /admin/system/upgrade/core-apply — 一次性下载并应用（可指定 target_version 单档） */
    public function coreApply()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $refresh = in_array(strtolower(trim((string) Request::post('refresh_manifest', '1'))), ['1', 'true', 'yes'], true);
        $target  = trim((string) Request::post('target_version', ''));

        return AdminApiResponse::admin($this->coreUpdateApply->apply($refresh, $target));
    }

    /** POST /admin/system/upgrade/core-ladder-apply — 按 releases 阶梯自动连升 */
    public function coreLadderApply()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $refresh = in_array(strtolower(trim((string) Request::post('refresh_manifest', '1'))), ['1', 'true', 'yes'], true);

        return AdminApiResponse::admin($this->coreUpdateApply->applyLadder($refresh));
    }

    /** GET /admin/system/upgrade/core-step/status */
    public function coreStepStatus()
    {
        return AdminApiResponse::admin($this->coreUpdateApply->stepStatus());
    }

    /** POST /admin/system/upgrade/core-step/prepare */
    public function coreStepPrepare()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $refresh = in_array(strtolower(trim((string) Request::post('refresh_manifest', '1'))), ['1', 'true', 'yes'], true);
        $target  = trim((string) Request::post('target_version', ''));

        return AdminApiResponse::admin($this->coreUpdateApply->stepPrepare($refresh, $target));
    }

    /** POST /admin/system/upgrade/core-step/download */
    public function coreStepDownload()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId = trim((string) Request::post('job_id', ''));

        return AdminApiResponse::admin($this->coreUpdateApply->stepDownload($jobId));
    }

    /** POST /admin/system/upgrade/core-step/backup */
    public function coreStepBackup()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId = trim((string) Request::post('job_id', ''));

        return AdminApiResponse::admin($this->coreUpdateApply->stepBackup($jobId));
    }

    /** POST /admin/system/upgrade/core-step/apply */
    public function coreStepApply()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId = trim((string) Request::post('job_id', ''));

        return AdminApiResponse::admin($this->coreUpdateApply->stepApply($jobId));
    }

    /** POST /admin/system/upgrade/core-step/migrate */
    public function coreStepMigrate()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId = trim((string) Request::post('job_id', ''));

        return AdminApiResponse::admin($this->coreUpdateApply->stepMigrate($jobId));
    }

    /** POST /admin/system/upgrade/core-step/finalize */
    public function coreStepFinalize()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId = trim((string) Request::post('job_id', ''));

        return AdminApiResponse::admin($this->coreUpdateApply->stepFinalize($jobId));
    }

    /** POST /admin/system/upgrade/core-step/abort */
    public function coreStepAbort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId = trim((string) Request::post('job_id', ''));

        return AdminApiResponse::admin($this->coreUpdateApply->stepAbort($jobId));
    }

    /** POST /admin/system/upgrade/core-restore — 从 core_backups 手动回滚 */
    public function coreRestore()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $backup = trim((string) Request::post('backup', ''));

        return AdminApiResponse::admin($this->coreUpdateApply->restoreBackup($backup));
    }

    /** POST — 授权码激活（REST · 原 Spa::licenseActivate） */
    public function licenseActivate(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        /** @var \app\common\service\admin\AdminSpaLicenseService $license */
        $license = \app\common\support\AppService::make(\app\common\service\admin\AdminSpaLicenseService::class);

        return AdminApiResponse::admin($license->spaActivate(
            (string) Request::post('license_code', '')
        ));
    }

    /** POST — 从授权平台同步（REST · 原 Spa::licenseSync） */
    public function licenseSync(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        /** @var \app\common\service\admin\AdminSpaLicenseService $license */
        $license = \app\common\support\AppService::make(\app\common\service\admin\AdminSpaLicenseService::class);

        return AdminApiResponse::admin($license->spaSync());
    }
}
