<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * 自定表单 Facade
 */
declare(strict_types=1);
namespace app\common\service\site;
use app\common\model\FormSubmission;
use app\common\service\site\SiteFormCrudService;
use app\common\service\site\SiteFormRenderService;
use app\common\service\infra\FrontCacheInvalidator;
use app\common\support\ServiceResult;
use app\common\service\template\TemplateEngine;
class SiteFormService
{

    public function __construct(
        private readonly TemplateEngine $templateEngine,
        private readonly FrontCacheInvalidator $frontCacheInvalidator,
        private readonly SiteFormRenderService $siteFormRenderService,
        private readonly SiteFormCrudService $siteFormCrudService,
    ) {
    }

    public const STATUS_UNREAD  = 0;
    public const STATUS_READ    = 1;
    public const STATUS_HANDLED = 2;

    private static bool $booted = false;

    public function boot(): void
    {
        if (!self::$booted) {
            self::$booted = true;
        }
        $this->templateEngine->registerKernelTag('form', static fn (array $attrs, array $pageVars, string $tpl = '') => app(self::class)->renderTag($attrs, $pageVars, $tpl));
        $this->templateEngine->registerKernelTag('form_open', static fn (array $attrs, array $pageVars, string $tpl = '') => app(self::class)->renderFormOpenTag($attrs, $pageVars, $tpl));
        $this->templateEngine->registerKernelTag('form_field', static fn (array $attrs, array $pageVars, string $tpl = '') => app(self::class)->renderFormFieldTag($attrs, $pageVars, $tpl));
        $this->templateEngine->registerKernelTag('form_close', static fn (array $attrs, array $pageVars, string $tpl = '') => app(self::class)->renderFormCloseTag($attrs, $pageVars, $tpl));
    }

    public function renderTag(array $attrs, array $pageVars, string $tpl = ''): string {
        return $this->siteFormRenderService->renderTag($attrs, $pageVars, $tpl);
    }

    public function renderFormOpenTag(array $attrs, array $pageVars, string $tpl = ''): string {
        return $this->siteFormRenderService->renderFormOpenTag($attrs, $pageVars, $tpl);
    }

    public function renderFormFieldTag(array $attrs, array $pageVars, string $tpl = ''): string {
        return $this->siteFormRenderService->renderFormFieldTag($attrs, $pageVars, $tpl);
    }

    public function renderFormCloseTag(array $attrs, array $pageVars, string $tpl = ''): string {
        return $this->siteFormRenderService->renderFormCloseTag($attrs, $pageVars, $tpl);
    }

    public function invokeSnippets(array $form): array {
        return $this->siteFormRenderService->invokeSnippets($form);
    }

    public function findBySlug(string $slug): ?array {
        return $this->siteFormCrudService->findBySlug($slug);
    }

    public function listAdmin(): array {
        return $this->siteFormCrudService->listAdmin();
    }

    public function listAdminPaged(array $params = []): array {
        return $this->siteFormCrudService->listAdminPaged($params);
    }

    public function findIdBySlug(string $slug): int {
        return $this->siteFormCrudService->findIdBySlug($slug);
    }

    public function countPendingForSlug(string $slug): int {
        return $this->siteFormCrudService->countPendingForSlug($slug);
    }

  /**
   * @return array{total:int,forms:list<array{slug:string,title:string,pending:int}>}
   */
    public function pendingLoginNoticeSummary(): array {
        return $this->siteFormCrudService->pendingLoginNoticeSummary();
    }

    public function countSubmissions(int $formId, int $status = -1): int {
        return $this->siteFormCrudService->countSubmissions($formId, $status);
    }

    public function listSubmissionsAdmin(
        int $formId,
        int $page = 1,
        int $limit = 20,
        int $status = -1,
        string $keyword = '',
    ): array {
        return $this->siteFormCrudService->listSubmissionsAdmin($formId, $page, $limit, $status, $keyword);
    }

    public function saveAdmin(array $data): ServiceResult {
        return $this->siteFormCrudService->saveAdmin($data);
    }

    public function deleteFormAdmin(int $id): ServiceResult {
        $res = $this->siteFormCrudService->deleteFormAdmin($id);
        if ($res->isOk()) {
            $this->frontCacheInvalidator->invalidateAll(false);
        }

        return $res;
    }

    public function batchDeleteFormsAdmin(array $ids): ServiceResult {
        $res = $this->siteFormCrudService->batchDeleteFormsAdmin($ids);
        if ($res->isOk()) {
            $this->frontCacheInvalidator->invalidateAll(false);
        }

        return $res;
    }

    public function submitPublic(array $payload, bool $captchaAlreadyVerified = false): ServiceResult {
        return $this->siteFormCrudService->submitPublic($payload, $captchaAlreadyVerified);
    }

    public function listSubmissions(int $formId = 0, int $page = 1, int $limit = 20): array {
        return $this->siteFormCrudService->listSubmissions($formId, $page, $limit);
    }

    public function updateSubmissionStatus(int $id, int $status): ServiceResult {
        return $this->siteFormCrudService->updateSubmissionStatus($id, $status);
    }

    public function batchUpdateSubmissionStatusAdmin(array $ids, int $status): ServiceResult {
        return $this->siteFormCrudService->batchUpdateSubmissionStatusAdmin($ids, $status);
    }

    public function deleteSubmissionAdmin(int $id): ServiceResult {
        return $this->siteFormCrudService->deleteSubmissionAdmin($id);
    }

    public function getSubmissionAdmin(int $id): ?array {
        return $this->siteFormCrudService->getSubmissionAdmin($id);
    }

    public function exportSubmissionsCsvAdmin(
        int $formId,
        int $status = -1,
        string $keyword = '',
        array $ids = [],
    ): array {
        return $this->siteFormCrudService->exportSubmissionsCsvAdmin($formId, $status, $keyword, $ids);
    }

    public function statusText(int $status): string {
        return $this->siteFormCrudService->statusText($status);
    }

    public function isSubmissionRead(int $status): bool {
        return $this->siteFormCrudService->isSubmissionRead($status);
    }

    public function formatSubmissionRow(array|FormSubmission $row, ?array $form = null): array {
        return $this->siteFormCrudService->formatSubmissionRow($row, $form);
    }

    public function exportSubmissionsAsyncStart(
        int $formId,
        int $status = -1,
        string $keyword = '',
        array $ids = [],
    ): ServiceResult {
        return $this->siteFormCrudService->exportSubmissionsAsyncStart($formId, $status, $keyword, $ids);
    }

    public function exportSubmissionsAsyncStep(
        string $jobId,
        int $formId,
        int $status = -1,
        string $keyword = '',
        array $ids = [],
    ): ServiceResult {
        return $this->siteFormCrudService->exportSubmissionsAsyncStep($jobId, $formId, $status, $keyword, $ids);
    }

    public function exportSubmissionsAsyncDownload(string $jobId): ?array {
        return $this->siteFormCrudService->exportSubmissionsAsyncDownload($jobId);
    }
}
