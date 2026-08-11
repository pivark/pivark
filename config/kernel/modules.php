<?php

/**

 * L1 Platform Module 目录（主程序内置、按插件 needs 并集点亮）

 * SSOT：docs/01-架构/插件与授权.md §4.14

 */

return [

    'payment' => [

        'label'       => '在线支付',

        'description' => '微信 / 支付宝 / 余额；配置在系统设置，非插件应用',

        'fallback_needs' => [],

        'kernel_builtin' => true,

    ],

    'enterprise_resource' => [

        'label'       => '企业经营资料',

        'description' => '证照/合同/投标资料索引，文件本体走上传去重池；侧栏入口由已启用且 needs 含本模块的经营/企业应用插件点亮',

        'fallback_needs' => ['tender'],

    ],

    'items' => [

        'label'       => '品项内核',

        'description' => '货号中枢 item_id，商城/ERP/投标行共用',

        'fallback_needs' => [],

    ],

    'event_bus' => [

        'label'       => '领域事件',

        'description' => '跨插件编排 project.won / order.confirmed 等',

        'fallback_needs' => [],

    ],

    'analytics' => [

        'label'       => '访问统计',

        'description' => '页面 PV/UV 采集与后台报表；系统内核，非插件',

        'fallback_needs' => [],

        'kernel_builtin' => true,

    ],

    'form' => [

        'label'       => '自定表单',

        'description' => '留言/报名/咨询表单 builder；系统内核，非插件',

        'fallback_needs' => [],

        'kernel_builtin' => true,

    ],

    'member' => [

        'label'       => '会员中心',

        'description' => '前台登录/个人中心 + 等级/积分/余额/充值套餐；开源版内置，非 weapp',

        'fallback_needs' => [],

        'kernel_builtin' => true,

    ],

    'favorite' => [

        'label'       => '点赞与收藏',

        'description' => '文档点赞、收藏计数与前台 {pv:favorite} / API；系统内核，非 weapp',

        'fallback_needs' => [],

        'kernel_builtin' => true,

    ],

    'float_contact' => [

        'label'       => '悬浮联系',

        'description' => '全站 footer 悬浮客服/社交入口；`{pv:floatcontact}`；系统模块',

        'fallback_needs' => [],

        'kernel_builtin' => true,

    ],

    'product' => [

        'label'       => '产品中心',

        'description' => '企业产品中枢（品项/参数/文档 Tab/{pv:product*}）；L1 内核非 weapp；专业版+ 等档位门禁 ProductCenterGateService；shop 等 L2 挂中枢壳',

        'fallback_needs' => ['items'],

        'kernel_builtin' => true,

    ],

    'ai_config' => [

        'label'       => 'AI 配置与搜索',

        'description' => 'LLM 路由、文档解析/OCR、超级搜索向量召回；tender 软依赖',

        'fallback_needs' => ['tender'],

        'kernel_builtin' => true,

    ],

    'insights' => [

        'label'       => '经营洞察',

        'description' => '各模块 KPI 卡片/趋势总线；管理首页 cockpit 聚合；非重型 BI',

        'fallback_needs' => [
            'shop',
            'tender',
            'oa',
            'crm',
            'erp',
            'plm',
            'mes',
            'geo',
            'mp-wechat',
        ],

    ],

    'organization' => [

        'label'       => '组织架构',

        'description' => '部门/员工/数据范围；needs 语义=OA 已装且授权；未装时 dataScope 降级 self',

        'fallback_needs' => ['oa'],

    ],

];

