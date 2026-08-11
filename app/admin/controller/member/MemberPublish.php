<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\member;

use app\common\service\auth\CsrfService;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\content\ContentEditorService;
use app\common\service\document\DocumentAdminService;
use app\common\service\theme\ThemeTemplateCatalogService;
use app\common\service\front\FrontAuthService;
use app\common\service\front\FrontCsrfService;
use app\common\service\member\MemberConfigService;
use app\common\service\member\MemberPublishLayoutService;
use app\common\service\member\MemberService;
use app\common\service\plugin\extension\PluginDocumentEditorService;
use app\common\service\site\SiteNavService;
use app\common\service\tag\TagService;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\Response;

/** 会员投稿（Vue 编辑页 API，鉴权为前台会员 Session） */
class MemberPublish extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly MemberConfigService $memberConfig,
        private readonly FrontAuthService $frontAuth,
        private readonly FrontCsrfService $frontCsrf,
        private readonly MemberPublishLayoutService $memberPublishLayout,
        private readonly DocumentAdminService $document,
        private readonly ThemeTemplateCatalogService $themeTemplateCatalog,
        private readonly TagService $tag,
        private readonly SiteNavService $siteNav,
        private readonly PluginDocumentEditorService $pluginDocumentEditor,
        private readonly MemberService $member,
        private readonly ContentEditorService $contentEditor,
    ) {
        parent::__construct($csrf);
    }

    private function requireMemberJson(): array|Response
    {
        if (!$this->memberConfig->isDocumentPublishOpen()) {
            return AdminApiResponse::fail('会员发文功能未开启');
        }
        $member = $this->frontAuth->current();
        if ($member === null) {
            return AdminApiResponse::authExpired('请先登录会员账号', SiteUrl::memberLogin((string) Request::url(true)));
        }

        return $member;
    }

    public function bootstrap(): Response
    {
        $member = $this->requireMemberJson();
        if ($member instanceof Response) {
            return $member;
        }

        $navActive = trim((string) Request::get('nav', 'document_create'));
        if ($navActive === '') {
            $navActive = 'document_create';
        }

        return AdminApiResponse::fromResult(ServiceResult::ok([
                'logged_in'  => true,
                'member'     => [
                    'id'       => (int) ($member['id'] ?? 0),
                    'username' => (string) ($member['username'] ?? ''),
                    'nickname' => (string) ($member['nickname'] ?? ''),
                ],
                'csrf_token' => $this->frontCsrf->token(),
                'csrf_field' => $this->frontCsrf->fieldName(),
                'shell'      => $this->memberPublishLayout->shellForMember($member, $navActive),
            ]));
    }

    public function documentFormMeta(): Response
    {
        $member = $this->requireMemberJson();
        if ($member instanceof Response) {
            return $member;
        }

        $userId = (int) ($member['id'] ?? 0);
        $id     = (int) Request::get('id', 0);
        $article = $id > 0 ? $this->document->findForAdmin($id) : null;
        if ($id > 0) {
            if ($article === null || (int) ($article['author_id'] ?? 0) !== $userId) {
                return AdminApiResponse::fromResult(ServiceResult::notFound('文档不存在或无权编辑'));
            }
        }
        if (is_array($article)) {
            $article['tags'] = $this->tag->tagNamesCsvForDocument($id);
        }

        $articleId      = (int) ($article['id'] ?? 0);
        $editorSurfaces = $this->pluginDocumentEditor->listMemberSpaSurfaces($articleId);
        $profile        = $this->member->profile($userId) ?? [];
        $nick           = trim((string) ($profile['nickname'] ?? ''));
        $username       = trim((string) ($profile['username'] ?? ''));
        $authorName     = $nick !== '' ? $nick : $username;

        return AdminApiResponse::fromResult(ServiceResult::ok([
                'document'          => $article,
                'isEdit'            => $article !== null,
                'contentEditor'     => $this->contentEditor->current(),
                'memberPointsEnabled' => $this->memberConfig->isPointsEnabled(),
                'templateOptions'   => [],
                'articleTemplates'  => $this->themeTemplateCatalog->listFiles(ThemeTemplateCatalogService::SCOPE_DOCUMENT),
                'authorPresets'     => [],
                'sourcePresets'     => [],
                'sourceOrphan'      => '',
                'authorForm'        => [
                    'checked'         => $authorName !== '' ? [$authorName] : [],
                    'custom_enabled'  => false,
                    'custom'          => '',
                ],
                'formDefaults'      => [
                    'author_name'  => (string) ($article['author_name'] ?? $authorName),
                    'source'       => (string) ($article['source'] ?? ''),
                    'click'        => (int) ($article['click'] ?? 0),
                    'published_at' => '',
                    'tpl_name'     => (string) ($article['tpl_name'] ?? 'view_document.php'),
                    'read_access'  => '0',
                ],
                'editorSurfaces'    => $editorSurfaces,
                'memberPublish'     => true,
                'documentsUrl'      => SiteUrl::memberDocuments(),
                'categoryOptions'   => array_values(array_filter(
                    $this->siteNav->listContentCategoryOptionsForPublish(),
                    static function (array $opt): bool {
                        $kind = (string) ($opt['content_kind'] ?? '');

                        // 会员投稿以文档栏目为主；产品栏目仍可挂文档类内容时保留
                        return $kind === '' || $kind === 'document' || $kind === 'product';
                    }
                )),
            ]));
    }
}
