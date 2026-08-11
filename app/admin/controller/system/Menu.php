<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\support\ServiceResult;
use app\common\service\config\ConfigService;
use app\common\service\menu\MenuService;
use app\common\support\SiteUrl;
use think\facade\Request;
use think\Response;

// app/admin/controller/Menu.php — 菜单管理

class Menu extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly MenuService $menu,
        private readonly ConfigService $config,
    ) {
        parent::__construct($csrf);
    }

    /**
     * JSON 接口：侧栏菜单树（Vue 主后台 bootstrap 使用）
     */
    public function init()
    {
        $menus = $this->menu->getTree();
        $menuInfo = [];
        foreach ($menus as $m) {
            $item = ['title' => $m['title'], 'icon' => $m['icon'] ?: 'fa fa-circle-o'];
            if (!empty($m['children'])) {
                $item['child'] = [];
                foreach ($m['children'] as $child) {
                    $item['child'][] = self::formatMenuNode($child);
                }
            } elseif (!empty($m['route'])) {
                // 顶级叶子菜单包一层 child，与有子菜单的结构一致
                $item['child'] = [[
                    'title'  => $m['title'],
                    'icon'   => $m['icon'] ?: 'fa fa-circle-o',
                    'href'   => $m['route'],
                    'target' => '_self',
                ]];
            }
            if (!empty($item['child'])) {
                $menuInfo[] = $item;
            }
        }

        $siteLogo = trim((string) $this->config->get('site_logo', ''));
        $homeInfo = $this->menu->defaultHomeInfo();
        return AdminApiResponse::admin(ServiceResult::ok([
            'homeInfo' => $homeInfo,
            'logoInfo' => [
                'title' => (string) $this->config->get('site_name', '元舟 PivArk'),
                'image' => $siteLogo !== '' ? $siteLogo : '/static/admin/images/logo.svg',
                'href'  => $homeInfo['href'],
            ],
            'siteHome' => SiteUrl::configuredPublicHome(),
            'menuInfo' => $menuInfo,
        ]));
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function formatMenuNode(array $node): array
    {
        $item = [
            'title'  => $node['title'],
            'icon'   => $node['icon'] ?: 'fa fa-circle-o',
            'href'   => $node['route'] ?: 'javascript:;',
            'target' => '_self',
        ];
        if (!empty($node['children'])) {
            $item['child'] = [];
            foreach ($node['children'] as $sub) {
                $item['child'][] = self::formatMenuNode($sub);
            }
        }

        return $item;
    }

    public function index()
    {
        if (Request::isAjax()) {
            $list = $this->menu->getAll();
            return AdminApiResponse::list(['total' => count($list),
                'list'  => $list,
                'data'  => $list]);
        }

        return $this->renderView('menu/index');
    }

    public function create()
    {
        $parentOptions = $this->menu->getOptions();
        return $this->renderView('menu/form', compact('parentOptions'));
    }

    public function edit()
    {
        $id = (int) Request::get('id', 0);
        $info = $this->menu->findForForm($id);
        if (!$info) {
            return redirect('/admin/menu/index');
        }
        $parentOptions = $this->menu->getOptions((int) $info['parent_id']);
        return $this->renderView('menu/form', compact('parentOptions', 'info'));
    }

    public function save()
    {
        if (!Request::isPost()) return AdminApiResponse::fail('请求方式错误');
        $id = (int) Request::post('id', 0);
        $data = Request::post();
        $res = $id ? $this->menu->update($id, $data) : $this->menu->create($data);
        return AdminApiResponse::fromResult($res);
    }

    public function sort()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->menu->updateSort(
            (int) Request::post('id', 0),
            (int) Request::post('sort', 0)
        ));
    }

    public function delete()
    {
        if (!Request::isPost()) return AdminApiResponse::fail('请求方式错误');
        return AdminApiResponse::admin($this->menu->delete((int) Request::post('id', 0)));
    }
}
