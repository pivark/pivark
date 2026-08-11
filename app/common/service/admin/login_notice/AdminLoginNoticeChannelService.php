<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\admin\login_notice;

use app\common\model\Form;
use app\common\model\User;
use app\common\model\UserRole;
use app\common\service\config\ConfigService;
use app\common\service\mail\MailService;
use app\common\service\sms\SmsService;
use app\common\support\SiteUrl;
use think\facade\Log;

/** 登录提醒 · 邮件/短信外发（弹窗之外的并行通道） */
class AdminLoginNoticeChannelService
{

    private const NOTICE_FORM = 'form.pending_submissions';

    public function __construct(
        private readonly AdminLoginNoticeConfigService $configService,
        private readonly AdminLoginNoticeAccessService $accessService,
        private readonly MailService $mailService,
        private readonly SmsService $smsService,
        private readonly ConfigService $siteConfig,
    ) {
    }

    public function dispatchFormSubmission(int $formId, int $submissionId): void
    {
        if ($formId < 1 || $submissionId < 1) {
            return;
        }
        if (!$this->configService->isChannelEnabled(self::NOTICE_FORM, 'email')
            && !$this->configService->isChannelEnabled(self::NOTICE_FORM, 'sms')) {
            return;
        }

        $form = Form::where('id', $formId)->find();
        if ($form === null) {
            return;
        }

        $meta = config('admin.login_notices.notices.' . self::NOTICE_FORM, []);
        if (!is_array($meta)) {
            $meta = [];
        }

        $title = trim((string) ($form['title'] ?? $form['slug'] ?? '表单'));
        $slug  = trim((string) ($form['slug'] ?? ''));
        $adminUrl = $slug !== ''
            ? SiteUrl::adminSpa('/site/form/submissions/' . $slug . '?pending=1')
            : SiteUrl::adminSpa('/site/form');

        $subject = sprintf('【%s】新表单提交待处理', (string) $this->siteConfig->get('site_name', 'PivArk'));
        $body    = sprintf(
            '<p>表单「%s」收到一条新的未读提交（#%d）。</p><p><a href="%s">打开后台处理</a></p>',
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            $submissionId,
            htmlspecialchars($adminUrl, ENT_QUOTES, 'UTF-8'),
        );

        foreach ($this->recipientsForNotice(self::NOTICE_FORM, $meta) as $user) {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId < 1) {
                continue;
            }
            if ($this->configService->isChannelEnabled(self::NOTICE_FORM, 'email')) {
                $email = trim((string) ($user['email'] ?? ''));
                if ($email !== '') {
                    $result = $this->mailService->send($email, $subject, $body);
                    if (!$result->ok) {
                        Log::warning('admin_login_notice_email_failed', [
                            'notice' => self::NOTICE_FORM,
                            'user_id' => $userId,
                            'msg'     => $result->msg,
                        ]);
                    }
                }
            }
            if ($this->configService->isChannelEnabled(self::NOTICE_FORM, 'sms')) {
                $this->dispatchSms($user, $title, $submissionId);
            }
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @return list<array{id:int,email:string,mobile:string,nickname:string}>
     */
    public function recipientsForNotice(string $noticeId, array $meta): array
    {
        $userIds = UserRole::distinct(true)->column('user_id');
        if ($userIds === []) {
            return [];
        }

        $rows = User::whereIn('id', $userIds)->where('status', 1)->select()->toArray();
        $out  = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId < 1 || !$this->accessService->canReceiveNotice($userId, $noticeId, $meta)) {
                continue;
            }
            $out[] = [
                'id'       => $userId,
                'email'    => (string) ($row['email'] ?? ''),
                'mobile'   => (string) ($row['mobile'] ?? ''),
                'nickname' => (string) ($row['nickname'] ?? $row['username'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $user */
    private function dispatchSms(array $user, string $formTitle, int $submissionId): void
    {
        if (!$this->smsService->isConfigured()) {
            Log::info('admin_login_notice_sms_skipped', [
                'reason'        => 'sms_not_ready',
                'user_id'       => (int) ($user['id'] ?? 0),
                'submission_id' => $submissionId,
            ]);

            return;
        }

        $mobile = trim((string) ($user['mobile'] ?? ''));
        if ($mobile === '') {
            Log::info('admin_login_notice_sms_skipped', [
                'reason'        => 'mobile_empty',
                'user_id'       => (int) ($user['id'] ?? 0),
                'submission_id' => $submissionId,
            ]);

            return;
        }

        $site    = (string) $this->siteConfig->get('site_name', 'PivArk');
        $content = sprintf('【%s】表单「%s」有新提交 #%d，请登录后台处理。', $site, $formTitle, $submissionId);
        $result  = $this->smsService->send($mobile, $content, [
            'notice'        => self::NOTICE_FORM,
            'user_id'       => (int) ($user['id'] ?? 0),
            'submission_id' => $submissionId,
            'form_title'    => $formTitle,
        ]);
        if (!$result->isOk()) {
            Log::warning('admin_login_notice_sms_failed', [
                'notice'        => self::NOTICE_FORM,
                'user_id'       => (int) ($user['id'] ?? 0),
                'submission_id' => $submissionId,
                'msg'           => $result->message(),
            ]);
        }
    }
}
