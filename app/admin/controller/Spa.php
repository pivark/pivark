<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\common\service\admin\AdminDashboardService;
use app\common\service\admin\AdminSpaAuthService;
use app\common\service\admin\AdminSpaBootstrapService;
use app\common\service\admin\AdminSpaMenuRouteService;
use app\common\service\admin\AdminSpaRouteResolveService;
use app\common\service\admin\AdminSpaMetaService;
use app\common\service\admin\AdminSpaPluginMetaService;
use app\common\service\admin\login_notice\AdminLoginNoticeAccessService;
use app\common\service\admin\login_notice\AdminLoginNoticeConfigService;
use app\common\service\admin\login_notice\AdminLoginNoticeService;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\facade\Session;
use think\Response;

/** Vue 主后台 JSON 适配（Session + CSRF，对接 PivArk Admin，成功码 code=0） */
class Spa
{
    public function __construct(
        private readonly AdminSpaBootstrapService $spaBootstrap,
        private readonly AdminSpaAuthService $spaAuth,
        private readonly AdminSpaMenuRouteService $spaMenuRoute,
        private readonly AdminSpaRouteResolveService $spaRouteResolve,
        private readonly AdminSpaMetaService $spaMeta,
        private readonly AdminLoginNoticeAccessService $loginNoticeAccess,
        private readonly AdminLoginNoticeConfigService $loginNoticeConfig,
        private readonly AdminLoginNoticeService $loginNotice,
        private readonly AdminSpaPluginMetaService $spaPluginMeta,
        private readonly AdminDashboardService $adminDashboard,
    ) {
    }

    /** GET — 启动信息（免登录） */
    public function bootstrap(): Response
    {
        $admin = Session::get('admin_user');

        return $this->ok($this->spaBootstrap->bootstrapPayload(is_array($admin) ? $admin : null));
    }

    /** GET — Vben UserInfo */
    public function user(): Response
    {
        $admin = $this->sessionAdmin();
        if ($admin === null) {
            return $this->unauthorized();
        }

        return $this->ok($this->spaBootstrap->formatUser($admin, true));
    }

    /** POST — 当前管理员修改密码（首登改密可免旧密码） */
    public function changePassword(): Response
    {
        if (!Request::isPost() && !Request::isPatch()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $admin = $this->sessionAdmin();
        if ($admin === null) {
            return $this->unauthorized();
        }

        $res = $this->spaAuth->changePassword(
            (int) $admin['id'],
            (string) Request::post('old_password', ''),
            (string) Request::post('new_password', '')
        );
        if (!$res->isOk()) {
            return AdminApiResponse::fail((string) ($res->message() ?? '修改失败'));
        }

        return AdminApiResponse::fromResult($res);
    }

    /** GET — TOTP 状态 */
    public function totpStatus(): Response
    {
        return $this->adminOk(fn (): array => $this->spaAuth->totpStatus((int) $this->sessionAdminId()));
    }

    /** POST — 生成待绑定 TOTP 密钥 */
    public function totpSetup(): Response
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $admin = $this->sessionAdmin();
        if ($admin === null) {
            return $this->unauthorized();
        }

        return $this->ok($this->spaAuth->totpSetup(
            (int) $admin['id'],
            (string) ($admin['username'] ?? '')
        ));
    }

    /** POST — 启用 TOTP */
    public function totpEnable(): Response
    {
        return $this->adminPostResult(fn (): ServiceResult => $this->spaAuth->totpEnable(
            $this->sessionAdminId(),
            (string) Request::post('code', '')
        ));
    }

    /** POST — 关闭 TOTP */
    public function totpDisable(): Response
    {
        return $this->adminPostResult(fn (): ServiceResult => $this->spaAuth->totpDisable(
            $this->sessionAdminId(),
            (string) Request::post('code', '')
        ));
    }

    /** GET — 权限码 */
    public function codes(): Response
    {
        $admin = $this->sessionAdmin();
        if ($admin === null) {
            return $this->unauthorized();
        }

        return $this->ok($this->spaAuth->permissionCodes($admin));
    }

