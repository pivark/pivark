<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\home\controller;

use app\common\service\document\satellite\DocumentArticleMetaService;
use app\common\service\document\satellite\DocumentPluginAvailabilityService;
use app\common\service\document\satellite\DocumentQrService;
use app\common\service\document\DocumentAttrHelper;
use app\common\service\document\DocumentFormatService;
use app\common\service\document\DocumentPublicService;
use app\common\service\infra\BreadcrumbService;
use app\common\service\config\ConfigService;
use app\common\service\front\FrontAuthService;
use app\common\service\front\FrontRenderService;
use app\common\service\hook\HookService;
use app\common\service\template\ListPageTemplateContextService;
use app\common\service\infra\PaginationService;
use app\common\service\tag\TagService;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\Response;

class Document extends Base
{
    public function index()
    {
        $page    = max(1, (int) Request::get('page', 1));
        $tagSlug = trim((string) Request::get('tag', ''));
        $params  = ['page' => $page, 'limit' => 12, 'sort' => 'id_desc'];
        if ($tagSlug !== '') {
            $params['tags'] = $tagSlug;
        }
        $result = app(DocumentPublicService::class)->listPublic($params);
        $list   = app(DocumentFormatService::class)->enrichListForView($result['list']);
        $tagRow = null;
        $viewMeta = [
            'tag_slug'        => $tagSlug,
            'tag_name'        => '',
            'tag_description' => '',
            'tag_litpic'      => '',
            'tag_kind'        => TagService::KIND_LABEL,
            'page_title'      => '全部文档',
            'seo_title'       => '全部文档',
            'seo_keywords'    => '',
            'seo_description' => '',
        ];
        if ($tagSlug !== '') {
            $tagRow = app(TagService::class)->findRowBySlug($tagSlug);
            if ($tagRow) {
                $viewMeta = app(TagService::class)->buildPublicListViewVars($tagRow);
            } else {
                $viewMeta['tag_name'] = $tagSlug;
                $viewMeta['page_title'] = '标签：' . $tagSlug;
                $viewMeta['seo_title'] = $viewMeta['page_title'];
            }
        }

        if ($tagSlug === '') {
            $siteCfg = app(ConfigService::class)->getAll();
            $viewMeta['seo_keywords']    = (string) ($siteCfg['site_keywords'] ?? '');
            $viewMeta['seo_description'] = (string) ($siteCfg['site_description'] ?? '');
        }

        return $this->render(app(ThemeTemplateCatalogService::class)->defaultListDocumentTpl(), app(ListPageTemplateContextService::class)->mark(array_merge($viewMeta, [
            'seo_title_context' => $tagSlug !== '' ? 'tag' : 'generic',
            'list'           => $list,
            'breadcrumbs'    => app(BreadcrumbService::class)->forDocumentList(
                (string) $viewMeta['page_title'],
                $tagSlug !== '' ? SiteUrl::tag($tagSlug) : SiteUrl::documents()
            ),
            'tags_nav'       => app(DocumentPublicService::class)->buildTagsNav($tagSlug, 20),
            'all_nav_class'  => $tagSlug === '' ? 'active' : '',
            'page'           => $page,
            'total'          => $result['total'],
            'limit'          => $result['limit'],
            'documents_url'   => SiteUrl::documents($page, $tagSlug),
        ], app(PaginationService::class)->build(
            $page,
            $result['total'],
            $result['limit'],
            static fn (int $p): string => $tagSlug !== ''
                ? SiteUrl::tag($tagSlug, $p)
                : SiteUrl::documents($p)
        ))));
    }

    public function view($key = '')
    {
        $key = (string) ($key ?: Request::param('key', Request::param('id', '')));
        $key = app(\app\common\service\front\FrontUrlResolver::class)->normalizeDocumentKey($key);
        $id  = app(DocumentPublicService::class)->resolvePublicDocumentId($key);
        if ($id < 1) {
            return $this->error('文档不存在', SiteUrl::documents());
        }

        return $this->viewById($id);
    }

