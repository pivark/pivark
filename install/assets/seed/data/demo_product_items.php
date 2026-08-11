<?php
/**
 * 演示站 · 品项（与 pv-demo-product-* 文档一一对应）
 *
 * @return list<array<string, mixed>>
 */
declare(strict_types=1);

use app\common\service\item\ItemService;

return [
    [
        'html' => 'pv-demo-product-01', 'code' => 'HY-810', 'slug' => 'hy-810',
        'name' => 'HY-810 智能压力变送器', 'item_type' => ItemService::TYPE_PHYSICAL,
        'tags' => ['pv-demo-product', 'pv-demo-cat-digital'], 'sort' => 1,
        'attrs' => ['product_line' => '压力变送器', 'output_signal' => 'HART', 'accuracy' => '0.075%FS'],
        'shop' => true,
    ],
    [
        'html' => 'pv-demo-product-02', 'code' => 'HY-820', 'slug' => 'hy-820',
        'name' => 'HY-820 智能差压变送器', 'item_type' => ItemService::TYPE_PHYSICAL,
        'tags' => ['pv-demo-product', 'pv-demo-cat-digital'], 'sort' => 2,
        'attrs' => ['product_line' => '差压变送器', 'output_signal' => 'HART', 'accuracy' => '0.075%FS'],
    ],
    [
        'html' => 'pv-demo-product-03', 'code' => 'HY-900', 'slug' => 'hy-900',
        'name' => 'HY-900 无纸记录仪（48 路）', 'item_type' => ItemService::TYPE_PHYSICAL,
        'tags' => ['pv-demo-product', 'pv-demo-cat-digital'], 'sort' => 3,
        'attrs' => ['product_line' => '记录仪', 'output_signal' => '以太网', 'accuracy' => '0.1%FS'],
    ],
    [
        'html' => 'pv-demo-product-04', 'code' => 'HY-510', 'slug' => 'hy-510',
        'name' => 'HY-510 温度采集模块（8 路 RTD）', 'item_type' => ItemService::TYPE_COMPONENT,
        'tags' => ['pv-demo-product', 'pv-demo-cat-digital'], 'sort' => 4,
        'attrs' => ['product_line' => '采集模块', 'output_signal' => 'Modbus RTU', 'accuracy' => '0.2%FS'],
    ],
    [
        'html' => 'pv-demo-product-13', 'code' => 'HY-630', 'slug' => 'hy-630',
        'name' => 'HY-630 电磁流量计', 'item_type' => ItemService::TYPE_PHYSICAL,
        'tags' => ['pv-demo-product', 'pv-demo-cat-digital'], 'sort' => 13,
        'attrs' => ['product_line' => '流量仪表', 'output_signal' => 'HART', 'accuracy' => '0.2%FS'],
    ],
    [
        'html' => 'pv-demo-product-14', 'code' => 'HY-702', 'slug' => 'hy-702',
        'name' => 'HY-702 音叉液位开关', 'item_type' => ItemService::TYPE_PHYSICAL,
        'tags' => ['pv-demo-product', 'pv-demo-cat-digital'], 'sort' => 14,
        'attrs' => ['product_line' => '液位仪表', 'output_signal' => '4~20 mA', 'accuracy' => '—'],
    ],
    [
        'html' => 'pv-demo-product-05', 'code' => 'SVC-TURNKEY', 'slug' => 'integration-turnkey',
        'name' => '过程监控系统 Turnkey 方案', 'item_type' => ItemService::TYPE_SERVICE,
        'tags' => ['pv-demo-product', 'pv-demo-cat-service'], 'sort' => 5,
        'attrs' => ['product_line' => '系统集成', 'output_signal' => '—', 'accuracy' => '—'],
    ],
    [
        'html' => 'pv-demo-product-06', 'code' => 'SVC-DCS-PLC', 'slug' => 'integration-dcs-plc',
        'name' => 'DCS / PLC 测控改造服务包', 'item_type' => ItemService::TYPE_SERVICE,
        'tags' => ['pv-demo-product', 'pv-demo-cat-service'], 'sort' => 6,
        'attrs' => ['product_line' => '系统集成', 'output_signal' => '—', 'accuracy' => '—'],
    ],
    [
        'html' => 'pv-demo-product-07', 'code' => 'SVC-EMS', 'slug' => 'integration-ems',
        'name' => '能源管理与数据采集平台', 'item_type' => ItemService::TYPE_DIGITAL,
        'tags' => ['pv-demo-product', 'pv-demo-cat-service'], 'sort' => 7,
        'attrs' => ['product_line' => '系统集成', 'output_signal' => '以太网', 'accuracy' => '—'],
    ],
    [
        'html' => 'pv-demo-product-08', 'code' => 'SVC-MAINT', 'slug' => 'integration-maintenance',
        'name' => '远程运维与年度巡检服务', 'item_type' => ItemService::TYPE_SERVICE,
        'tags' => ['pv-demo-product', 'pv-demo-cat-service'], 'sort' => 8,
        'attrs' => ['product_line' => '运维服务', 'output_signal' => '—', 'accuracy' => '—'],
    ],
    [
        'html' => 'pv-demo-product-09', 'code' => 'SP-DIAPHRAGM', 'slug' => 'accessory-diaphragm-kit',
        'name' => '压力变送器膜片备件包', 'item_type' => ItemService::TYPE_KIT,
        'tags' => ['pv-demo-product', 'pv-demo-cat-resource'], 'sort' => 9,
        'attrs' => ['product_line' => '配件耗材', 'output_signal' => '—', 'accuracy' => '—'],
    ],
    [
        'html' => 'pv-demo-product-10', 'code' => 'SP-RS485-KIT', 'slug' => 'accessory-rs485-kit',
        'name' => 'RS485 通信线缆与接插件套装', 'item_type' => ItemService::TYPE_KIT,
        'tags' => ['pv-demo-product', 'pv-demo-cat-resource'], 'sort' => 10,
        'attrs' => ['product_line' => '配件耗材', 'output_signal' => 'Modbus RTU', 'accuracy' => '—'],
    ],
    [
        'html' => 'pv-demo-product-11', 'code' => 'SP-CAL-TOOL', 'slug' => 'accessory-calibration-kit',
        'name' => '现场校准工具套装', 'item_type' => ItemService::TYPE_KIT,
        'tags' => ['pv-demo-product', 'pv-demo-cat-resource'], 'sort' => 11,
        'attrs' => ['product_line' => '配件耗材', 'output_signal' => '—', 'accuracy' => '0.04%FS'],
    ],
    [
        'html' => 'pv-demo-product-12', 'code' => 'SP-MOUNT', 'slug' => 'accessory-mount-bracket',
        'name' => '仪表安装支架与引压管接头', 'item_type' => ItemService::TYPE_COMPONENT,
        'tags' => ['pv-demo-product', 'pv-demo-cat-resource'], 'sort' => 12,
        'attrs' => ['product_line' => '配件耗材', 'output_signal' => '—', 'accuracy' => '—'],
    ],
];