    /** GET — Vben 后端动态路由菜单 */
    public function menus(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMenuRoute->vbenRoutes());
    }

    /** GET — 解析后台 href 为 Vue SPA path */
    public function resolveHref(): Response
    {
        return $this->adminOk(fn (): array => [
            'path' => $this->spaRouteResolve->vuePathForAdminHref(
                trim((string) Request::get('href', ''))
            ),
        ]);
    }

    /** GET — 文档发布页：标签库搜索（分页，避免一次加载全部） */
    public function documentTagPicker(): Response
    {
        if (!$this->adminLoggedIn() && !\app\common\support\MemberPublishAccess::isActiveMember()) {
            return $this->unauthorized();
        }
        $keyword = trim((string) Request::get('keyword', ''));
        $page    = max(1, (int) Request::get('page', 1));
        $limit   = min(max((int) Request::get('limit', 20), 1), 50);

        return $this->ok($this->spaMeta->documentTagPicker($keyword, $page, $limit));
    }

    /** GET — 站点配置元数据 */
    public function configMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->configMeta());
    }

    /** GET — 登录后弹窗提醒（表单未读等 · 见 config/admin/login_notices.php） */
    public function loginNotices(): Response
    {
        $admin = $this->sessionAdmin();
        if ($admin === null) {
            return $this->unauthorized();
        }

        return $this->ok([
            'edition_mode'         => $this->loginNoticeAccess->editionMode(),
            'allowed_client_ids'   => $this->loginNoticeAccess
                ->clientAllowedNoticeIds((int) ($admin['id'] ?? 0)),
            'channel_config'       => $this->loginNoticeConfig->noticeChannels(),
            'notices'              => $this->loginNotice->collectForAdmin($admin),
        ]);
    }

    /** GET — 导入意图插件 catalog（内容捕获 Registry SSOT） */
    public function importIntentCatalog(): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return AdminApiResponse::admin($this->spaMeta->spaImportIntentCatalog());
    }

    /** GET — 文档表单元数据 */
    public function documentFormMeta(): Response
    {
        return $this->adminOkOrNotFound(
            fn (): ?array => $this->spaMeta->documentFormMeta(
                (int) Request::get('id', 0),
                $this->sessionAdmin() ?? []
            ),
            '文档不存在'
        );
    }

    /** GET — 用户表单元数据 */
    public function userFormMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->userFormMeta(
            (int) Request::get('id', 0),
            Session::get('admin_user')
        ));
    }

    /** GET — 角色表单元数据 */
    public function roleFormMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->roleFormMeta((int) Request::get('id', 0)));
    }

    /** GET — 会员表单元数据 */
    public function memberFormMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->memberFormMeta());
    }

    /** GET — 定时任务元数据 */
    public function cronMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->cronMeta());
    }

    /** GET — 已启用 weapp 插件配置 meta（download/comment/ask/…） */
    public function pluginMeta(string $plugin): Response
    {
        return $this->adminResult(fn (): ServiceResult => $this->spaPluginMeta->spaMeta($plugin));
    }

    /** GET — AI 配置（L1 内核 · /system/ai-config） */
    public function aiConfigMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->aiDocumentMeta());
    }

    /** GET — 网站栏目元数据 */
    public function siteNavMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->siteNavMeta());
    }

    /** GET — 单页表单元数据 */
    public function sitePageFormMeta(): Response
    {
        return $this->adminOkOrNotFound(
            fn (): ?array => $this->spaMeta->sitePageFormMeta((int) Request::get('id', 0)),
            '单页不存在'
        );
    }

    /** GET — 会员中心功能配置 */
    public function memberCenterConfigMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->memberCenterConfigMeta());
    }

    /** GET — 系统内置支付配置 */
    public function paymentConfigMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->paymentConfigMeta());
    }

    /** GET — 支付订单列表（后台标记退款等） */
    public function paymentOrdersMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->paymentOrdersList(Request::get()));
    }

    /** GET — 微信小程序渠道配置 */
    public function miniprogramWechatMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->miniprogramWechatMeta());
    }

    /** GET — 小程序页面装修 */
    public function miniprogramPageMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->miniprogramPageMeta());
    }

    /** GET — 搜索管理（全文引擎 + 超级搜索智能化） */
    public function searchConfigMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->searchConfigMeta());
    }

    /** GET — 验证码场景配置 */
    public function captchaConfigMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->captchaConfigMeta());
    }

    /** GET — 登录/事件提醒渠道设置 */
    public function loginNoticeSettingsMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->loginNoticeSettingsMeta());
    }

    /** GET — 邮件 SMTP 接口配置 */
    public function mailConfigMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->mailConfigMeta());
    }

    /** GET — 短信网关接口配置 */
    public function smsConfigMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->smsConfigMeta());
    }

    /** GET — 会员积分全局规则 */
    public function memberPointsConfigMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->memberPointsConfigMeta());
    }

    /** GET — 会员字段表单元数据 */
    public function memberCenterFieldMeta(): Response
    {
        return $this->adminOkOrNotFound(
            fn (): ?array => $this->spaMeta->memberCenterFieldMeta((int) Request::get('id', 0)),
            '字段不存在'
        );
    }

    /** GET — 充值套餐表单元数据 */
    public function memberCenterRechargeMeta(): Response
    {
        return $this->adminOkOrNotFound(
            fn (): ?array => $this->spaMeta->memberCenterRechargeMeta((int) Request::get('id', 0)),
            '套餐不存在'
        );
    }

    /** GET — HTML 静态生成元数据 */
    public function seoStaticMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->seoStaticMeta());
    }

    /** GET — 悬浮联系方式：配置 + 条目列表 */
    public function floatContactMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->floatContactMeta());
    }

    /** GET — 插件脚手架页元数据 */
    public function pluginScaffoldMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->pluginScaffoldMeta());
    }

    /** GET — SEO URL 配置元数据 */
    public function seoUrlMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->seoUrlMeta());
    }

    /** GET — Sitemap 配置元数据 */
    public function seoSitemapMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->seoSitemapMeta());
    }

    /** GET — Robots 配置元数据 */
    public function seoRobotsMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->seoRobotsMeta());
    }

    /** GET — 标签表单元数据 */
    public function tagFormMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->tagFormMeta(
            (int) Request::get('id', 0),
            (int) Request::get('group_id', 0)
        ));
    }

    /** GET — 标签页筛选项 */
    public function tagMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->tagMeta());
    }

    /** GET — 文档列表筛选项 */
    public function documentMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->documentMeta());
    }

    /** GET — 操作日志筛选项 */
    public function logMeta(): Response
    {
        return $this->adminOk(fn (): array => $this->spaMeta->logMeta());
    }

    /** GET — 控制台概览 */
    public function dashboard(): Response
    {
        $refresh = (int) Request::param('refresh', 0) === 1;

        return $this->adminOk(
            fn (): array => $this->adminDashboard->spaDashboard($this->sessionAdminId(), $refresh),
        );
    }

    /** POST — 保存控制台展示偏好 */
    public function saveDashboardPrefs(): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $body = Request::post();
        if (!is_array($body) || $body === []) {
            $raw = Request::getContent();
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }
        if (!is_array($body)) {
            $body = [];
        }

        return $this->ok($this->adminDashboard->spaSavePrefs($this->sessionAdminId(), $body));
    }

    /** @return array<string, mixed>|null */
    private function sessionAdmin(): ?array
    {
        $admin = Session::get('admin_user');
        if (!is_array($admin) || empty($admin['id'])) {
            return null;
        }

        return $admin;
    }

    private function sessionAdminId(): int
    {
        return (int) ($this->sessionAdmin()['id'] ?? 0);
    }

    private function adminLoggedIn(): bool
    {
        return $this->sessionAdmin() !== null;
    }

    private function requireAdmin(): ?Response
    {
        return $this->sessionAdmin() === null ? $this->unauthorized() : null;
    }

    /** @param callable(): mixed $loader */
    private function adminOk(callable $loader): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->ok($loader());
    }

    /** @param callable(): ServiceResult $loader */
    private function adminResult(callable $loader): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }
        $res = $loader();
        if (!$res->isOk()) {
            return AdminApiResponse::fromResult($res);
        }

        return $this->ok($res->dataArray() ?? []);
    }

    /** @param callable(): ServiceResult $loader */
    private function adminPostResult(callable $loader): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::fromResult($loader());
    }

    /** @param callable(): ?array<string, mixed> $loader */
    private function adminOkOrNotFound(callable $loader, string $notFoundMessage): Response
    {
        $deny = $this->requireAdmin();
        if ($deny !== null) {
            return $deny;
        }
        $payload = $loader();
        if ($payload === null) {
            return AdminApiResponse::fromResult(ServiceResult::notFound($notFoundMessage));
        }

        return $this->ok($payload);
    }

    /** @param mixed $data */
    private function ok(mixed $data): Response
    {
        return AdminApiResponse::fromResult(ServiceResult::ok($data, ''));
    }

    private function unauthorized(): Response
    {
        return AdminApiResponse::authExpired('未登录', SiteUrl::adminSpa('/auth/login'), 401);
    }
}
