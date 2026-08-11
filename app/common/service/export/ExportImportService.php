<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\export;

use app\common\support\AppTime;

use app\common\support\ServiceResult;

use app\common\service\audit\AuditLogService;
use app\common\service\user\UserPasswordService;
use app\common\service\user\UserService;
use app\common\service\tag\TagService;
use app\common\service\document\DocumentAdminService;
use app\common\model\Document;
use app\common\model\User;
use think\facade\Db;
use think\facade\Log;

/** CSV 导入导出（文章、用户、表单提交等；各业务 Service 组装表头与行后调用 packCsv） */
class ExportImportService
{

    public function __construct(
        private readonly TagService $tagService,
        private readonly UserPasswordService $userPasswordService,
        private readonly UserService $userService,
        private readonly AuditLogService $auditLogService,
        private readonly DocumentAdminService $documentService,
    ) {
    }

    /**
     * 通用表格 CSV 导出（全家桶复用入口）
     *
     * @param list<string>     $headers 表头（通常中文列名）
     * @param list<list<mixed>> $rows    数据行，列顺序与 $headers 一致
     * @return array{filename:string,content:string}
     */
    public function packCsv(string $basename, array $headers, array $rows): array
    {
        $lines = [$this->csvLine($headers)];
        foreach ($rows as $row) {
            $lines[] = $this->csvLine($row);
        }
        $safe = preg_replace('/[^a-z0-9_-]+/i', '_', $basename) ?: 'export';

        return [
            'filename' => $safe . '_' . AppTime::format('Ymd_His') . '.csv',
            'content'  => $this->withBom(implode("\n", $lines)),
        ];
    }

    /**
     * @return array{filename:string,content:string}
     */
    public function exportUsersCsv(): array
    {
        $header = ['id', 'username', 'nickname', 'email', 'mobile', 'status', 'created_at'];
        $lines  = [$this->csvLine($header)];
        User::order('id', 'asc')->chunk(500, function ($rows) use (&$lines): void {
            foreach ($rows as $row) {
                $row = $row->toArray();
                $lines[] = $this->csvLine([
                    $row['id'],
                    $row['username'],
                    $row['nickname'] ?? '',
                    $row['email'] ?? '',
                    $row['mobile'] ?? '',
                    $row['status'] ?? 1,
                    $row['created_at'] ?? '',
                ]);
            }
        });

        return [
            'filename' => 'users_' . AppTime::format('Ymd_His') . '.csv',
            'content'  => $this->withBom(implode("\n", $lines)),
        ];
    }

    /**
     * @return array{filename:string,content:string}
     */
    public function exportArticlesCsv(): array
    {
        $header = ['id', 'title', 'status', 'author_name', 'tags', 'published_at', 'created_at'];
        $lines  = [$this->csvLine($header)];
        Document::whereNull('deleted_at')->order('id', 'asc')->chunk(500, function ($rows) use (&$lines): void {
            foreach ($rows as $row) {
                $row = $row->toArray();
                $lines[] = $this->csvLine([
                    $row['id'],
                    $row['title'],
                    $row['status'] ?? 1,
                    $row['author_name'] ?? '',
                    $this->tagService->tagNamesCsvForDocument((int) $row['id']),
                    $row['published_at'] ?? '',
                    $row['created_at'] ?? '',
                ]);
            }
        });

        return [
            'filename' => 'articles_' . AppTime::format('Ymd_His') . '.csv',
            'content'  => $this->withBom(implode("\n", $lines)),
        ];
    }

