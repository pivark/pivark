<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\model\MemberEnterpriseProfile;
use app\common\support\HtmlSanitizer;
use app\common\support\ServiceResult;

/** 会员企业资料读写 */
final class MemberEnterpriseProfileService
{
    /**
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $row = MemberEnterpriseProfile::where('user_id', $userId)->find();

        return $row ? $row->toArray() : null;
    }

    /**
     * 注册/资料提交校验并规范化企业字段。
     *
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function validatePayload(array $data, bool $requireCore = true): ServiceResult
    {
        $company = HtmlSanitizer::cleanPlainText((string) ($data['company_name'] ?? ''), 200);
        $contact = HtmlSanitizer::cleanPlainText((string) ($data['contact_name'] ?? ''), 100);
        $phone   = HtmlSanitizer::cleanPlainText((string) ($data['contact_phone'] ?? ''), 32);
        $usci    = HtmlSanitizer::cleanPlainText((string) ($data['usci'] ?? $data['credit_code'] ?? ''), 32);
        $job     = HtmlSanitizer::cleanPlainText((string) ($data['job_title'] ?? ''), 100);
        $email   = trim((string) ($data['company_email'] ?? ''));

        if ($requireCore) {
            if ($company === '') {
                return ServiceResult::fail('请填写企业名称');
            }
            if ($contact === '') {
                return ServiceResult::fail('请填写联系人');
            }
            if ($phone === '') {
                return ServiceResult::fail('请填写联系电话');
            }
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ServiceResult::fail('企业邮箱格式不正确');
        }

        return ServiceResult::ok([
            'company_name'  => $company,
            'contact_name'  => $contact,
            'contact_phone' => $phone,
            'usci'          => $usci,
            'job_title'     => $job,
            'company_email' => $email,
        ]);
    }

    /**
     * @param array<string, mixed> $payload 须已经过 validatePayload
     */
    public function upsert(int $userId, array $payload): void
    {
        if ($userId < 1) {
            return;
        }
        $existing = MemberEnterpriseProfile::where('user_id', $userId)->find();
        $row = [
            'company_name'  => (string) ($payload['company_name'] ?? ''),
            'contact_name'  => (string) ($payload['contact_name'] ?? ''),
            'contact_phone' => (string) ($payload['contact_phone'] ?? ''),
            'usci'          => (string) ($payload['usci'] ?? ''),
            'job_title'     => (string) ($payload['job_title'] ?? ''),
            'company_email' => (string) ($payload['company_email'] ?? ''),
        ];
        if ($existing) {
            MemberEnterpriseProfile::where('user_id', $userId)->update($row);
        } else {
            $row['user_id'] = $userId;
            MemberEnterpriseProfile::create($row);
        }
    }

    public function deleteByUserId(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        MemberEnterpriseProfile::where('user_id', $userId)->delete();
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array<string, mixed>>
     */
    public function mapByUserIds(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0));
        if ($userIds === []) {
            return [];
        }
        $out = [];
        foreach (MemberEnterpriseProfile::whereIn('user_id', $userIds)->select() as $row) {
            $arr = $row->toArray();
            $uid = (int) ($arr['user_id'] ?? 0);
            if ($uid > 0) {
                $out[$uid] = $arr;
            }
        }

        return $out;
    }
}
