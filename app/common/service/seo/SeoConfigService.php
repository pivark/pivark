<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\seo;

use app\common\support\ServiceResult;

use app\common\service\audit\AuditLogService;
use app\common\service\config\ConfigService;
use app\common\service\site\SiteUrlModeService;
use app\common\service\seo\SeoStaticConfigService;
use app\common\service\static\StaticRemotePublishConfigService;
use app\common\model\Config;

/** SEO 模块配置（URL 规则、标题模板等） */
class SeoConfigService
{

    public function __construct(
        private readonly SiteUrlModeService $siteUrlModeService,
        private readonly SeoStaticConfigService $seoStaticConfigService,
        private readonly StaticRemotePublishConfigService $staticRemotePublishConfigService,
        private readonly ConfigService $configService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /** @return list<string> */
    public function urlKeys(): array
    {
        return $this->siteUrlModeService->configKeys();
    }

    /** @return list<string> */
    public function titleKeys(): array
    {
        return [
            'seo_title_separator',
            'seo_tag_title_rule',
            'seo_document_title_rule',
        ];
    }

    /** @return list<string> */
    public function allKeys(): array
    {
        return array_merge(
            $this->urlKeys(),
            $this->titleKeys(),
            $this->seoStaticConfigService->configKeys(),
            $this->staticRemotePublishConfigService->configKeys(),
        );
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->allKeys() as $key) {
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }

        return $out;
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'seo_title_separator' => ' - ',
            'seo_tag_title_rule' => 'name_page_site',
            'seo_document_title_rule' => 'title_site',
            default => $this->staticRemotePublishConfigService->defaultFor($key),
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveUrlAdmin(array $data): ServiceResult
    {
        $this->seoStaticConfigService->mergeIntoSavePayload($data);
        $this->staticRemotePublishConfigService->mergeIntoSavePayload($data);
        $payload = [];
        foreach ($this->allKeys() as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key];
            }
        }
        if ($payload === []) {
            return ServiceResult::fail('无有效配置项');
        }

        if (isset($payload['seo_title_separator'])) {
            $payload['seo_title_separator'] = mb_substr(trim((string) $payload['seo_title_separator']), 0, 10);
        }
        foreach (['seo_tag_title_rule', 'seo_document_title_rule'] as $ruleKey) {
            if (!isset($payload[$ruleKey])) {
                continue;
            }
            $payload[$ruleKey] = $this->normalizeRule($ruleKey, (string) $payload[$ruleKey]);
        }

        $this->configService->save($payload);
        $this->auditLogService->operate('保存 SEO URL 配置', 'admin.seo.url', []);

        return ServiceResult::ok(null, '配置已保存');
    }

    public function normalizeRule(string $key, string $rule): string
    {
        $allowed = $key === 'seo_tag_title_rule'
            ? ['name', 'name_site', 'name_page', 'name_page_site']
            : ['title', 'title_site', 'title_tag_site'];

        return in_array($rule, $allowed, true) ? $rule : $this->defaultFor($key);
    }

    /** @return array<string, string> */
    public function tagTitleRuleLabels(): array
    {
        return [
            'name'           => '栏目名称',
            'name_site'      => '栏目名称_网站名称',
            'name_page'      => '栏目名称_第N页',
            'name_page_site' => '栏目名称_第N页_网站名称',
        ];
    }

    /** @return array<string, string> */
    public function documentTitleRuleLabels(): array
    {
        return [
            'title'          => '内容标题',
            'title_site'     => '内容标题_网站名称',
            'title_tag_site' => '内容标题_栏目名称_网站名称',
        ];
    }
}