    /**
     * @return ServiceResult
     * @param mixed $csvText
     * @param mixed $operatorId
     */
    public function importUsersCsv(string $csvText, int $operatorId = 0): ServiceResult
    {
        $rows = $this->parseCsv($csvText);
        if ($rows === []) {
            return ServiceResult::fail('CSV 为空');
        }

        $errors  = [];
        $pending = [];
        $seen    = [];
        foreach ($rows as $i => $row) {
            $lineNo   = $i + 2;
            $username = trim((string) ($row['username'] ?? ''));
            if ($username === '') {
                $errors[] = '第 ' . $lineNo . ' 行：缺少 username';
                continue;
            }
            if (isset($seen[$username])) {
                $errors[] = '第 ' . $lineNo . ' 行：CSV 内用户名重复 ' . $username;
                continue;
            }
            $seen[$username] = true;
            if (User::where('username', $username)->count() > 0) {
                $errors[] = '第 ' . $lineNo . ' 行：用户名已存在 ' . $username;
                continue;
            }

            $password = trim((string) ($row['password'] ?? ''));
            if ($password === '') {
                $errors[] = '第 ' . $lineNo . ' 行：缺少 password';
                continue;
            }
            $pwdMsg = $this->userPasswordService->validateNewPassword($password, $username);
            if ($pwdMsg !== '') {
                $errors[] = '第 ' . $lineNo . ' 行：' . $pwdMsg;
                continue;
            }

            $pending[] = [
                'line'     => $lineNo,
                'username' => $username,
                'password' => $password,
                'nickname' => (string) ($row['nickname'] ?? $username),
                'email'    => (string) ($row['email'] ?? ''),
                'mobile'   => (string) ($row['mobile'] ?? ''),
                'status'   => (int) ($row['status'] ?? 1),
            ];
        }

        if ($errors !== []) {
            return ServiceResult::fail(
                '导入校验失败，共 ' . count($errors) . ' 处错误，未写入任何用户',
                data: ['errors' => $errors],
            );
        }
        if ($pending === []) {
            return ServiceResult::fail('CSV 无有效数据行');
        }

        $created = 0;
        Db::startTrans();
        try {
            foreach ($pending as $item) {
                $res = $this->userService->create([
                    'username' => $item['username'],
                    'password' => $item['password'],
                    'nickname' => $item['nickname'],
                    'email'    => $item['email'],
                    'mobile'   => $item['mobile'],
                    'status'   => $item['status'],
                ]);
                if (!$res->isOk()) {
                    throw new \RuntimeException('第 ' . $item['line'] . ' 行：' . ($res->message() ?? '创建失败'));
                }
                $created++;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('用户 CSV 导入失败：' . $e->getMessage());

            return ServiceResult::fail('导入失败，已回滚全部写入');
        }

        $this->auditLogService->operate('导入用户 CSV', 'admin.user', ['created' => $created, 'errors' => 0]);

        return ServiceResult::ok(['created' => $created, 'errors' => []], "成功导入 {$created} 条");
    }

    /**
     * @return ServiceResult
     * @param mixed $csvText
     * @param mixed $operatorId
     */
    public function importArticlesCsv(string $csvText, int $operatorId = 0): ServiceResult
    {
        $rows = $this->parseCsv($csvText);
        if ($rows === []) {
            return ServiceResult::fail('CSV 为空');
        }

        $errors  = [];
        $pending = [];
        foreach ($rows as $i => $row) {
            $lineNo = $i + 2;
            $title  = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $errors[] = '第 ' . $lineNo . ' 行：缺少 title';
                continue;
            }
            $pending[] = [
                'line'         => $lineNo,
                'title'        => $title,
                'content'      => (string) ($row['content'] ?? $title),
                'summary'      => (string) ($row['summary'] ?? ''),
                'status'       => (int) ($row['status'] ?? 1),
                'author_name'  => (string) ($row['author_name'] ?? '小编'),
                'tags'         => (string) ($row['tags'] ?? ''),
                'published_at' => (string) ($row['published_at'] ?? AppTime::now()),
            ];
        }

        if ($errors !== []) {
            return ServiceResult::fail(
                '导入校验失败，共 ' . count($errors) . ' 处错误，未写入任何文档',
                data: ['errors' => $errors],
            );
        }
        if ($pending === []) {
            return ServiceResult::fail('CSV 无有效数据行');
        }

        $created = 0;
        Db::startTrans();
        try {
            foreach ($pending as $item) {
                /** @var ServiceResult $res */
                $res = $this->documentService->saveAdmin([
                    'title'        => $item['title'],
                    'content'      => $item['content'],
                    'summary'      => $item['summary'],
                    'status'       => $item['status'],
                    'author_name'  => $item['author_name'],
                    'tags'         => $item['tags'],
                    'published_at' => $item['published_at'],
                ], $operatorId);
                if (!$res->isOk()) {
                    throw new \RuntimeException('第 ' . $item['line'] . ' 行：' . ($res->message() ?? '失败'));
                }
                $created++;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('文档 CSV 导入失败：' . $e->getMessage());

            return ServiceResult::fail('导入失败，已回滚全部写入');
        }

        $this->auditLogService->operate('导入文档 CSV', 'admin.document', ['created' => $created, 'errors' => 0]);

        return ServiceResult::ok(['created' => $created, 'errors' => []], "成功导入 {$created} 条");
    }

    /**
     * @return array{filename:string,content:string}
     */
    public function exportDocumentsJson(): array
    {
        $out = [];
        Document::whereNull('deleted_at')->order('id', 'asc')->chunk(200, function ($rows) use (&$out): void {
            foreach ($rows as $row) {
                $row = $row->toArray();
                $id = (int) ($row['id'] ?? 0);
                $out[] = [
                    'id'           => $id,
                    'title'        => (string) ($row['title'] ?? ''),
                    'subtitle'     => (string) ($row['subtitle'] ?? ''),
                    'summary'      => (string) ($row['summary'] ?? ''),
                    'content'      => (string) ($row['content'] ?? ''),
                    'status'       => (int) ($row['status'] ?? 1),
                    'author_name'  => (string) ($row['author_name'] ?? ''),
                    'tags'         => $this->tagService->tagNamesCsvForDocument($id),
                    'published_at' => (string) ($row['published_at'] ?? ''),
                    'seo_title'    => (string) ($row['seo_title'] ?? ''),
                    'seo_keywords' => (string) ($row['seo_keywords'] ?? ''),
                    'seo_description' => (string) ($row['seo_description'] ?? ''),
                ];
            }
        });

        return [
            'filename' => 'articles_' . AppTime::format('Ymd_His') . '.json',
            'content'  => json_encode(['version' => 1, 'documents' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]',
        ];
    }

    /**
     * @return ServiceResult
     * @param mixed $jsonText
     * @param mixed $operatorId
     */
    public function importDocumentsJson(string $jsonText, int $operatorId = 0): ServiceResult
    {
        $payload = json_decode($jsonText, true);
        if (!is_array($payload)) {
            return ServiceResult::fail('JSON 格式无效');
        }
        $rows = $payload['documents'] ?? $payload;
        if (!is_array($rows) || $rows === []) {
            return ServiceResult::fail('没有可导入的文章');
        }

        $created = 0;
        $updated = 0;
        $errors  = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                $errors[] = '第 ' . ($i + 1) . ' 条：格式无效';
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $errors[] = '第 ' . ($i + 1) . ' 条：缺少 title';
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $payload = [
                'title'           => $title,
                'subtitle'        => (string) ($row['subtitle'] ?? ''),
                'content'         => (string) ($row['content'] ?? $title),
                'summary'         => (string) ($row['summary'] ?? ''),
                'status'          => (int) ($row['status'] ?? 1),
                'author_name'     => (string) ($row['author_name'] ?? '小编'),
                'tags'            => (string) ($row['tags'] ?? ''),
                'published_at'    => (string) ($row['published_at'] ?? AppTime::now()),
                'seo_title'       => (string) ($row['seo_title'] ?? ''),
                'seo_keywords'    => (string) ($row['seo_keywords'] ?? ''),
                'seo_description' => (string) ($row['seo_description'] ?? ''),
            ];
            $isUpdate = false;
            if ($id > 0 && Document::where('id', $id)->whereNull('deleted_at')->find()) {
                $payload['id'] = $id;
                $isUpdate      = true;
            }
            /** @var ServiceResult $res */
            $res = $this->documentService->saveAdmin($payload, $operatorId);
            if ($res->isOk()) {
                if ($isUpdate) {
                    $updated++;
                } else {
                    $created++;
                }
            } else {
                $errors[] = '第 ' . ($i + 1) . ' 条：' . ($res->message() !== '' ? $res->message() : '失败');
            }
        }

        $this->auditLogService->operate('导入文档 JSON', 'admin.document', [
            'created' => $created,
            'updated' => $updated,
            'errors'  => count($errors),
        ]);

        $okCount = $created + $updated;
        $parts   = [];
        if ($created > 0) {
            $parts[] = "新建 {$created} 条";
        }
        if ($updated > 0) {
            $parts[] = "更新 {$updated} 条";
        }
        $summary = $parts !== [] ? implode('，', $parts) : '未导入任何文章';
        $msg     = $summary . (count($errors) ? '，' . count($errors) . ' 条失败' : '');
        $data    = [
            'created' => $created,
            'updated' => $updated,
            'errors'  => $errors,
        ];
        if ($okCount < 1) {
            return ServiceResult::fail($msg, data: $data);
        }

        return ServiceResult::ok($data, $msg);
    }

    /**
     * @return list<array<string, string>>
     * @param mixed $text
     */
    public function parseCsv(string $text): array
    {
        $text = ltrim($text, "\xEF\xBB\xBF");
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        if ($lines === []) {
            return [];
        }
        $header = str_getcsv(array_shift($lines) ?: '');
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        $out    = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $row  = [];
            foreach ($header as $idx => $key) {
                if ($key !== '') {
                    $row[$key] = $cols[$idx] ?? '';
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<mixed> $fields
     * @return mixed
     */
    public function csvLine(array $fields): string
    {
        $escaped = [];
        foreach ($fields as $field) {
            $s = (string) $field;
            if (str_contains($s, ',') || str_contains($s, '"') || str_contains($s, "\n")) {
                $s = '"' . str_replace('"', '""', $s) . '"';
            }
            $escaped[] = $s;
        }

        return implode(',', $escaped);
    }

    /**
     * @return mixed
     * @param mixed $content
     */
    public function withBom(string $content): string
    {
        return "\xEF\xBB\xBF" . $content;
    }
}
