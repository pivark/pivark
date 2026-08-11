<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

return [
    ['parent' => 'HY-810', 'child' => 'SP-MOUNT', 'relation_type' => 'accessory', 'note' => '2" 管架与引压管接头套装', 'sort' => 1],
    ['parent' => 'HY-810', 'child' => 'SP-DIAPHRAGM', 'relation_type' => 'spare', 'note' => '建议常备 10% 用量', 'sort' => 2],
    ['parent' => 'HY-810', 'child' => 'SP-RS485-KIT', 'relation_type' => 'component', 'note' => 'Modbus 现场布线', 'sort' => 3],
    ['parent' => 'HY-820', 'child' => 'SP-MOUNT', 'relation_type' => 'accessory', 'sort' => 1],
    ['parent' => 'HY-820', 'child' => 'SP-DIAPHRAGM', 'relation_type' => 'spare', 'sort' => 2],
    ['parent' => 'HY-900', 'child' => 'SP-RS485-KIT', 'relation_type' => 'component', 'sort' => 1],
    ['parent' => 'HY-900', 'child' => 'SP-CAL-TOOL', 'relation_type' => 'accessory', 'sort' => 2],
    ['parent' => 'HY-510', 'child' => 'SP-RS485-KIT', 'relation_type' => 'component', 'sort' => 1],
    ['parent' => 'HY-630', 'child' => 'SP-MOUNT', 'relation_type' => 'accessory', 'sort' => 1],
    ['parent' => 'HY-702', 'child' => 'SP-MOUNT', 'relation_type' => 'accessory', 'sort' => 1],
    ['parent' => 'SVC-TURNKEY', 'child' => 'HY-810', 'relation_type' => 'component', 'note' => '典型配置主机型', 'sort' => 1],
    ['parent' => 'SVC-TURNKEY', 'child' => 'HY-900', 'relation_type' => 'component', 'note' => '数据记录单元', 'sort' => 2],
];
