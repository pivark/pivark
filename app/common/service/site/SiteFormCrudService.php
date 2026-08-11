<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 * Split — 表单 CRUD / 提交 / 导出
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\AppTime;
use app\common\support\QueryLimit;

use app\common\support\ServiceResult;

use app\common\service\admin\login_notice\AdminLoginNoticeChannelService;
use app\common\model\Form;
use app\common\model\FormSubmission;
use app\common\service\auth\CaptchaService;
use app\common\service\export\ExportImportService;
use app\common\service\infra\AdminAsyncExportService;
use think\facade\Request;

class SiteFormCrudService
{

    public function __construct(
        private readonly CaptchaService $captcha,
        private readonly ExportImportService $export,
        private readonly AdminAsyncExportService $asyncExport,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $row = Form::where('slug', $slug)->where('status', 1)->find();
        if (!$row) {
            return null;
        }

        return $this->formatRow($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdmin(): array
    {
        return $this->listAdminPaged([])['list'];
    }

    /**
     * @param array<string, mixed> $params page, limit, keyword, id, slug
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listAdminPaged(array $params = []): array
    {
        $page    = max(1, (int) ($params['page'] ?? 1));
        $limit   = min(max((int) ($params['limit'] ?? 20), 1), 100);
        $keyword = trim((string) ($params['keyword'] ?? $params['q'] ?? ''));
        $id      = (int) ($params['id'] ?? $params['form_id'] ?? 0);
        $slug    = trim((string) ($params['slug'] ?? ''));

        $query = Form::order('sort', 'asc')->order('id', 'desc');
        if ($id > 0) {
            $query->where('id', $id);
        } elseif ($slug !== '') {
            $query->where('slug', $slug);
        }
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            if (ctype_digit($keyword)) {
                $id = (int) $keyword;
                $query->where(function ($sub) use ($like, $id): void {
                    $sub->where('id', $id)->whereOr('title|slug', 'like', $like);
                });
            } else {
                $query->whereLike('title|slug', $like);
            }
        }

        $total = (int) $query->count();
        $rows  = $query->page($page, $limit)->select()->toArray();
        $formIds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows,
        ), static fn (int $id): bool => $id > 0));
        $counts = $this->batchSubmissionCounts($formIds);
        $out   = [];
        foreach ($rows as $row) {
            $formatted = $this->formatRow($row);
            $formId    = (int) $formatted['id'];
            $formatted['submission_count'] = $counts['total'][$formId] ?? 0;
            $formatted['pending_count']    = $counts['pending'][$formId] ?? 0;
            $out[] = $formatted;
        }

        return ['list' => $out, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public function findIdBySlug(string $slug): int
    {
        $form = $this->findBySlug($slug);

        return $form !== null ? (int) ($form['id'] ?? 0) : 0;
    }

    public function countPendingForSlug(string $slug): int
    {
        $id = $this->findIdBySlug($slug);

        return $id > 0 ? $this->countSubmissions($id, SiteFormService::STATUS_UNREAD) : 0;
    }

    /**
     * 登录提醒：汇总全部启用表单的未读提交
     *
     * @return array{total:int,forms:list<array{slug:string,title:string,pending:int}>}
     */
    public function pendingLoginNoticeSummary(): array
    {
        $rows = Form::where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if ($rows === []) {
            return ['total' => 0, 'forms' => []];
        }

        $formIds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows,
        ), static fn (int $id): bool => $id > 0));
        $counts  = $this->batchSubmissionCounts($formIds);
        $forms   = [];
        $total   = 0;
        foreach ($rows as $row) {
            $formId  = (int) ($row['id'] ?? 0);
            $pending = (int) ($counts['pending'][$formId] ?? 0);
            if ($pending < 1) {
                continue;
            }
            $total += $pending;
            $forms[] = [
                'slug'    => (string) ($row['slug'] ?? ''),
                'title'   => (string) ($row['title'] ?? ''),
                'pending' => $pending,
            ];
        }

