<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\support\ServiceResult;
use app\common\support\OpsLog;


use app\common\service\config\ConfigService;
use app\common\model\Document;
use app\common\support\SiteUrl;
use app\common\support\LocalFile;

/** 文档前台 URL 二维码（PNG 缓存，离线可读） */
class DocumentQrService
{

    public function __construct(
        private readonly ConfigService $config,
    ) {
    }

    private const CACHE_SUBDIR = 'qr';
    private const REMOTE_API   = 'https://api.qrserver.com/v1/create-qr-code/';

    public function isEnabled(): bool
    {
        return (string) $this->config->get('document_qr_enabled', '1') === '1';
    }

    public function publicDocumentUrl(int $documentId): string
    {
        if ($documentId < 1) {
            return '';
        }
        $row = Document::where('id', $documentId)->whereNull('deleted_at')->find();
        if ($row === null) {
            return '';
        }

        return SiteUrl::absolute(SiteUrl::documentFromRow($row->toArray()));
    }

    /**
     * @return ServiceResult
     */
    public function infoForAdmin(int $documentId): ServiceResult
    {
        if (!$this->isEnabled()) {
            return ServiceResult::fail('文档二维码功能未开启，请在系统配置 → 内容与编辑中启用');
        }
        $url = $this->publicDocumentUrl($documentId);
        if ($url === '') {
            return ServiceResult::fail('文档不存在或未发布');
        }
        $path = $this->ensureCachedPng($url, 'doc_' . $documentId);
        if ($path === null) {
            return ServiceResult::fail('二维码生成失败，请检查服务器出网或 GD 扩展');
        }

        return ServiceResult::ok(['url' => $url, 'qr_path' => $path, 'qr_url' => $this->publicCacheUrl($documentId)], 'ok');
    }

    public function publicCacheUrl(int $documentId): string
    {
        if (!$this->isEnabled() || $documentId < 1) {
            return '';
        }

        return SiteUrl::home() . 'document/qrcode/' . $documentId;
    }

    /** 品项页绝对 URL（/items/{slug}） */
    public function publicItemUrl(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        return SiteUrl::absolute(SiteUrl::productItem($slug));
    }

    public function publicItemCacheUrl(int $itemId): string
    {
        if (!$this->isEnabled() || $itemId < 1) {
            return '';
        }

        return SiteUrl::home() . 'item/qrcode/' . $itemId;
    }

    /** @return string|null 绝对路径 */
    public function cachedPngPath(int $documentId): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $url = $this->publicDocumentUrl($documentId);
        if ($url === '') {
            return null;
        }

        return $this->ensureCachedPng($url, 'doc_' . $documentId);
    }

    /** @return string|null 绝对路径 */
    public function cachedItemPngPath(int $itemId, string $slug): ?string
    {
        if (!$this->isEnabled() || $itemId < 1) {
            return null;
        }
        $url = $this->publicItemUrl($slug);
        if ($url === '') {
            return null;
        }

        return $this->ensureCachedPng($url, 'item_' . $itemId);
    }

    private function ensureCachedPng(string $targetUrl, string $cacheKeyPrefix): ?string
    {
        $dir = runtime_path() . self::CACHE_SUBDIR . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $file = $dir . $cacheKeyPrefix . '_' . substr(hash('sha256', $targetUrl), 0, 12) . '.png';
        if (is_file($file) && filesize($file) > 100) {
            return $file;
        }

        $size = max(120, min(512, (int) $this->config->get('document_qr_size', 200)));
        $remote = self::REMOTE_API . '?size=' . $size . 'x' . $size . '&data=' . rawurlencode($targetUrl);
        $bytes  = $this->fetchBytes($remote);
        if ($bytes === null || $bytes === '') {
            $bytes = $this->renderLocalPng($targetUrl, $size);
        }
        if ($bytes === null || $bytes === '') {
            return null;
        }
        if (!LocalFile::putContents($file, $bytes, LOCK_EX)) {
            return null;
        }

        return $file;
    }

    private function renderLocalPng(string $data, int $size): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        $baseDir = root_path() . 'app' . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'third_party' . DIRECTORY_SEPARATOR . 'phpqrcode' . DIRECTORY_SEPARATOR;
        $lib     = $baseDir . 'qrlib.php';
        if (!is_file($lib)) {
            return null;
        }
        $cacheDir = $baseDir . 'cache';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return null;
        }
        $prevLevel = error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
        try {
            require_once $lib;
            $moduleSize = max(2, min(8, (int) round($size / 40)));
            ob_start();
            \QRcode::png($data, false, QR_ECLEVEL_M, $moduleSize, 2, false);
            $png = ob_get_clean();
        } catch (\Throwable $e) {
            OpsLog::businessWarning('document_qr_render_failed', ['msg' => $e->getMessage()]);
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $png = false;
        } finally {
            error_reporting($prevLevel);
        }

        return ($png !== false && $png !== '' && strlen($png) > 100) ? $png : null;
    }

    private function fetchBytes(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_USERAGENT      => 'PivArkDocumentQr/1.0',
            ]);
            $data = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($data === false || $code >= 400) {
                return null;
            }

            return (string) $data;
        }
        $ctx  = stream_context_create(['http' => ['timeout' => 12, 'follow_location' => 1]]);
        $data = LocalFile::getContents($url, false, $ctx);

        return ($data === null || $data === '') ? null : $data;
    }
}
