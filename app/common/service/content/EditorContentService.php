<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * 元舟 PivArk — 文章正文后处理（远程图本地化、外链清理，跟随系统配置）
     * @return mixed
     * @param mixed $content
     * @param mixed $editorMode
 */
declare(strict_types=1);

namespace app\common\service\content;


use app\common\support\AppTime;use app\common\service\content\ContentEditorService;

use think\facade\Request;



use app\common\service\upload\UploadService;
use app\common\service\config\ConfigService;
use app\common\support\OpsLog;
use app\common\exception\UploadException;
use app\common\support\SiteUrl;

class EditorContentService
{

    public function __construct(
        private readonly ContentEditorService $contentEditorService,
        private readonly ConfigService $configService,
        private readonly EditorSpecialCharsService $specialChars,
    ) {
    }

    private const REMOTE_FETCH_TIMEOUT = 8;
    private const REMOTE_MAX_BYTES     = 5_242_880; // 5MB

    /** 最近一次 processForSave 的远程图统计（供后台保存提示） */
    private static array $lastProcessReport = [
        'images_attempted'  => 0,
        'images_localized'  => 0,
        'images_kept_remote'=> 0,
    ];

    /**
     * 保存前处理正文（HTML 或 Markdown 源码）
     */
    public function resetProcessReport(): void
    {
        self::$lastProcessReport = [
            'images_attempted'   => 0,
            'images_localized'   => 0,
            'images_kept_remote' => 0,
        ];
    }

    public function processForSave(string $content, string $editorMode = ''): string
    {
        if ($content === '') {
            return '';
        }

        $isMarkdown = $this->contentEditorService->isMarkdown($editorMode !== '' ? $editorMode : $this->contentEditorService->current());
        if (!$isMarkdown) {
            $content = $this->localizeDataUriImages($content);
        }
        if ($this->remoteLocalEnabled()) {
            $content = $isMarkdown
                ? $this->localizeMarkdownImages($content)
                : $this->localizeHtmlImages($content);
        }
        if ($this->clearExternalEnabled()) {
            $content = $isMarkdown
                ? $this->sanitizeMarkdownLinks($content)
                : $this->sanitizeHtmlLinks($content);
        }

        // 未开启「特殊字符」时剥离 emoji 等四字节字符，避免 utf8 库写入失败
        return $this->specialChars->filterForSave($content);
    }

    public function specialCharsEnabled(): bool
    {
        return $this->specialChars->enabled();
    }

    /**
     * @return array{images_attempted:int,images_localized:int,images_kept_remote:int}
     */
    public function lastProcessReport(): array
    {
        return self::$lastProcessReport;
    }

    /**
     * @return mixed
     */
    public function remoteLocalEnabled(): bool
    {
        return (string) $this->configService->get('editor_remote_local', '1') === '1';
    }

    /**
     * @return mixed
     */
    public function clearExternalEnabled(): bool
    {
        return (string) $this->configService->get('editor_clear_external', '1') === '1';
    }

    /**
     * @return mixed
     * @param mixed $html
     */
    public function localizeHtmlImages(string $html): string
    {
        $html = (string) preg_replace_callback(
            '/<img\b[^>]*\b(?:src|data-src)\s*=\s*("|\')((?:[^"\']|&[^;]+;)+)\1/i',
            function (array $m): string {
                return $this->replaceImgSrcInTag($m[0], $m[1], $m[2]);
            },
            $html
        );

        return $html;
    }