    public function viewById(int $id): Response
    {
        if ($id < 1) {
            return $this->error('文档不存在', SiteUrl::documents());
        }

        // 整页缓存命中则跳过正文/相邻/相关等重查询（键按 URI，与 click 无关）
        $cached = $this->tryPageCacheResponse();
        if ($cached !== null) {
            $this->scheduleDocumentClickIncrement($id);

            return $cached;
        }

        $detail = app(DocumentPublicService::class)->getPublicViewData($id);
        if ($detail === null) {
            return $this->error('文档不存在或已被删除', SiteUrl::documents());
        }

        $externalUrl = app(DocumentAttrHelper::class)->resolveExternalRedirectUrl($detail);
        if ($externalUrl !== null) {
            if (app(DocumentAttrHelper::class)->externalOpensInNewTab($detail)) {
                return $this->renderExternalNewTab($externalUrl);
            }

            return redirect($externalUrl, 302);
        }

        $adjacent = app(DocumentPublicService::class)->getAdjacentPublic($id);
        $prev     = $adjacent['prev'];
        $next     = $adjacent['next'];
        if ($prev) {
            $prev['url'] = SiteUrl::documentFromRow($prev);
        }
        if ($next) {
            $next['url'] = SiteUrl::documentFromRow($next);
        }

        $related = app(DocumentPublicService::class)->getRelatedPublic($id, 5);
        $cp      = max(1, (int) ($detail['content_page'] ?? 1));
        $cpTotal = max(1, (int) ($detail['content_page_total'] ?? 1));
        $docUrl  = SiteUrl::documentFromRow($detail);
        $pagePrevUrl = $cp > 1 ? $docUrl . (str_contains($docUrl, '?') ? '&' : '?') . 'cpage=' . ($cp - 1) : '';
        $pageNextUrl = $cp < $cpTotal ? $docUrl . (str_contains($docUrl, '?') ? '&' : '?') . 'cpage=' . ($cp + 1) : '';
        $tpl     = app(ThemeTemplateCatalogService::class)->resolveDocumentViewTplForDetail(
            $detail,
            $this->primaryTagListTpl($detail),
            null,
            $this->primaryTagViewTpl($detail)
        );

        $publishedRaw = $detail['published_at'] ?? $detail['created_at'] ?? '';
        $attrFlags    = (string) ($detail['attr_flags'] ?? '');
        $attrLabels   = app(DocumentAttrHelper::class)->attrFlagLabels($attrFlags);

        app(HookService::class)->fire('document.view', [
            'document_id' => $id,
            'path'        => (string) Request::pathinfo(),
            'referer'     => (string) Request::header('referer', ''),
        ]);

        return $this->render($tpl, array_merge([
            'document_id'          => $id,
            'document_url'         => SiteUrl::documentFromRow($detail),
            'breadcrumbs'         => app(BreadcrumbService::class)->forDocument($detail),
            'document_title'       => $detail['title'],
            'document_title_class' => app(DocumentAttrHelper::class)->hasAttrFlag($attrFlags, 'bold') ? 'fw-bold' : '',
            'document_attr_label_text' => $attrLabels !== [] ? implode(' · ', $attrLabels) : '',
            'document_subtitle'    => $detail['subtitle'] ?? '',
            'document_author'      => $detail['author_name'] ?? '',
            'document_source'      => $detail['source'] ?? '',
            'document_content'     => $detail['content'] ?? '',
            'document_content_page' => $cp,
            'document_content_page_total' => $cpTotal,
            'document_content_page_prev_url' => $pagePrevUrl,
            'document_content_page_next_url' => $pageNextUrl,
            'document_content_paged' => $cpTotal > 1 ? '1' : '',
            'document_qr_url'      => app(DocumentQrService::class)->publicCacheUrl($id),
            'document_share_url'   => app(DocumentQrService::class)->publicDocumentUrl($id),
            'document_summary'     => $detail['summary'] ?? '',
            'document_litpic'      => $detail['litpic'] ?? '',
            'document_click'       => (int) ($detail['click'] ?? 0),
            'document_date'        => $publishedRaw !== ''
                ? date('Y-m-d', strtotime((string) $publishedRaw)) : '',
            'document_tags'        => $detail['tags'] ?? [],
            'document_login_required' => !empty($detail['login_required']),
            'document_read_level_name' => (string) ($detail['read_level_name'] ?? ''),
            'document_perm_hint'       => (string) ($detail['perm_hint'] ?? ''),
            'document_perm_cta_url'    => (string) ($detail['perm_cta_url'] ?? ''),
            'document_perm_cta_label'  => (string) ($detail['perm_cta_label'] ?? ''),
            'seo_title'           => ($detail['seo_title'] ?? '') ?: $detail['title'],
            'seo_title_context'   => 'document',
            'seo_keywords'        => $detail['seo_keywords'] ?? '',
            'seo_description'     => $detail['seo_description'] ?? '',
            'prev_document'        => $prev,
            'next_document'          => $next,
            'related_documents'    => $related,
        ], app(DocumentArticleMetaService::class)->templateVars($detail), app(DocumentPluginAvailabilityService::class)->templateVars(
            $id,
            (string) ($detail['content'] ?? ''),
            (string) ($detail['summary'] ?? '')
        ), app(FrontRenderService::class)->documentChannelHeaderVars($detail)));
    }

