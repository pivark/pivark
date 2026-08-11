<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

use app\common\support\ServiceResult;
use app\common\support\SimpleHttpClient;
use app\common\service\ai\AiProviderCatalog;

use app\common\service\config\AiConfigService;

/** 统一 OpenAI 兼容 Chat Completions（按 AiConfigService 当前服务商） */
class LlmChatService
{

    public function __construct(
        private readonly AiConfigService $aiConfigService,
        private readonly AiProviderCatalog $aiProviderCatalog,
    ) {
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @return ServiceResult
     */
    public function chat(array $messages, ?string $model = null, float $temperature = 0.3, ?string $provider = null): ServiceResult
    {
        $provider = $provider ?? $this->aiConfigService->provider();
        $apiKey   = $this->aiConfigService->providerApiKey($provider);
        if ($apiKey === '') {
            $name = $this->aiProviderCatalog->get($provider)['name'];

            return ServiceResult::fail("未配置 {$name} API Key");
        }

        $payload = [
            'model'       => $model ?? $this->aiConfigService->providerModel($provider),
            'messages'    => $messages,
            'temperature' => $temperature,
        ];

        $base = rtrim($this->aiConfigService->providerBaseUrl($provider), '/');
        $url  = $base . '/chat/completions';
        $raw  = $this->postJson($url, $payload, $apiKey);
        if ($raw === null) {
            return ServiceResult::fail($this->aiProviderCatalog->get($provider)['name'] . ' 请求失败');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ServiceResult::fail('响应解析失败');
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            return ServiceResult::fail((string) ($decoded['error']['message'] ?? 'API 错误'));
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            return ServiceResult::fail('返回空内容');
        }

        return ServiceResult::ok(['content' => $content, 'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : []], 'ok');
    }

    /**
     * @return ServiceResult
     */
    public function generateDocumentMeta(string $plainText, string $hintTitle = '', ?string $provider = null): ServiceResult
    {
        $plainText = trim($plainText);
        if ($plainText === '') {
            return ServiceResult::fail('正文为空');
        }

        if (mb_strlen($plainText) > 12000) {
            $plainText = mb_substr($plainText, 0, 12000) . '…';
        }

        $hint = $hintTitle !== '' ? "参考标题：{$hintTitle}\n\n" : '';

        $res = $this->chat([
            [
                'role'    => 'system',
                'content' => '你是 CMS 内容助手。根据用户提供的正文，输出 JSON 对象，字段：title, summary, seo_title, seo_keywords, seo_description, tags。'
                    . 'tags 为字符串数组，3～8 个中文标签，不要带「链接」「提取码」等噪音。'
                    . '只输出 JSON，不要 markdown 代码块。',
            ],
            [
                'role'    => 'user',
                'content' => $hint . "正文：\n" . $plainText,
            ],
        ], null, 0.3, $provider);

        if (!$res->isOk()) {
            return ServiceResult::fail((string) ($res->message() ?? '生成失败'));
        }

        $jsonText = trim((string) ($res['content'] ?? ''));
        $jsonText = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $jsonText) ?? $jsonText;
        $data = json_decode($jsonText, true);
        if (!is_array($data)) {
            return ServiceResult::fail('AI 返回非 JSON');
        }

        return ServiceResult::ok($data, 'ok');
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload, string $apiKey): ?string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return null;
        }

        $res = SimpleHttpClient::request($url, [
            'method'     => 'POST',
            'body'       => $body,
            'timeout'    => 120,
            'headers'    => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            'verify_ssl' => !$this->curlSslInsecure(),
        ]);

        if ($res['errno'] !== 0 || $res['http_code'] < 200 || $res['http_code'] >= 300) {
            return $res['body'] !== '' ? $res['body'] : null;
        }

        return $res['body'];
    }

    private function curlSslInsecure(): bool
    {
        $flag = trim((string) env('AI_CURL_SSL_INSECURE', ''));
        if ($flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var(getenv('APP_DEBUG') ?: '', FILTER_VALIDATE_BOOLEAN);
    }
}
