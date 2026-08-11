<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\plugin\concern;

use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use think\facade\Request;
use think\Response;

trait PluginLifecycleActions
{
    public function install()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->plugins()->installFromWeapp($this->pluginPostIdentifier()));
    }

    public function enable()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = $this->pluginPostIdentifier();
        if ((int) Request::post('ack_slot_conflict', 0) === 2) {
            return AdminApiResponse::admin($this->plugins()->disable($identifier));
        }

        return AdminApiResponse::admin($this->plugins()->enable(
            $identifier,
            (int) Request::post('ack_slot_conflict', 0) === 1,
        ));
    }

    public function disable()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->plugins()->disable($this->pluginPostIdentifier()));
    }

    public function uninstall()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->plugins()->uninstall(
            $this->pluginPostIdentifier(),
            trim((string) Request::post('purge', 'register'))
        ));
    }

    public function upgrade()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = $this->pluginPostIdentifier();
        $ladder = in_array(strtolower(trim((string) Request::post('ladder', '0'))), ['1', 'true', 'yes'], true);
        if ($ladder) {
            return AdminApiResponse::admin(
                app(\app\common\service\plugin\market\PluginUpgradeLadderService::class)->apply($identifier)
            );
        }

        return AdminApiResponse::admin($this->plugins()->upgrade($identifier));
    }

    /** GET — 升级前联检（内核约束 + 市场阶梯） */
    public function upgradePreflight()
    {
        if (!Request::isAjax() && !Request::isGet()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $identifier = $this->pluginQueryIdentifier();
        if ($identifier === '') {
            $identifier = trim((string) Request::param('identifier', ''));
        }
        if ($identifier === '') {
            return AdminApiResponse::fail('请指定插件 identifier');
        }

        return AdminApiResponse::fromResult(ServiceResult::ok(
            $this->pluginInstallPreflight->forUpgradeReport($identifier)
        ));
    }

    public function installBackups()
    {
        if (!Request::isAjax()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = $this->pluginQueryIdentifier();
        if ($identifier === '') {
            return AdminApiResponse::fail('请指定插件 identifier');
        }

        return AdminApiResponse::list(['data' => [
            'identifier' => $identifier,
            'list'       => $this->pluginInstallBackup->listForIdentifier($identifier),
        ]]);
    }

    public function restoreInstallBackup()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = $this->pluginPostIdentifier();
        $filename   = basename(trim((string) Request::post('filename', '')));
        if ($identifier === '' || $filename === '') {
            return AdminApiResponse::fail('参数无效');
        }

        return AdminApiResponse::admin($this->pluginInstallBackup->restoreFromArchive($identifier, $filename));
    }

    public function createScaffold()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $identifier = $this->pluginPostIdentifier(false);
        if ($identifier === '') {
            $identifier = trim((string) Request::post('identifier', ''));
        }

        return AdminApiResponse::admin($this->pluginScaffold->createDownloadZip([
            'kind'            => (string) Request::post('kind', ''),
            'identifier'      => $identifier,
            'name'            => (string) Request::post('name', ''),
            'package'         => (string) Request::post('package', ''),
            'author'          => (string) Request::post('author', ''),
            'tag_name'        => (string) Request::post('tag_name', ''),
            'pricing_form'    => self::commercialPricingFormFromRequest(),
        ]));
    }

    public function downloadScaffoldZip()
    {
        $token = trim((string) Request::get('token', ''));
        $path = $this->pluginScaffold->resolveDownloadZip($token);
        if ($path === null) {
            return AdminApiResponse::fail('下载已失效，请重新生成脚手架');
        }
        $filename = trim((string) Request::get('filename', ''));
        if ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+\.zip$/', $filename)) {
            $filename = 'plugin-scaffold.zip';
        }

        return Response::create($path, 'file', 200)
            ->name($filename)
            ->mimeType('application/zip');
    }

    public function grant()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $days = (int) Request::post('trial_days', 0);

        return AdminApiResponse::admin($this->entitlements()->grantManual(
            $this->pluginPostIdentifier(),
            trim((string) Request::post('license_type', 'paid')),
            $days
        ));
    }

    public function revokeEntitlement()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->entitlements()->revokeManual($this->pluginPostIdentifier()));
    }

}
