<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\plugin;

use app\common\service\auth\CsrfService;
use app\common\support\AdminApiResponse;
use app\common\service\access\AccessStatsService;
use app\common\service\infra\DeadLinkCheckService;
use think\facade\Request;

class AccessStats extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly AccessStatsService $accessStats,
        private readonly DeadLinkCheckService $deadLinkCheck,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        if (Request::isAjax()) {
            $dash = $this->accessStats->dashboard();
            $analytics = $this->accessStats->analyticsDashboard();
            $today = is_array($dash['today'] ?? null) ? $dash['today'] : [];
            $week  = is_array($dash['week'] ?? null) ? $dash['week'] : [];
            $list  = [
                ['metric' => '今日 PV', 'value' => (int) ($today['pv'] ?? 0)],
                ['metric' => '今日 UV', 'value' => (int) ($today['uv'] ?? 0)],
                ['metric' => '近7日 PV', 'value' => (int) ($week['pv'] ?? 0)],
                ['metric' => '近7日 UV', 'value' => (int) ($week['uv'] ?? 0)],
            ];
            foreach ((array) ($dash['top_paths'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $list[] = [
                    'metric' => '热门路径 ' . (string) ($row['path'] ?? ''),
                    'value'  => (int) ($row['pv'] ?? 0),
                ];
            }

            return AdminApiResponse::list(['list'  => $list,
                'total' => count($list),
                'cfg'        => $this->accessStats->config(),
                'dash'       => $dash,
                'analytics'  => $analytics]);
        }

        return $this->renderView('access_stats/index');
    }

    public function deadLinks()
    {
        if (Request::isGet()) {
            return AdminApiResponse::admin($this->deadLinkCheck->lastScan());
        }
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $limit = (int) Request::post('limit', 50);
        $mode  = trim((string) Request::post('mode', 'with_links'));
        if ($mode !== 'all' && $limit < 1) {
            $limit = 50;
        }

        return AdminApiResponse::admin($this->deadLinkCheck->scan($limit, $mode));
    }

    public function configSave()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        return AdminApiResponse::admin($this->accessStats->saveConfig(Request::post()));
    }
}
