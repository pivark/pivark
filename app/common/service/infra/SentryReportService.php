<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;


use Throwable;

final class SentryReportService
{

    private const CLIENT = 'pivark-php/1.0';

    public function __construct(
        private readonly CurlTlsService $curlTls,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->parseDsn() !== null;
    }

    /**
     * @return array{scheme:string,host:string,project_id:string,public_key:string}|null
     */
    public function parseDsn(?string $dsn = null): ?array
    {
        $raw = trim($dsn ?? (string) env('SENTRY_DSN', ''));
        if ($raw === '') {
            return null;
        }

        $parts = parse_url($raw);
        if (!is_array($parts) || ($parts['host'] ?? '') === '' || ($parts['user'] ?? '') === '') {
            return null;
        }

        $projectId = trim((string) ($parts['path'] ?? ''), '/');
        if ($projectId === '' || !ctype_digit($projectId)) {
            return null;
        }

        return [
            'scheme'     => (string) ($parts['scheme'] ?? 'https'),
            'host'       => (string) $parts['host'],
            'project_id' => $projectId,
            'public_key' => (string) $parts['user'],
        ];
    }

    public function captureMessage(string $message, string $level = 'info'): bool
    {
        return $this->sendEvent([
            'level'   => $this->normalizeLevel($level),
            'message' => ['formatted' => $message],
        ]);
    }

    public function captureException(Throwable $exception): bool
    {
        return $this->sendEvent([
            'level'     => 'error',
            'message'   => ['formatted' => $exception->getMessage()],
            'exception' => [
                'values' => [[
                    'type'       => get_class($exception),
                    'value'      => $exception->getMessage(),
                    'stacktrace' => ['frames' => $this->framesFromThrowable($exception)],
                ]],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendEvent(array $payload): bool
    {
        $dsn = $this->parseDsn();
        if ($dsn === null) {
            return false;
        }

        $eventId = bin2hex(random_bytes(16));
        $event   = array_merge([
            'event_id'    => $eventId,
            'timestamp'   => gmdate('Y-m-d\TH:i:s'),
            'platform'    => 'php',
            'logger'      => 'pivark',
            'environment' => trim((string) env('PIVARK_ENV', 'dev')),
            'release'     => trim((string) env('SENTRY_RELEASE', '')),
        ], $payload);
        if ($event['release'] === '') {
            unset($event['release']);
        }

        $url  = sprintf(
            '%s://%s/api/%s/store/',
            $dsn['scheme'],
            $dsn['host'],
            $dsn['project_id']
        );
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return false;
        }

        $auth = sprintf(
            'Sentry sentry_version=7, sentry_client=%s, sentry_key=%s',
            self::CLIENT,
            $dsn['public_key']
        );

        $res = $this->postJson($url, $body, [
            'Content-Type: application/json',
            'X-Sentry-Auth: ' . $auth,
        ]);

        return $res['errno'] === 0 && $res['http_code'] >= 200 && $res['http_code'] < 300;
    }

    /**
     * @param  list<string>  $headers
     * @return array{http_code: int, errno: int}
     */
    private function postJson(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return ['http_code' => 0, 'errno' => -1];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['http_code' => 0, 'errno' => -1];
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $this->curlTls->applyToCurl($ch);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 0) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        curl_close($ch);

        return ['http_code' => $code, 'errno' => $errno];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function framesFromThrowable(Throwable $exception): array
    {
        $frames = [];
        foreach ($exception->getTrace() as $frame) {
            $frames[] = [
                'filename' => (string) ($frame['file'] ?? '[internal]'),
                'lineno'   => (int) ($frame['line'] ?? 0),
                'function' => (string) ($frame['function'] ?? ''),
            ];
        }
        $frames[] = [
            'filename' => $exception->getFile(),
            'lineno'   => $exception->getLine(),
            'function' => '?',
        ];

        return array_reverse($frames);
    }

    private function normalizeLevel(string $level): string
    {
        $level = strtolower(trim($level));

        return in_array($level, ['fatal', 'error', 'warning', 'info', 'debug'], true) ? $level : 'info';
    }
}
