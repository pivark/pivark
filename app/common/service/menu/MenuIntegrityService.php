<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\menu;

use app\common\model\Menu;

/** 后台菜单数据完整性（交付门禁用） */
class MenuIntegrityService
{

    /**
     * @return list<array{parent_id:int,route:string,count:int,ids:string}>
     */
    public function duplicateRoutes(): array
    {
        $rows = Menu::where('status', 1)
            ->whereRaw("TRIM(route) <> ''")
            ->field('parent_id, route, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids')
            ->group('parent_id, LOWER(TRIM(route))')
            ->having('cnt > 1')
            ->select()
            ->toArray();
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'parent_id' => (int) ($row['parent_id'] ?? 0),
                'route'     => (string) ($row['route'] ?? ''),
                'count'     => (int) ($row['cnt'] ?? 0),
                'ids'       => (string) ($row['ids'] ?? ''),
            ];
        }

        return $out;
    }

    public function hasDuplicateRoutes(): bool
    {
        return $this->duplicateRoutes() !== [];
    }
}