    /** 缓存命中路径仍累计浏览量（轻量 shutdown，不挡响应） */
    private function scheduleDocumentClickIncrement(int $id): void
    {
        if ($id < 1) {
            return;
        }
        register_shutdown_function(static function () use ($id): void {
            try {
                \app\common\model\Document::where('id', $id)->inc('click')->update();
            } catch (\Throwable $e) {
                \app\common\support\OpsLog::businessWarning('document_click_increment_failed', [
                    'id'  => $id,
                    'msg' => $e->getMessage(),
                ]);
            }
        });
    }

    /** 文档前台 URL 二维码 PNG（路由 document/qrcode/{id}；缓存 data/runtime/qr/） */
    public function qrcode($id = 0)
    {
        if (!app(DocumentQrService::class)->isEnabled()) {
            return response('Not Found', 404);
        }
        $id = (int) ($id ?: Request::param('id', 0));
        $path = app(DocumentQrService::class)->cachedPngPath($id);
        if ($path === null || !is_file($path)) {
            return response('Not Found', 404);
        }
        $bytes = (string) file_get_contents($path);
        if ($bytes === '' || strlen($bytes) < 100) {
            return response('Not Found', 404);
        }

        return Response::create($bytes, 'html', 200)
            ->header([
                'Content-Type'        => 'image/png',
                'Content-Disposition' => 'inline; filename="document-' . $id . '-qr.png"',
                'Cache-Control'       => 'public, max-age=86400',
            ]);
    }

    public function tags()
    {
        $page   = max(1, (int) Request::get('page', 1));
        $result = app(TagService::class)->listPublic($page, 30);
        $tags   = $result['list'];
        $counts = app(TagService::class)->countPublishedDocumentsByTagIds(array_column($tags, 'id'));
        foreach ($tags as &$tag) {
            $tag['document_count'] = $counts[(int) $tag['id']] ?? 0;
        }

        return $this->render(app(ThemeTemplateCatalogService::class)->systemTagsIndexTpl(), array_merge([
            'tags'            => $tags,
            'breadcrumbs'     => app(BreadcrumbService::class)->forTagsCloud(),
            'page'            => $page,
            'total'           => $result['total'],
            'limit'           => $result['limit'],
            'page_title'      => '标签云',
            'seo_title'       => '标签云',
            'seo_keywords'    => (string) (app(ConfigService::class)->getAll()['site_keywords'] ?? ''),
            'seo_description' => (string) (app(ConfigService::class)->getAll()['site_description'] ?? ''),
            'tag_description' => '浏览全部内容维度与聚合入口',
        ], app(FrontRenderService::class)->channelListTopPageVars(), app(PaginationService::class)->build(
            $page,
            $result['total'],
            $result['limit'],
            static fn (int $p): string => SiteUrl::tags($p)
        )));
    }

    /** 旧链接 /tags/{slug} → 301 到自定义路径 */
    public function tag($slug = '')
    {
        $slug = (string) ($slug ?: Request::param('slug', ''));
        $tagRow = app(TagService::class)->findRowBySlug($slug);
        if ($tagRow === null) {
            return $this->error('标签不存在', SiteUrl::tags());
        }

        return redirect(SiteUrl::tagFromRow($tagRow), 301);
    }

    /** @param array<string, mixed> $detail */
    private function primaryTagListTpl(array $detail): string
    {
        $row = $this->primaryTagRow($detail);

        return $row === null ? '' : (string) ($row['tpl_name'] ?? '');
    }

    /** @param array<string, mixed> $detail */
    private function primaryTagViewTpl(array $detail): string
    {
        $row = $this->primaryTagRow($detail);

        return $row === null ? '' : trim((string) ($row['view_tpl_name'] ?? ''));
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>|null
     */
    private function primaryTagRow(array $detail): ?array
    {
        $primary = app(TagService::class)->primaryTagFromDocument($detail);
        if ($primary === null) {
            return null;
        }
        $slug = trim((string) ($primary['slug'] ?? ''));
        if ($slug !== '') {
            $row = app(TagService::class)->findRowBySlug($slug);
            if (is_array($row)) {
                return $row;
            }
        }

        return is_array($primary) ? $primary : null;
    }

    private function renderExternalNewTab(string $url): Response
    {
        $safeUrl   = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $urlJson   = json_encode($url, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $homeJson  = json_encode(SiteUrl::home(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $html      = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="referrer" content="no-referrer">'
            . '<title>正在打开外链</title></head><body style="font-family:sans-serif;padding:24px;color:#333;">'
            . '<p>正在新窗口打开外链，若未自动打开请 '
            . '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">点击这里</a>。</p>'
            . '<script>(function(){var u=' . $urlJson . ',h=' . $homeJson . ';'
            . 'var w=window.open(u,"_blank","noopener,noreferrer");'
            . 'if(!w){window.location.replace(u);return;}'
            . 'setTimeout(function(){if(window.history.length>1){window.history.back();}'
            . 'else{window.location.replace(h);}},400);})();</script></body></html>';

        return $this->renderRawHtml($html);
    }
}