        return ['total' => $total, 'forms' => $forms];
    }

    public function countSubmissions(int $formId, int $status = -1): int
    {
        if ($formId < 1) {
            return 0;
        }

        return (int) $this->buildSubmissionAdminQuery($formId, $status)->count();
    }

    /**
     * @param  list<int>  $formIds
     * @return array{total: array<int, int>, pending: array<int, int>}
     */
    private function batchSubmissionCounts(array $formIds): array
    {
        $formIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $formIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($formIds === []) {
            return ['total' => [], 'pending' => []];
        }

        $total = [];
        foreach (FormSubmission::whereIn('form_id', $formIds)
            ->field('form_id, COUNT(*) AS cnt')
            ->group('form_id')
            ->select()
            ->toArray() as $row) {
            $total[(int) ($row['form_id'] ?? 0)] = (int) ($row['cnt'] ?? 0);
        }

        $pending = [];
        foreach (FormSubmission::whereIn('form_id', $formIds)
            ->where('status', SiteFormService::STATUS_UNREAD)
            ->field('form_id, COUNT(*) AS cnt')
            ->group('form_id')
            ->select()
            ->toArray() as $row) {
            $pending[(int) ($row['form_id'] ?? 0)] = (int) ($row['cnt'] ?? 0);
        }

        return ['total' => $total, 'pending' => $pending];
    }

    /**
     * @return \think\db\Query
     */
    private function buildSubmissionAdminQuery(
        int $formId,
        int $status = -1,
        string $keyword = '',
    ) {
        $query = FormSubmission::where('form_id', $formId);
        $this->applySubmissionStatusFilter($query, $status);
        $this->applySubmissionKeywordFilter($query, $keyword);

        return $query;
    }

    private function applySubmissionStatusFilter($query, int $status): void
    {
        if ($status === 0) {
            $query->where('status', SiteFormService::STATUS_UNREAD);
        } elseif ($status === 1) {
            $query->whereIn('status', [SiteFormService::STATUS_READ, SiteFormService::STATUS_HANDLED]);
        }
    }

    private function applySubmissionKeywordFilter($query, string $keyword): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return;
        }
        if (ctype_digit($keyword)) {
            $query->where('id', (int) $keyword);

            return;
        }
        $like = '%' . addcslashes($keyword, '%_\\') . '%';
        $query->whereLike('payload_json', $like);
    }

    /**
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int,form?:array<string,mixed>}
     */
    public function listSubmissionsAdmin(
        int $formId,
        int $page = 1,
        int $limit = 20,
        int $status = -1,
        string $keyword = '',
    ): array {
        if ($formId < 1) {
            return ['list' => [], 'total' => 0, 'page' => 1, 'limit' => $limit];
        }
        $form = Form::where('id', $formId)->find();
        if (!$form) {
            return ['list' => [], 'total' => 0, 'page' => 1, 'limit' => $limit];
        }
        $formattedForm = $this->formatRow($form);
        $page          = max(1, $page);
        $limit         = min(max($limit, 1), 100);
        $query         = $this->buildSubmissionAdminQuery($formId, $status, $keyword)->order('id', 'desc');
        $total = (int) $query->count();
        $rows  = $query->page($page, $limit)->select()->toArray();
        $list  = [];
        foreach ($rows as $row) {
            $list[] = $this->formatSubmissionRow($row, $formattedForm);
        }

        return [
            'list' => $list,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'form'  => $formattedForm,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id    = (int) ($data['id'] ?? 0);
        $slug  = trim((string) ($data['slug'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        if ($slug === '' || $title === '') {
            return ServiceResult::fail('标识与名称不能为空');
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $slug)) {
            return ServiceResult::fail('标识须小写字母开头');
        }
        $dupSlug = Form::where('slug', $slug);
        if ($id > 0) {
            $dupSlug->where('id', '<>', $id);
        }
        if ($dupSlug->find()) {
            return ServiceResult::fail('标识 slug 已被其他表单使用');
        }
        $fields   = isset($data['fields']) && is_array($data['fields']) ? $data['fields'] : [];
        $seenKeys = [];
        foreach ($fields as $idx => $field) {
            if (!is_array($field)) {
                continue;
            }
            $fkey = trim((string) ($field['key'] ?? ''));
            if ($fkey === '') {
                return ServiceResult::fail('第 ' . ((int) $idx + 1) . ' 个字段缺少 key');
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $fkey)) {
                return ServiceResult::fail('字段 key 须小写字母开头：' . $fkey);
            }
            if (isset($seenKeys[$fkey])) {
                return ServiceResult::fail('字段 key 在本表单内重复：' . $fkey);
            }
            $seenKeys[$fkey] = true;
        }
        $settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : [];
        $payload  = [
            'slug'          => $slug,
            'title'         => mb_substr($title, 0, 200),
            'fields_json'   => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'status'        => !empty($data['status']) ? 1 : 0,
            'sort'          => (int) ($data['sort'] ?? 0),
            'updated_at'    => AppTime::now(),
        ];
        if ($id > 0) {
            Form::where('id', $id)->update($payload);

            return ServiceResult::ok(['id' => $id], '保存成功');
        }
        $payload['created_at'] = AppTime::now();
        $newId = (int) Form::insertGetId($payload);

        return ServiceResult::ok(['id' => $newId], '创建成功');
    }

    /**
     * @return ServiceResult
     */
    public function deleteFormAdmin(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数错误');
        }
        $row = Form::where('id', $id)->find();
        if (!$row) {
            return ServiceResult::fail('表单不存在');
        }
        if ((string) ($row['slug'] ?? '') === 'contact') {
            return ServiceResult::fail('默认联系表单不可删除，请在编辑页改为停用');
        }
        $subCount = (int) FormSubmission::where('form_id', $id)->count();
        FormSubmission::where('form_id', $id)->delete();
        Form::where('id', $id)->delete();
        $msg = '删除成功';
        if ($subCount > 0) {
            $msg .= "（含 {$subCount} 条提交记录）";
        }

        return ServiceResult::ok(null, $msg);
    }

    /**
     * @param array<int, mixed> $ids
     * @return ServiceResult
     */
    public function batchDeleteFormsAdmin(array $ids): ServiceResult
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ServiceResult::fail('请选择表单');
        }
        $deleted = 0;
        $skipped = 0;
        $subs    = 0;
        foreach ($ids as $id) {
            $row = Form::where('id', $id)->find();
            if (!$row) {
                continue;
            }
            if ((string) ($row['slug'] ?? '') === 'contact') {
                $skipped++;
                continue;
            }
            $subs += (int) FormSubmission::where('form_id', $id)->count();
            FormSubmission::where('form_id', $id)->delete();
            Form::where('id', $id)->delete();
            $deleted++;
        }
        if ($deleted < 1) {
            return ServiceResult::fail($skipped > 0 ? '默认联系表单不可删除' : '未删除任何表单');
        }
        $msg = "已删除 {$deleted} 个表单";
        if ($subs > 0) {
            $msg .= "（含 {$subs} 条提交记录）";
        }
        if ($skipped > 0) {
            $msg .= "，跳过 {$skipped} 个系统默认表单";
        }

        return ServiceResult::ok(['count' => $deleted, 'skipped' => $skipped], $msg);
    }

    /**
     * @param array<string, mixed> $payload
     * @return ServiceResult
     */
    public function submitPublic(array $payload, bool $captchaAlreadyVerified = false): ServiceResult
    {
        $formId = (int) ($payload['form_id'] ?? 0);
        $form   = Form::where('id', $formId)->where('status', 1)->find();
        if (!$form) {
            return ServiceResult::fail('表单不存在或已停用');
        }
        $settings = json_decode((string) ($form['settings_json'] ?? '{}'), true);
        if (!is_array($settings)) {
            $settings = [];
        }
        if (!$captchaAlreadyVerified) {
            $captchaCode = trim((string) ($payload['captcha'] ?? ''));
            $captcha     = $this->captcha->forScene('contact');
            if (!empty($settings['captcha']) && $captcha->isEnabled() && !$captcha->verify($captchaCode)) {
                return ServiceResult::fail('验证码错误或已过期');
            }
        }

        $fields = json_decode((string) ($form['fields_json'] ?? '[]'), true);
        if (!is_array($fields)) {
            $fields = [];
        }
        $data = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $raw = $payload[$key] ?? $payload[$key . '[]'] ?? '';
            if (is_array($raw)) {
                $parts = [];
                foreach ($raw as $item) {
                    $s = trim((string) $item);
                    if ($s !== '') {
                        $parts[] = $s;
                    }
                }
                $val = implode(', ', $parts);
            } else {
                $val = trim((string) $raw);
            }
            if (!empty($field['required']) && $val === '') {
                return ServiceResult::fail('请填写' . ($field['label'] ?? $key));
            }
            $data[$key] = mb_substr($val, 0, 2000);
        }

        FormSubmission::insert([
            'form_id'      => $formId,
            'payload_json' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ip_hash'      => hash('sha256', (string) Request::ip()),
            'status'       => SiteFormService::STATUS_UNREAD,
            'created_at'   => AppTime::now(),
        ]);
        $submissionId = (int) FormSubmission::getLastInsID();
        if ($submissionId > 0) {
            try {
                app(AdminLoginNoticeChannelService::class)->dispatchFormSubmission($formId, $submissionId);
            } catch (\Throwable $e) {
                \think\facade\Log::warning('form_submission_notice_dispatch_failed', [
                    'form_id' => $formId,
                    'msg'     => $e->getMessage(),
                ]);
            }
        }

        return ServiceResult::ok(null, (string) ($settings['success_msg'] ?? '提交成功'));
    }

    /**
     * @return array{list:list<array<string,mixed>>,total:int}
     */
    public function listSubmissions(int $formId = 0, int $page = 1, int $limit = 20): array
    {
        $query = FormSubmission::order('id', 'desc');
        if ($formId > 0) {
            $query->where('form_id', $formId);
        }
        $total = (int) $query->count();
        $rows  = $query->page($page, $limit)->select()->toArray();
        $list  = [];
        foreach ($rows as $row) {
            $list[] = [
                'id'         => (int) ($row['id'] ?? 0),
                'form_id'    => (int) ($row['form_id'] ?? 0),
                'payload'    => json_decode((string) ($row['payload_json'] ?? '{}'), true) ?: [],
                'status'     => (int) ($row['status'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return ['list' => $list, 'total' => $total];
    }

    /**
     * @return ServiceResult
     */
    public function updateSubmissionStatus(int $id, int $status): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数错误');
        }
        if (!in_array($status, [SiteFormService::STATUS_UNREAD, SiteFormService::STATUS_READ, SiteFormService::STATUS_HANDLED], true)) {
            return ServiceResult::fail('状态无效');
        }
        if (!FormSubmission::where('id', $id)->find()) {
            return ServiceResult::fail('提交记录不存在');
        }
        FormSubmission::where('id', $id)->update(['status' => $status]);

        return ServiceResult::ok(['status' => $status], '状态已更新');
    }

    /**
     * @param array<int, mixed> $ids
     * @return ServiceResult
     */
    public function batchUpdateSubmissionStatusAdmin(array $ids, int $status): ServiceResult
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ServiceResult::fail('请选择记录');
        }
        if (!in_array($status, [SiteFormService::STATUS_UNREAD, SiteFormService::STATUS_READ, SiteFormService::STATUS_HANDLED], true)) {
            return ServiceResult::fail('状态无效');
        }
        $count = (int) FormSubmission::whereIn('id', $ids)->update(['status' => $status]);

        return ServiceResult::ok(['count' => $count], "已更新 {$count} 条");
    }

    /**
     * @return ServiceResult
     */
    public function deleteSubmissionAdmin(int $id): ServiceResult
    {
        if ($id < 1) {
            return ServiceResult::fail('参数错误');
        }
        FormSubmission::where('id', $id)->delete();

        return ServiceResult::ok(null, '删除成功');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSubmissionAdmin(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = FormSubmission::where('id', $id)->find();
        if (!$row) {
            return null;
        }
        $formId = (int) ($row['form_id'] ?? 0);
        $form   = $formId > 0 ? Form::where('id', $formId)->find() : null;

        return $this->formatSubmissionRow($row, $form instanceof Form ? $this->formatRow($form) : null);
    }

    /**
     * @return array{filename:string,content:string}
     */
    /**
     * @param list<int> $ids
     * @return array{filename:string,content:string}
     */
    public function exportSubmissionsCsvAdmin(
        int $formId,
        int $status = -1,
        string $keyword = '',
        array $ids = [],
    ): array {
        if ($formId < 1) {
            return $this->export->packCsv('form_submissions', ['提示'], [['form_id 无效']]);
        }
        $formRow = Form::where('id', $formId)->find();
        if (!$formRow) {
            return $this->export->packCsv('form_submissions', ['提示'], [['表单不存在']]);
        }
        $form      = $this->formatRow($formRow);
        $fieldCols = [];
        foreach ($form['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '' || ($field['type'] ?? '') === 'hidden') {
                continue;
            }
            $fieldCols[] = [
                'key'   => $key,
                'label' => (string) ($field['label'] ?? $key),
            ];
        }
        $query = $this->buildSubmissionAdminQuery($formId, $status, $keyword)->order('id', 'desc');
        $ids   = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $rows = $query->limit(QueryLimit::FORM_EXPORT_MAX)->select()->toArray();

        $headers = ['ID', '状态', '提交时间'];
        foreach ($fieldCols as $col) {
            $headers[] = $col['label'];
        }
        $csvRows = [];
        foreach ($rows as $row) {
            $fmt   = $this->formatSubmissionRow($row, $form);
            $line  = [
                (int) ($fmt['id'] ?? 0),
                (string) ($fmt['status_text'] ?? ''),
                (string) ($fmt['created_at'] ?? ''),
            ];
            foreach ($fieldCols as $col) {
                $line[] = (string) ($fmt[$col['key']] ?? '');
            }
            $csvRows[] = $line;
        }
        $basename = 'form_' . preg_replace('/[^a-z0-9_-]+/', '_', (string) ($form['slug'] ?? 'submissions'));

        return $this->export->packCsv($basename . '_submissions', $headers, $csvRows);
    }

    public function statusText(int $status): string
    {
        return $status === SiteFormService::STATUS_UNREAD ? '未读' : '已读';
    }

    /** 列表/操作仅区分未读与已读 */
    public function isSubmissionRead(int $status): bool
    {
        return $status !== SiteFormService::STATUS_UNREAD;
    }

    /**
     * @param array<string, mixed>|FormSubmission $row
     * @param array<string, mixed>|null $form
     * @return array<string, mixed>
     */
    public function formatSubmissionRow(array|FormSubmission $row, ?array $form = null): array
    {
        if ($row instanceof FormSubmission) {
            $row = $row->toArray();
        }
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $status = (int) ($row['status'] ?? 0);
        $out    = [
            'id'          => (int) ($row['id'] ?? 0),
            'form_id'     => (int) ($row['form_id'] ?? 0),
            'payload'     => $payload,
            'status'      => $status,
            'status_text' => $this->statusText($status),
            'created_at'  => (string) ($row['created_at'] ?? ''),
            'summary'     => '',
        ];
        $parts = [];
        if ($form !== null && is_array($form['fields'] ?? null)) {
            foreach ($form['fields'] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = (string) ($field['key'] ?? '');
                if ($key === '' || !array_key_exists($key, $payload)) {
                    continue;
                }
                $label = (string) ($field['label'] ?? $key);
                $val   = trim((string) $payload[$key]);
                if ($val === '') {
                    continue;
                }
                $out[$key] = $val;
                if (in_array($field['type'] ?? '', ['textarea', 'text'], true) && strlen($val) > 40) {
                    $parts[] = mb_substr($val, 0, 40) . '…';
                } else {
                    $parts[] = $val;
                }
            }
        } else {
            foreach ($payload as $k => $v) {
                if (is_string($k) && is_scalar($v)) {
                    $out[$k] = (string) $v;
                    $parts[] = (string) $v;
                }
            }
        }
        $out['summary'] = $parts !== [] ? implode(' · ', array_slice($parts, 0, 3)) : '—';

        return $out;
    }

    /**
     * @param array<string, mixed>|Form $row
     * @return array<string, mixed>
     */
    private function formatRow(array|Form $row): array
    {
        if ($row instanceof Form) {
            $row = $row->toArray();
        }
        $formatted = [
            'id'       => (int) ($row['id'] ?? 0),
            'slug'     => (string) ($row['slug'] ?? ''),
            'title'    => (string) ($row['title'] ?? ''),
            'fields'   => json_decode((string) ($row['fields_json'] ?? '[]'), true) ?: [],
            'settings' => json_decode((string) ($row['settings_json'] ?? '{}'), true) ?: [],
            'status'   => (int) ($row['status'] ?? 0),
            'sort'     => (int) ($row['sort'] ?? 0),
        ];
        $formatted['invoke'] = app(SiteFormRenderService::class)->invokeSnippets($formatted);

        return $formatted;
    }

    /**
     * 大表 CSV 异步导出（>500 行建议）
     *
     * @param list<int> $ids
     * @return ServiceResult
     */
    public function exportSubmissionsAsyncStart(
        int $formId,
        int $status = -1,
        string $keyword = '',
        array $ids = [],
    ): ServiceResult {
        if ($formId < 1) {
            return ServiceResult::fail('请指定表单');
        }
        $total = $this->countSubmissions($formId, $status);
        if ($ids !== []) {
            $total = count($ids);
        } elseif ($keyword !== '') {
            $total = (int) $this->buildSubmissionAdminQuery($formId, $status, $keyword)->count();
        }
        if ($total < 1) {
            return ServiceResult::fail('没有可导出的记录');
        }
        if ($total <= 500) {
            return ServiceResult::ok(['sync' => true, 'total' => $total], 'sync');
        }

        $headers = $this->exportSubmissionHeaders($formId);
        if ($headers === null) {
            return ServiceResult::fail('表单不存在');
        }

        return $this->asyncExport->start(
            'form_submissions',
            'form_' . $formId,
            $headers,
            function (int $page, int $limit) use ($formId, $status, $keyword, $ids): array {
                $query = $this->buildSubmissionAdminQuery($formId, $status, $keyword)->order('id', 'desc');
                if ($ids !== []) {
                    $query->whereIn('id', $ids);
                }
                $total = (int) $query->count();
                $rows  = $query->page($page, $limit)->select()->toArray();
                $formModel = Form::where('id', $formId)->find();
                $form      = $formModel ? $this->formatRow($formModel) : ['fields' => []];
                $csvRows = [];
                foreach ($rows as $row) {
                    $csvRows[] = $this->exportSubmissionCsvLine($row, $form);
                }
                $done = $page * $limit >= $total || $rows === [];

                return ['rows' => $csvRows, 'done' => $done, 'total' => $total];
            }
        );
    }

    /**
     * @param list<int> $ids
     * @return ServiceResult
     */
    public function exportSubmissionsAsyncStep(
        string $jobId,
        int $formId,
        int $status = -1,
        string $keyword = '',
        array $ids = [],
    ): ServiceResult {
        return $this->asyncExport->step($jobId, function (int $page, int $limit) use ($formId, $status, $keyword, $ids): array {
            $query = $this->buildSubmissionAdminQuery($formId, $status, $keyword)->order('id', 'desc');
            if ($ids !== []) {
                $query->whereIn('id', $ids);
            }
            $total = (int) $query->count();
            $rows  = $query->page($page, $limit)->select()->toArray();
            $formModel = Form::where('id', $formId)->find();
            $form      = $formModel ? $this->formatRow($formModel) : ['fields' => []];
            $csvRows = [];
            foreach ($rows as $row) {
                $csvRows[] = $this->exportSubmissionCsvLine($row, $form);
            }

            return [
                'rows'  => $csvRows,
                'done'  => $page * $limit >= $total || $rows === [],
                'total' => $total,
            ];
        });
    }

    /**
     * @return array{filename:string,content:string}|null
     */
    public function exportSubmissionsAsyncDownload(string $jobId): ?array
    {
        return $this->asyncExport->consumeDownload($jobId);
    }

    /** @return list<string>|null */
    private function exportSubmissionHeaders(int $formId): ?array
    {
        $formRow = Form::where('id', $formId)->find();
        if (!$formRow) {
            return null;
        }
        $form      = $this->formatRow($formRow);
        $fieldCols = [];
        foreach ($form['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '' || ($field['type'] ?? '') === 'hidden') {
                continue;
            }
            $fieldCols[] = (string) ($field['label'] ?? $key);
        }
        $headers = ['ID', '状态', '提交时间'];
        foreach ($fieldCols as $label) {
            $headers[] = $label;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, mixed> $form
     * @return list<mixed>
     */
    private function exportSubmissionCsvLine(array $row, array $form): array
    {
        $fmt   = $this->formatSubmissionRow($row, $form);
        $line  = [
            (int) ($fmt['id'] ?? 0),
            (string) ($fmt['status_text'] ?? ''),
            (string) ($fmt['created_at'] ?? ''),
        ];
        foreach ($form['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '' || ($field['type'] ?? '') === 'hidden') {
                continue;
            }
            $line[] = (string) ($fmt[$key] ?? '');
        }

        return $line;
    }

}
