<?php

/**

 * 演示站文档批量定义 — 工业装备 / 智能制造行业样板（华仪智控）

 * @return list<array{string,string,string,string,string,int,int}> [html, title, tags, flags, summary, click, daysAgo]

 */

declare(strict_types=1);



$preview = '/uploads/demo-seed/preview.svg';



$para = static function (string $lead, array $sections = [], array $bullets = []): string {

    $html = '<p class="lead">' . $lead . '</p>';

    foreach ($sections as $heading => $text) {

        $html .= '<h3>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h3>';

        $html .= '<p>' . $text . '</p>';

    }

    if ($bullets !== []) {

        $html .= '<ul>';

        foreach ($bullets as $b) {

            $html .= '<li>' . $b . '</li>';

        }

        $html .= '</ul>';

    }

    return $html;

};



return [

    // ── 新闻（12）──

    ['pv-demo-news-001', '华仪智控 HY-810 智能压力变送器系列正式发布', 'pv-demo-news', 'headline,recommend,has_image',

        '新一代智能变送器采用自研传感芯体与数字补偿算法，精度等级 0.075%FS，支持 HART / Modbus 双协议。',

        $para('HY-810 系列面向过程工业压力、液位测量场景，已通过 EMC 与防爆（Ex ia IIC T6 Ga）认证。', [

            '核心参数' => '量程覆盖 -100 kPa ~ 40 MPa；工作温度 -40 ~ 85℃；防护等级 IP67。',

            '应用行业' => '石化、电力、冶金、水处理、装备制造等流程工业现场。',

        ], ['全不锈钢隔离膜片', '本地 LCD 现场显示', '可通过组态软件批量参数下发']),

        1260, 1],

    ['pv-demo-news-002', '华仪智控亮相 2026 中国国际工业博览会', 'pv-demo-news', 'recommend,has_image',

        '公司携测控仪器、系统集成方案与典型行业案例参展，现场签署三项战略合作意向。',

        $para('本届工博会公司重点展示过程测控产品线、无纸记录仪与能源管理平台，吸引装备制造与公用事业客户驻足交流。', [

            '展台亮点' => 'HY-820 差压变送器 live demo、DCS 对接沙盘、远程运维大屏。',

        ]),

        980, 2],

    ['pv-demo-news-003', '某大型石化集团 DCS 国产化测控改造一期顺利验收', 'pv-demo-news', 'has_image',

        '项目覆盖 3 套生产装置、1200+ 测点，华仪智控提供变送器、信号调理与上位机软件整体交付。',

        $para('改造后测点在线率提升至 99.2%，年度备件与维护成本下降约 18%。', [

            '交付范围' => '现场仪表更换、机柜接线、组态迁移、操作员培训与一年质保。',

        ], ['符合国产化替代技术路线', '与现有 DCS 通过标准协议对接', '保留原工艺报警逻辑']),

        860, 3],

    ['pv-demo-news-004', '公司通过 ISO 9001 质量管理体系年度监督审核', 'pv-demo-news', 'has_image',

        '审核组对研发、生产、检验、售后全流程给予肯定，未发现不符合项。',

        $para('华仪智控持续以 ISO 9001 为质量管理基础，配套 ISO 14001 环境体系与安全生产标准化运行。'),

        740, 5],

    ['pv-demo-news-005', '与华东某省电力公司签署框架协议', 'pv-demo-news', 'has_image',

        '双方将在变电站辅助测控、无纸化巡检与备品备件供应方面开展长期合作。',

        $para('协议约定优先采用华仪智控记录仪与采集模块，并共建区域售后响应机制。'),

        620, 7],

    ['pv-demo-news-006', 'HY-900 无纸记录仪获得防爆合格证', 'pv-demo-news', 'has_image',

        '适用于 1 区、2 区危险场所的数据记录与曲线存储，通道数最高 48 路。',

        $para('产品支持 USB / 以太网导出、邮件告警与 Modbus TCP 上送，满足 GMP 与审计追溯要求。'),

        580, 9],

    ['pv-demo-news-007', '华北区域售后服务中心正式扩容启用', 'pv-demo-news', 'has_image',

        '新中心配备校准实验室与备件库，可覆盖京津冀、山西、内蒙古主要工业客户。',

        $para('客户可预约现场校准、故障诊断与年度巡检服务，400 热线 7×12 小时响应。'),

        510, 11],

    ['pv-demo-news-008', '《过程测控选型手册 2026 版》正式发布', 'pv-demo-news', 'has_image',

        '手册涵盖压力、温度、流量、液位四大类选型表、安装注意事项与通信接线图。',

        $para('电子版可在资料下载中心免费获取，纸质版可向当地办事处索取。'),

        490, 13],

    ['pv-demo-news-009', '测控技术联合实验室在校企合作单位揭牌', 'pv-demo-news', 'has_image',

        '实验室将开展传感器材料、数字补偿算法与工业软件方向的产学研合作。',

        $para('首批开放课题包括高温高压传感与边缘采集网关原型验证。'),

        450, 15],

    ['pv-demo-news-010', '某市政污水处理厂自动化升级项目案例分享', 'pv-demo-news', 'has_image',

        '项目采用华仪智控液位、流量仪表与 SCADA 采集方案，实现进出水关键参数实时监控。',

        $para('升级后人工抄表频次减少 80%，异常工况短信告警平均响应时间缩短至 15 分钟。'),

        420, 18],

    ['pv-demo-news-011', '「过程测控与智能制造」线上技术研讨会开放报名', 'pv-demo-news', 'has_image',

        '资深应用工程师将讲解选型误区、防爆分区与常见通信故障排查。',

        $para('研讨会面向设计院、总包单位与终端用户工程师，报名成功后发送会议链接。'),

        390, 20],

    ['pv-demo-news-012', '2026 华仪智控渠道伙伴大会圆满落幕', 'pv-demo-news', 'recommend,has_image',

        '来自全国 80 余家授权伙伴参加，发布年度产品路线图与区域市场支持政策。',

        $para('大会表彰了年度优秀集成商与金牌服务商，并启动「千厂测控」市场拓展计划。'),

        1100, 4],



    // ── 应用案例 / 图集（10）──

    ['pv-demo-gallery-01', 'HY-810 智能变送器生产线', 'pv-demo-gallery', 'headline,recommend,has_image',

        'SMT 贴片、激光焊接、高低温老化与标定工序一览，展现工业化量产能力。',

        $para('产线采用 MES 追溯每件产品的校准数据与出厂报告。'),

        1580, 2],

    ['pv-demo-gallery-02', '石化客户催化裂化装置现场', 'pv-demo-gallery', 'recommend,has_image',

        '高温高压工况下的差压、温度测点安装与机柜布线实景。',

        $para('项目采用隔爆与本安混合防爆设计，满足装置区安全规范。'),

        1320, 4],

    ['pv-demo-gallery-03', '2026 工博会展台与产品演示', 'pv-demo-gallery', 'has_image',

        '展台开幕、产品讲解、客户洽谈与签约仪式现场记录。',

        $para('现场演示 HART 手操器读写与无纸记录仪实时曲线。'),

        990, 6],

    ['pv-demo-gallery-04', '电力调度辅助监控中心', 'pv-demo-gallery', 'has_image',

        '大屏展示变电站辅助测控数据与告警汇总界面。',

        $para('系统支持多站所接入与历史趋势对比分析。'),

        870, 8],

    ['pv-demo-gallery-05', '公司研发与校准实验室', 'pv-demo-gallery', 'has_image',

        '标准压力源、温度检定炉、电磁兼容暗室等关键设施。',

        $para('实验室通过 CNAS 认可，可出具第三方校准证书。'),

        760, 10],

    ['pv-demo-gallery-06', '传感器芯体与膜片微距特写', 'pv-demo-gallery', 'has_image',

        '不锈钢膜片、硅电容芯体与灌封工艺细节，体现产品可靠性设计。',

        $para('适用于官网产品详情与选型宣传册配图。'),

        680, 12],

    ['pv-demo-gallery-07', '东南亚某棕榈油项目现场', 'pv-demo-gallery', 'has_image',

        '海外 EPC 项目中的液位、流量仪表安装与本地化技术服务。',

        $para('展示华仪智控装备出海的工程交付能力。'),

        640, 14],

    ['pv-demo-gallery-08', '智能仓储与物流监控', 'pv-demo-gallery', 'has_image',

        '温湿度采集、门禁联动与视频监控在备件中心的集成应用。',

        $para('保障精密仪器仓储环境符合出厂标准。'),

        590, 16],

    ['pv-demo-gallery-09', '客户培训中心与实操课堂', 'pv-demo-gallery', 'has_image',

        '工程师为客户讲解组态软件、接线与故障诊断实操。',

        $para('培训中心可容纳 40 人，支持定制化内训课程。'),

        550, 18],

    ['pv-demo-gallery-10', '分布式光伏场站测控项目', 'pv-demo-gallery', 'has_image',

        '汇流箱监测、逆变器通信与升压站辅助测点部署实景。',

        $para('方案支持 Modbus / IEC104 多协议上送能源管理平台。'),

        720, 20],



    // ── 产品视频（8）──

    ['pv-demo-video-01', 'HY-810 智能压力变送器 · 产品介绍（3 分钟）', 'pv-demo-video', 'headline,recommend,has_image',

        '讲解产品结构、核心技术参数与典型安装方式。',

        $para('适合销售人员与客户工程师快速了解旗舰产品。'),

        3200, 3],

    ['pv-demo-video-02', 'HY-900 无纸记录仪组态操作指南', 'pv-demo-video', 'recommend,has_image',

        '从通道配置、报警设置到历史曲线导出完整演示。',

        $para('建议结合资料下载中的组态手册一并学习。'),

        2100, 5],

    ['pv-demo-video-03', 'HART 通信与手操器现场调试', 'pv-demo-video', 'has_image',

        '演示零点校准、量程迁移与标签读写流程。',

        $para('面向现场仪表工程师与维保人员。'),

        1800, 7],

    ['pv-demo-video-04', 'Modbus RTU 接线与上位机采集', 'pv-demo-video', 'has_image',

        'RS485 接线规范、波特率设置与 SCADA 点表映射。',

        $para('常见通信故障排查要点在片尾总结。'),

        1650, 9],

    ['pv-demo-video-05', 'DCS / PLC 系统对接配置演示', 'pv-demo-video', 'has_image',

        '以主流 DCS 为例展示测点导入与量程换算。',

        $para('适用于系统集成商与总包单位技术同事。'),

        1420, 11],

    ['pv-demo-video-06', '防爆区域仪表选型与安装规范', 'pv-demo-video', 'has_image',

        '解读 Ex 标志、本安回路设计与电缆敷设要求。',

        $para('帮助设计人员避免选型与安装常见误区。'),

        1280, 13],

    ['pv-demo-video-07', '污水处理厂自动化升级案例', 'pv-demo-video', 'has_image',

        '从方案设计、施工调试到验收投运的全过程回顾。',

        $para('对应新闻频道中的市政项目报道。'),

        1100, 15],

    ['pv-demo-video-08', '华仪智控企业概况宣传片（5 分钟）', 'pv-demo-video', 'has_image',

        '公司简介、研发实力、典型行业与服务体系概览。',

        $para('可用于首页轮播、关于我们与商务场合播放。'),

        980, 17],



    // ── 资料下载（6）──

    ['pv-demo-download-01', 'HY-810 智能压力变送器选型手册（PDF）', 'pv-demo-download', 'headline,recommend,has_image',

        '含完整型号命名规则、量程表、材质选项与订货信息。',

        $para('售前选型必备文档，建议与样本册一并归档。', ['版本' => '2026.03 修订版']),

        8900, 2],

    ['pv-demo-download-02', 'HY-810 安装调试指南', 'pv-demo-download', 'recommend,has_image',

        '安装方向、引压管敷设、电气接线与首次上电检查步骤。',

        $para('现场工程师请打印携带，避免安装不规范导致测量偏差。'),

        4200, 4],

    ['pv-demo-download-03', 'HY 系列 CAD 外形图与开孔尺寸（ZIP）', 'pv-demo-download', 'has_image',

        '提供 DXF / PDF 格式外形图，便于机柜与支架设计。',

        $para('解压后请核对产品型号与图纸版本号是否一致。'),

        3100, 6],

    ['pv-demo-download-04', 'Modbus / HART 通信协议说明', 'pv-demo-download', 'has_image',

        '寄存器地址表、功能码说明与通信时序示例。',

        $para('系统集成与二次开发请参考本文档。'),

        2800, 8],

    ['pv-demo-download-05', '防爆合格证与 SIL 认证扫描件', 'pv-demo-download', 'has_image',

        'Ex 防爆、CE、SIL2 等证书合集，便于招投标技术应答。',

        $para('证书编号与产品型号对应关系见目录页。'),

        1900, 10],

    ['pv-demo-download-06', '企业资质与质量体系文件包', 'pv-demo-download', 'has_image',

        '营业执照、ISO 9001、高新技术企业等资质 PDF 合集。',

        $para('供应商准入与项目投标常用资料。'),

        2400, 12],



    // ── 产品 · 测控仪器 ──

    ['pv-demo-product-01', 'HY-810 智能压力变送器', 'pv-demo-product,pv-demo-cat-digital', 'headline,recommend,has_image',

        '精度 0.075%FS，HART / Modbus，不锈钢膜片，适用于过程工业压力与液位测量。',

        $para('旗舰产品，支持在线询价与资料下载。', [

            '量程' => '-100 kPa ~ 40 MPa 多档可选',

            '输出' => '4~20 mA + HART 或 RS485 Modbus RTU',

        ], ['本安 / 隔爆可选', 'LCD 现场显示', '零点和量程可调']),

        2100, 5],

    ['pv-demo-product-02', 'HY-820 智能差压变送器', 'pv-demo-product,pv-demo-cat-digital', 'recommend,has_image',

        '适用于流量、液位、过滤器压差等差压测量场景，耐静压 25 MPa。',

        $para('可配合标准孔板、V 锥等节流装置构成流量测量回路。'),

        1680, 7],

    ['pv-demo-product-03', 'HY-900 无纸记录仪（48 路）', 'pv-demo-product,pv-demo-cat-digital', 'has_image',

        '多通道曲线记录、报警、数据导出，支持以太网与 USB 备份。',

        $para('适用于制药、食品、热处理等需要审计追溯的行业。'),

        920, 9],

    ['pv-demo-product-04', 'HY-510 温度采集模块（8 路 RTD）', 'pv-demo-product,pv-demo-cat-digital', 'has_image',

        '支持 Pt100 / Pt1000，Modbus RTU，DIN 导轨安装。',

        $para('可与 PLC、触摸屏或能源管理平台灵活组网。'),

        780, 11],

    ['pv-demo-product-13', 'HY-630 电磁流量计', 'pv-demo-product,pv-demo-cat-digital', 'recommend,has_image',

        '适用于导电液体体积流量测量，内衬可耐酸碱与磨损介质。',

        $para('一体式 / 分体式可选，支持 HART 与 Modbus 输出。', [

            '口径' => 'DN15 ~ DN600',

            '精度' => '±0.2% FS',

        ], ['空管检测', '双频励磁', '现场 LCD']),

        1120, 13],

    ['pv-demo-product-14', 'HY-702 音叉液位开关', 'pv-demo-product,pv-demo-cat-digital', 'has_image',

        '适用于各类液体、浆料液位高限 / 低限报警，无活动部件。',

        $para('不受泡沫、气泡与粘附影响，维护量低。'),

        640, 15],



    // ── 产品 · 系统集成 ──

    ['pv-demo-product-05', '过程监控系统 Turnkey 方案', 'pv-demo-product,pv-demo-cat-service', 'recommend,has_image',

        '从仪表选型、机柜集成、组态编程到调试培训的一站式交付。',

        $para('适用于中小型装置扩能改造与新建产线。'),

        1340, 6],

    ['pv-demo-product-06', 'DCS / PLC 测控改造服务包', 'pv-demo-product,pv-demo-cat-service', 'has_image',

        '含现场调研、方案设计、施工调试与一年质保。',

        $para('支持主流 DCS 与国产 PLC 平台对接。'),

        880, 8],

    ['pv-demo-product-07', '能源管理与数据采集平台', 'pv-demo-product,pv-demo-cat-service', 'recommend,has_image',

        '水电气热等多能源介质采集、报表分析与 KPI 看板。',

        $para('可扩展至集团多厂区统一监控。'),

        1020, 10],

    ['pv-demo-product-08', '远程运维与年度巡检服务', 'pv-demo-product,pv-demo-cat-service', 'headline,recommend,has_image',

        '7×12 热线、季度现场巡检、备件优先供应与故障响应 SLA。',

        $para('签约客户可享校准实验室优先排期。'),

        1560, 4],



    // ── 产品 · 配件耗材 ──

    ['pv-demo-product-09', '压力变送器膜片备件包', 'pv-demo-product,pv-demo-cat-resource', 'recommend,has_image',

        '316L / 哈氏合金膜片与密封组件，按型号成套供应。',

        $para('建议常备 10% 用量的关键备件。'),

        1180, 12],

    ['pv-demo-product-10', 'RS485 通信线缆与接插件套装', 'pv-demo-product,pv-demo-cat-resource', 'has_image',

        '屏蔽双绞线、终端电阻与工业连接器，符合现场抗干扰要求。',

        $para('含接线端子与标签纸。'),

        990, 14],

    ['pv-demo-product-11', '现场校准工具套装', 'pv-demo-product,pv-demo-cat-resource', 'has_image',

        'HART 手操器、压力泵与接头组，适用于日常维护校准。',

        $para('可选购公司校准服务或自助维护。'),

        850, 16],

    ['pv-demo-product-12', '仪表安装支架与引压管接头', 'pv-demo-product,pv-demo-cat-resource', 'has_image',

        '2" 管架、墙面支架与常用引压阀、接头规格包。',

        $para('缩短现场安装采购周期。'),

        720, 18],

];

