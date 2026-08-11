<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 *
 * 发行演示加灌：新闻长文 + 案例加量（下载/视频改由插件 VolumeSeed）
 * 行结构：[html_name, title, tagCsv, flags, summary, contentHtml, click, daysAgo]
 * flags 含 has_image 才写 litpic；默认空封面更真实。
 */
declare(strict_types=1);

$longNews = static function (string $title, string $hook): string {
    $parts = [];
    $parts[] = '<p class="lead">' . $hook . '</p>';
    $parts[] = '<p>华仪智控以测控仪表与系统集成为主业。本文用于演示「长文 + 正文分页」，列表页不强制大封面。</p>';
    $parts[] = '<h2>背景</h2><p>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' 相关项目推进中，设计院、总包与业主多方协同。</p>';
    $parts[] = '<!--pagebreak-->';
    $parts[] = '<h2>进展与交付（第 2 页）</h2>';
    for ($i = 1; $i <= 5; $i++) {
        $parts[] = '<p>段落 ' . $i . '：现场勘测、点表确认、FAT/SAT、培训与备件交付节点说明。演示正文可在后台继续加长。</p>';
    }
    $parts[] = '<!--pagebreak-->';
    $parts[] = '<h2>展望（第 3 页）</h2><p>后续将开放更多选型工具与渠道资料。欢迎联系应用工程师获取正式版本。</p>';

    return implode("\n", $parts);
};

$short = static function (string $lead): string {
    return '<p class="lead">' . $lead . '</p><p>本篇用于充实列表与分页，详情保持干净，不堆头图。</p>';
};

$rows = [];

// 新闻 021–036：多数无封面；约 1/3 有封面；部分长文分页
$newsExtra = [
    [21, '华东备件中心启用：常用膜片 48 小时发货', 'company', 1, 0],
    [22, '过程测控国标解读：精度与稳定性条款变化', 'industry', 0, 1],
    [23, '与某设计院签订框架选型合作备忘录', 'company', 0, 0],
    [24, '智能制造大会侧记：国产仪表的机会与挑战', 'industry', 1, 1],
    [25, 'HY 系列固件 v2.3 发布说明（摘要）', 'company', 0, 0],
    [26, '罐区安全仪表系统改造的三点经验', 'industry', 0, 1],
    [27, '华仪智控荣获省级专精特新企业认定', 'company', 1, 0],
    [28, '能源计量数字化：从点表到平台的路径', 'industry', 0, 0],
    [29, '开放日：客户走进校准实验室', 'company', 0, 1],
    [30, '进口替代项目复盘：交付周期如何压缩', 'industry', 1, 0],
    [31, '新版《选型手册》印刷与电子版同步上线', 'company', 0, 0],
    [32, '工业互联网标识解析在仪表台账中的应用', 'industry', 0, 1],
    [33, '华南服务中心扩编应用工程师团队', 'company', 1, 0],
    [34, '差压液位测量中的引压管敷设误区', 'industry', 0, 0],
    [35, '渠道政策更新说明（需登录查看全文）', 'company', 0, 1],
    [36, '年度质量月：零缺陷发货专项回顾', 'company', 1, 0],
];
foreach ($newsExtra as [$n, $title, $kind, $cover, $long]) {
    $html = 'pv-demo-news-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    $tag = 'pv-demo-news,pv-demo-news-' . $kind;
    $flags = $cover ? 'has_image' : '';
    $summary = $title . '。演示新闻列表分页与正文分页能力。';
    $content = $long ? $longNews($title, $title) : $short($title);
    $rows[] = [$html, $title, $tag, $flags, $summary, $content, 900 + $n * 17, max(1, 40 - $n)];
}

// 案例 017–024：覆盖更多行业；多数无列表大图（图集插件内仍有实拍图）
$cases = [
    [17, '制药纯化水系统压力监控改造', 0],
    [18, '冶金连铸冷却回路差压监测', 1],
    [19, '城市综合管廊环境监测', 0],
    [20, 'LNG 接收站温度多点采集', 0],
    [21, '纸浆漂白工段液位联锁', 1],
    [22, '机场加油管线压力巡检', 0],
    [23, '医院洁净空调压差监测', 0],
    [24, '矿山选矿药剂流量计量', 1],
];
foreach ($cases as [$n, $title, $cover]) {
    $html = 'pv-demo-gallery-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    $flags = $cover ? 'has_image' : '';
    $rows[] = [
        $html,
        $title,
        'pv-demo-gallery',
        $flags,
        $title . ' · 现场实拍图集演示。',
        $short($title . '。详情页以图集插件展示为主，避免重复大头图。'),
        700 + $n * 11,
        max(1, 30 - $n),
    ];
}

// 纠偏既有加灌：新闻 013–020 改为稀疏封面（重写 flags）
$newsRewrite = [
    [13, '华北服务中心扩容：48 小时上门校准覆盖京津冀', 'company', 1, 0],
    [14, '行业观察：国产测控仪表在石化改造中的占比持续上升', 'industry', 0, 1],
    [15, '华仪智控通过年度 ISO 9001 / 14001 监督审核', 'company', 0, 0],
    [16, '与某钢铁集团签署能源数据采集平台框架协议', 'company', 1, 1],
    [17, '技术分享会：HART / Modbus 混合现场的排障清单', 'industry', 0, 0],
    [18, '新品预告：HY-920 多通道温度采集模块即将上市', 'company', 0, 0],
    [19, '海外项目速递：中东某炼厂仪表成套交付完成', 'company', 1, 1],
    [20, '招聘：应用工程师 / 嵌入式软件工程师（上海·张江）', 'company', 0, 0],
];
$out = [];
foreach ($newsRewrite as [$n, $title, $kind, $cover, $long]) {
    $html = 'pv-demo-news-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    $out[] = [
        $html,
        $title,
        'pv-demo-news,pv-demo-news-' . $kind,
        $cover ? 'has_image' : '',
        $title,
        $long ? $longNews($title, $title) : $short($title),
        1000 + $n * 13,
        max(1, 25 - $n),
    ];
}
// 旧 download/gallery enrich 条目不再在此维护（改由插件 VolumeSeed / 案例下行）
foreach ($rows as $r) {
    $out[] = $r;
}

return $out;
