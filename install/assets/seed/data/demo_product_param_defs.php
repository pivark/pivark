<?php
/**
 * 演示站 · 产品插件自定义参数定义
 */
declare(strict_types=1);

return [
    [
        'param_key'  => 'product_line',
        'label'      => '产品线',
        'input_type' => 'select',
        'filterable' => 1,
        'sort'       => 10,
        'options'    => ['压力变送器', '差压变送器', '记录仪', '采集模块', '流量仪表', '液位仪表', '系统集成', '运维服务', '配件耗材'],
    ],
    [
        'param_key'  => 'output_signal',
        'label'      => '输出信号',
        'input_type' => 'select',
        'filterable' => 1,
        'sort'       => 20,
        'options'    => ['HART', 'Modbus RTU', '4~20 mA', '以太网', '—'],
    ],
    [
        'param_key'  => 'accuracy',
        'label'      => '精度等级',
        'input_type' => 'select',
        'filterable' => 1,
        'sort'       => 30,
        'options'    => ['0.04%FS', '0.075%FS', '0.1%FS', '0.2%FS', '—'],
    ],
];