    /** docx/剪贴板内嵌 base64 图 → uploads，避免 cleanArticle 剥离 data: 后正文变空 */
    public function localizeDataUriImages(string $html): string
    {
        if ($html === '' || !str_contains($html, 'data:image')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<img\b[^>]*\bsrc\s*=\s*("|\')(data:image\/[^"\']+)\1/i',
            function (array $m): string {
                $quote = $m[1];
                $dataUri = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $local = $this->localizeDataUri($dataUri);
                if ($local === null || $local === '') {
                    return preg_replace('/\s+src\s*=\s*("|\')[^"\']*\1/i', '', $m[0]) ?? $m[0];
                }
                self::$lastProcessReport['images_attempted']++;
                self::$lastProcessReport['images_localized']++;

                return str_replace($quote . $m[2] . $quote, $quote . $local . $quote, $m[0]);
            },
            $html
        );
    }

    private function localizeDataUri(string $dataUri): ?string
    {
        if (!preg_match('#^data:image/([a-z0-9+.-]+);base64,(.+)$#i', $dataUri, $m)) {
            return null;
        }
        $ext = strtolower($m[1]);
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!in_array($ext, ['jpg', 'png', 'gif', 'webp', 'bmp', 'svg+xml', 'svg'], true)) {
            $ext = 'png';
        }
        if (str_contains($ext, 'svg')) {
            $ext = 'svg';
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false || $binary === '') {
            return null;
        }
        if (strlen($binary) > self::REMOTE_MAX_BYTES) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pv_dataimg_');
        if ($tmp === false) {
            return null;
        }
        $path = $tmp . '.' . $ext;
        $this->unlinkTempFile($tmp);
        if (file_put_contents($path, $binary) === false) {
            $this->unlinkTempFile($path);

            return null;
        }

        try {
            $result = UploadService::scene('general')->storeFromLocalFile($path, 'clipboard-' . AppTime::format('YmdHis') . '.' . $ext);
            $this->unlinkTempFile($path);

            return (string) ($result['url'] ?? null);
        } catch (\Throwable $e) {
            $this->unlinkTempFile($path);
            OpsLog::businessWarning('editor_content_clipboard_upload_failed', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    private function replaceImgSrcInTag(string $tag, string $quote, string $rawSrc): string
    {
        $src = trim(html_entity_decode($rawSrc, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '' || !$this->isRemoteHttpUrl($src) || $this->isLocalAssetUrl($src)) {
            return $tag;
        }
        self::$lastProcessReport['images_attempted']++;
        $local = $this->localizeRemoteUrl($src);
        if ($local === null || $local === $src) {
            self::$lastProcessReport['images_kept_remote']++;

            return $tag;
        }
        self::$lastProcessReport['images_localized']++;

        return str_replace($quote . $rawSrc . $quote, $quote . $local . $quote, $tag);
    }

    /**
     * @return mixed
     * @param mixed $md
     */
    public function localizeMarkdownImages(string $md): string
    {
        return (string) preg_replace_callback(
            '/!\[([^\]]*)\]\((https?:\/\/[^)\s]+)\)/i',
            function (array $m): string {
                self::$lastProcessReport['images_attempted']++;
                $local = $this->localizeRemoteUrl($m[2]);
                if ($local === null || $local === $m[2]) {
                    self::$lastProcessReport['images_kept_remote']++;

                    return $m[0];
                }
                self::$lastProcessReport['images_localized']++;

                return '![' . $m[1] . '](' . $local . ')';
            },
            $md
        );
    }

    /**
     * @return mixed
     * @param mixed $html
     */
    public function sanitizeHtmlLinks(string $html): string
    {
        return (string) preg_replace_callback(
            '/<a\b([^>]*)\bhref\s*=\s*("|\')([^"\']+)\2/i',
            function (array $m): string {
                $href = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($this->isAllowedLink($href)) {
                    return $m[0];
                }
                $attrs = preg_replace('/\s+href\s*=\s*("|\')[^"\']*\1/i', '', $m[1]) ?? $m[1];
                return '<a' . $attrs . '>';
            },
            $html
        );
    }

    /**
     * @return mixed
     * @param mixed $md
     */
    public function sanitizeMarkdownLinks(string $md): string
    {
        return (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/i',
            function (array $m): string {
                if ($this->isAllowedLink($m[2])) {
                    return $m[0];
                }
                return $m[1];
            },
            $md
        );
    }

    /**
     * 下载远程图片并落盘（uploads + 基本设置中的目录/命名规则）；失败则保留原 URL
     * @return mixed
     * @param mixed $url
     */
    public function localizeRemoteUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || !$this->isRemoteHttpUrl($url) || $this->isLocalAssetUrl($url)) {
            return null;
        }

        $tmp = $this->downloadRemoteToTemp($url);
        if ($tmp === null) {
            return null;
        }

        try {
            $name   = $this->remoteImageFilename($url, $tmp);
            $result = UploadService::scene('general')->storeFromLocalFile($tmp, $name);
            $this->unlinkTempFile($tmp);

            return (string) ($result['url'] ?? null);
        } catch (UploadException) {
            $this->unlinkTempFile($tmp);
            return null;
        } catch (\Throwable $e) {
            $this->unlinkTempFile($tmp);
            OpsLog::businessWarning('editor_content_remote_image_localize_failed', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return mixed
     * @param mixed $url
     */
    public function isAllowedLink(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return true;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return true;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        foreach ($this->siteHosts() as $siteHost) {
            if ($host === $siteHost || str_ends_with($host, '.' . $siteHost)) {
                return true;
            }
        }
        foreach ($this->externalWhitelistHosts() as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return mixed
     * @param mixed $url
     */
    public function isRemoteHttpUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }

    /**
     * @return mixed
     * @param mixed $url
     */
    public function isLocalAssetUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return true;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        foreach ($this->siteHosts() as $siteHost) {
            if ($host === $siteHost || str_ends_with($host, '.' . $siteHost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function siteHosts(): array
    {
        $hosts = [];
        $siteUrl = trim((string) $this->configService->get('site_url', ''));
        if ($siteUrl !== '') {
            $h = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
            if ($h !== '') {
                $hosts[] = $h;
            }
        }
        if (Request::host() !== '') {
            $hosts[] = strtolower((string) Request::host());
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * @return list<string>
     */
    public function externalWhitelistHosts(): array
    {
        $raw = (string) $this->configService->get('external_domain_whitelist', '');
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line === '' || str_contains($line, '://')) {
                continue;
            }
            $line = (string) preg_replace('#^www\.#', '', $line);
            $out[] = $line;
        }

        return array_values(array_unique($out));
    }

    private function downloadRemoteToTemp(string $url): ?string
    {
        $data = $this->fetchRemoteBytes($url);
        if ($data === null || $data === '') {
            return null;
        }
        if (strlen($data) > self::REMOTE_MAX_BYTES) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pv_img_');
        if ($tmp === false) {
            return null;
        }
        if (file_put_contents($tmp, $data, LOCK_EX) === false) {
            $this->unlinkTempFile($tmp);
            return null;
        }

        $mime = $this->detectImageMime($tmp);
        if ($mime === null || !str_starts_with($mime, 'image/')) {
            $this->unlinkTempFile($tmp);
            return null;
        }

        return $tmp;
    }

    private function fetchRemoteBytes(string $url): ?string
    {
        $data = $this->fetchRemoteBytesViaStream($url);
        if ($data !== null && $data !== '') {
            return $data;
        }

        return $this->fetchRemoteBytesViaCurl($url);
    }

    private function fetchRemoteBytesViaStream(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => self::REMOTE_FETCH_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'user_agent'      => $this->remoteFetchUserAgent(),
                'header'          => $this->remoteFetchHeaders($url),
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        try {
            $data = file_get_contents($url, false, $ctx);
        } catch (\Throwable) {
            return null;
        }

        return ($data === false || $data === '') ? null : $data;
    }

    private function fetchRemoteBytesViaCurl(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => self::REMOTE_FETCH_TIMEOUT,
            CURLOPT_TIMEOUT        => self::REMOTE_FETCH_TIMEOUT,
            CURLOPT_USERAGENT      => $this->remoteFetchUserAgent(),
            CURLOPT_HTTPHEADER     => $this->remoteFetchHeaderLines($url),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data === false || $code >= 400) {
            return null;
        }

        return $data === '' ? null : (string) $data;
    }

    private function remoteFetchUserAgent(): string
    {
        return 'Mozilla/5.0 (compatible; PivArkEditor/1.0; +' . trim((string) $this->configService->get('site_url', 'pivark.com')) . ')';
    }

    private function remoteFetchHeaders(string $url): string
    {
        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host   = (string) ($parts['host'] ?? '');

        return $host !== ''
            ? 'Referer: ' . $scheme . '://' . $host . "/\r\nAccept: image/*,*/*;q=0.8\r\n"
            : "Accept: image/*,*/*;q=0.8\r\n";
    }

    /**
     * @return list<string>
     */
    private function remoteFetchHeaderLines(string $url): array
    {
        $raw = $this->remoteFetchHeaders($url);
        $lines = preg_split("/\r\n|\n|\r/", trim($raw)) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    private function remoteImageFilename(string $url, string $tmpPath): string
    {
        $path     = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $base     = basename($path) ?: 'remote.jpg';
        $base     = (string) preg_replace('/[?#].*$/', '', $base);
        $ext      = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif'];
        if ($ext !== '' && in_array($ext, $allowed, true)) {
            return $base;
        }
        $mime = $this->detectImageMime($tmpPath);
        $fromMime = $this->extensionFromMime($mime);

        return 'remote.' . ($fromMime ?: 'jpg');
    }

    private function extensionFromMime(?string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp'  => 'bmp',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            'image/avif' => 'avif',
            default      => null,
        };
    }

    private function detectImageMime(string $path): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        $info = getimagesize($path);
        if (is_array($info) && !empty($info['mime'])) {
            return (string) $info['mime'];
        }

        return null;
    }

    /** 前台展示：Tag 名称自动内链 */
    public function tagAutolinkEnabled(): bool
    {
        return (string) $this->configService->get('editor_tag_autolink', '1') === '1';
    }

    /** 前台展示：正文分页（手动分页符 / 按字数自动分页） */
    public function contentPaginationEnabled(): bool
    {
        return (string) $this->configService->get('editor_content_pagination', '1') === '1';
    }

    /** manual=仅分页符；both=有分页符用之，否则按字数；auto=仅按字数 */
    public function contentPaginationMode(): string
    {
        $mode = strtolower(trim((string) $this->configService->get('editor_content_pagination_mode', 'both')));
        if (!in_array($mode, ['manual', 'both', 'auto'], true)) {
            return 'both';
        }

        return $mode;
    }

    public function contentPaginationCharLimit(): int
    {
        $n = (int) $this->configService->get('editor_content_pagination_chars', 2500);

        return max(500, min(20000, $n > 0 ? $n : 2500));
    }

    /**
     * @param list<array<string, mixed>> $tags 文档关联标签（formatForApi）
     */
    public function processForDisplay(string $html, array $tags = []): string
    {
        if ($html === '' || $tags === [] || !$this->tagAutolinkEnabled()) {
            return $html;
        }

        return $this->autolinkTagsInHtml($html, $tags);
    }

    /**
     * @param list<array<string, mixed>> $tags
     */
    public function autolinkTagsInHtml(string $html, array $tags): string
    {
        if ($html === '' || $tags === []) {
            return $html;
        }

        usort($tags, static function (array $a, array $b): int {
            return mb_strlen((string) ($b['name'] ?? '')) <=> mb_strlen((string) ($a['name'] ?? ''));
        });

        return (string) preg_replace_callback(
            '/(?<=>)([^<]+)(?=<|$)/u',
            static function (array $m) use ($tags): string {
                $chunk = $m[1];
                if ($chunk === '' || str_contains($chunk, 'pv-tag-link')) {
                    return $chunk;
                }
                foreach ($tags as $tag) {
                    $name = trim((string) ($tag['name'] ?? ''));
                    if ($name === '' || !str_contains($chunk, $name)) {
                        continue;
                    }
                    $href = htmlspecialchars(SiteUrl::tagFromRow($tag), ENT_QUOTES, 'UTF-8');
                    $label = htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8');
                    $chunk = (string) preg_replace(
                        '/' . preg_quote($name, '/') . '/u',
                        '<a href="' . $href . '" class="pv-tag-link" rel="tag">' . $label . '</a>',
                        $chunk
                    );
                }

                return $chunk;
            },
            $html
        );
    }

    /** @return list<string> */
    private function pagebreakPatterns(): array
    {
        return [
            '#<!--\s*pagebreak\s*-->#i',
            '#<!--\s*nextpage\s*-->#i',
            '#\[pagebreak\]#i',
            '#<hr\b[^>]*\bclass\s*=\s*["\'][^"\']*pv-pagebreak[^"\']*["\'][^>]*>#i',
        ];
    }

    /**
     * @return list<string>
     */
    public function splitContentPages(string $html): array
    {
        if ($html === '' || !$this->contentPaginationEnabled()) {
            return $html === '' ? [''] : [$html];
        }

        $mode = $this->contentPaginationMode();
        $manual = $this->splitContentPagesByMarkers($html);

        if ($mode === 'manual') {
            return $manual;
        }
        if ($mode === 'auto') {
            return $this->splitContentPagesByCharLimit($html, $this->contentPaginationCharLimit());
        }

        // both：有手动分页符则用手动；否则按字数
        if (count($manual) > 1) {
            return $manual;
        }

        return $this->splitContentPagesByCharLimit($html, $this->contentPaginationCharLimit());
    }

    /**
     * @return list<string>
     */
    private function splitContentPagesByMarkers(string $html): array
    {
        foreach ($this->pagebreakPatterns() as $pattern) {
            $parts = preg_split($pattern, $html);
            if (!is_array($parts) || count($parts) <= 1) {
                continue;
            }
            $out = [];
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }

            return $out !== [] ? $out : [$html];
        }

        return [$html];
    }

    /**
     * 按可见文本字数分页（优先在 </p>、</div> 处断开，避免截断 HTML 标签）
     *
     * @return list<string>
     */
    public function splitContentPagesByCharLimit(string $html, int $maxChars): array
    {
        $maxChars = max(500, min(20000, $maxChars));
        $plainLen = mb_strlen(strip_tags($html));
        if ($plainLen <= $maxChars) {
            return [$html];
        }

        $blocks = preg_split('#(?=</p\s*>)#iu', $html) ?: [];
        if (count($blocks) <= 1) {
            $blocks = preg_split('#(?=</div\s*>)#iu', $html) ?: [];
        }
        if (count($blocks) <= 1) {
            $blocks = preg_split('#(?=<br\s*/?\s*>)#iu', $html) ?: [];
        }
        if (count($blocks) <= 1) {
            return $this->splitContentPagesByCharLimitHard($html, $maxChars);
        }

        $pages = [];
        $current = '';
        $currentLen = 0;
        foreach ($blocks as $block) {
            $block = (string) $block;
            if ($block === '') {
                continue;
            }
            $len = mb_strlen(strip_tags($block));
            if ($currentLen > 0 && $currentLen + $len > $maxChars) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $pages[] = $trimmed;
                }
                $current   = '';
                $currentLen = 0;
            }
            $current .= $block;
            $currentLen += $len;
        }
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $pages[] = $trimmed;
        }

        return $pages !== [] ? $pages : [$html];
    }

    /**
     * @return list<string>
     */
    private function splitContentPagesByCharLimitHard(string $html, int $maxChars): array
    {
        $pages = [];
        $remaining = $html;
        while (mb_strlen(strip_tags($remaining)) > $maxChars) {
            $cut = $this->findHtmlTextCutPosition($remaining, $maxChars);
            if ($cut <= 0) {
                break;
            }
            $pages[] = trim(mb_substr($remaining, 0, $cut));
            $remaining = (string) mb_substr($remaining, $cut);
        }
        $remaining = trim($remaining);
        if ($remaining !== '') {
            $pages[] = $remaining;
        }

        return $pages !== [] ? $pages : [$html];
    }

    private function findHtmlTextCutPosition(string $html, int $maxChars): int
    {
        $textLen = 0;
        $len = mb_strlen($html);
        $lastSafe = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($html, $i, 1);
            if ($ch === '<') {
                $gt = mb_strpos($html, '>', $i);
                if ($gt === false) {
                    break;
                }
                $i = $gt;

                continue;
            }
            $textLen++;
            $lastSafe = $i + 1;
            if ($textLen >= $maxChars) {
                break;
            }
        }
        if ($lastSafe < 1) {
            return $len;
        }
        // 尽量在标点/空白处断开
        $window = mb_substr($html, max(0, $lastSafe - 80), min(80, $lastSafe));
        if (preg_match('/[\s，。！？；、,.!?;][^\s，。！？；、,.!?;]*$/u', $window, $m, PREG_OFFSET_CAPTURE)) {
            $rel = (int) ($m[0][1] ?? 0) + mb_strlen((string) ($m[0][0] ?? ''));

            return max(1, $lastSafe - 80 + $rel);
        }

        return $lastSafe;
    }

    /**
     * @return array{content:string,page:int,total:int,pages:list<string>}
     */
    public function paginateForDisplay(string $html, int $page): array
    {
        if (!$this->contentPaginationEnabled()) {
            return ['content' => $html, 'page' => 1, 'total' => 1, 'pages' => [$html]];
        }
        $pages = $this->splitContentPages($html);
        if (count($pages) <= 1) {
            return ['content' => $html, 'page' => 1, 'total' => 1, 'pages' => $pages];
        }
        $total = count($pages);
        $page  = max(1, min($page, $total));

        return [
            'content' => $pages[$page - 1],
            'page'    => $page,
            'total'   => $total,
            'pages'   => $pages,
        ];
    }

    private function unlinkTempFile(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            unlink($path);
        }
    }
}
