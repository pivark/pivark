<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
/**
 * Community 安装演示数据（华仪智控工业装备样板，主题 default = 发行包内 demo 门户）
 * 用法: php install/assets/seed/seed_community_demo.php（装完可删 install/）
 * 数据 SSOT: install/assets/seed/data/
 */
declare(strict_types=1);

$__root = dirname(__DIR__, 3);
require_once $__root . '/vendor/autoload.php';
$__migBoot = \app\common\support\ProjectPaths::migrationsBootstrapFile();
if (!is_readable($__migBoot)) {
    throw new \RuntimeException('缺少迁移引导');
}
require_once $__migBoot;
if (!\class_exists(\install\support\InstallSeedContext::class, false)) {
    require dirname(__DIR__, 3) . '/app/bootstrap/cli.php';
    pivark_app();
}

use install\support\InstallSeedContext;

$root = migration_project_root();
['pdo' => $pdo, 'pfx' => $pfx, 'db' => $db] = migration_bootstrap($root);

$dataDir = __DIR__ . '/data';

$demoDocCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$pfx}documents` WHERE `html_name` LIKE 'pv-demo-%'")->fetchColumn();
// 安装向导：插件 InstallDemo 往往已先灌 ≥10 篇 pv-demo-*；禁走增量（否则跳过产品 Tag + site_nav）
$forceFullSeed = InstallSeedContext::isActive()
    || (static function (): bool {
        $raw = $_ENV['PIVARK_INSTALL_WIZARD'] ?? $_SERVER['PIVARK_INSTALL_WIZARD'] ?? getenv('PIVARK_INSTALL_WIZARD');
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw)) {
            return $raw === 1;
        }
        if (!is_string($raw)) {
            return false;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    })();
$incrementalOnly = !$forceFullSeed && $demoDocCount >= 10;
$refreshNav = !$incrementalOnly;
$applyTheme = !$incrementalOnly;
$purgeLegacy = false;
$now        = date('Y-m-d H:i:s');
$tTags      = "`{$pfx}tags`";
$tGroups    = "`{$pfx}tag_groups`";
$tDocuments = "`{$pfx}documents`";
$tArtTags   = "`{$pfx}document_tags`";
$tNav       = "`{$pfx}site_nav`";
$tCfg       = "`{$pfx}configs`";
$tPages     = "`{$pfx}site_pages`";
$tSlides    = "`{$pfx}site_slides`";

$imagesCfg = require $dataDir . '/demo_showcase_images.php';
$imageMap  = $imagesCfg['map'];
$fallbackImg = $imageMap['default'] ?? ($imagesCfg['list_top'] ?? '');
$carouselImgs = $imagesCfg['carousel'];
$listTopImg   = $imagesCfg['list_top'];
$siteLogo     = $imagesCfg['logo'];

echo $incrementalOnly
    ? "=== seed_community_demo (incremental: documents + pages, pv-demo documents={$demoDocCount}) ===\n"
    : "=== seed_community_demo ===\n";

if ($incrementalOnly) {
    $tagIds = [];
    foreach ($pdo->query("SELECT slug, id FROM {$tTags} WHERE slug LIKE 'pv-demo-%'") as $row) {
        $tagIds[(string) $row['slug']] = (int) $row['id'];
    }
    // 增量路径仍须保证广告位 + 空轮播可补（否则前台 slot 无效 → 空轮播）
    $tAdSlots = "`{$pfx}site_ad_slots`";
    $pdo->exec(
        "INSERT INTO {$tAdSlots} (`code`,`name`,`remark`,`default_creative_type`,`sort`,`status`) VALUES
        ('home_carousel','首页轮播','首页顶部轮播区','carousel',1,1),
        ('home_hero','首页主图（单图）','首页单图 Banner','single_image',2,1),
        ('sidebar','侧栏条幅','列表/详情侧栏','single_image',3,1),
        ('list_top','列表页顶栏','频道列表顶部','single_image',4,1),
        ('footer_strip','页脚通栏','全站页脚通栏','single_image',5,1),
        ('popup','弹窗/浮层','营销弹窗或浮层','single_image',6,1)
        ON DUPLICATE KEY UPDATE `status`=VALUES(`status`)"
    );
    $hasSlotInc = migration_column_exists($db)($pdo, $pfx, 'site_slides', 'slot');
    $hcInc = $hasSlotInc
        ? (int) $pdo->query("SELECT COUNT(*) FROM {$tSlides} WHERE `slot` = 'home_carousel' AND `status` = 1")->fetchColumn()
        : (int) $pdo->query("SELECT COUNT(*) FROM {$tSlides} WHERE `status` = 1")->fetchColumn();
    if ($hcInc < 1) {
        if ($hasSlotInc) {
            $insSlideInc = $pdo->prepare("INSERT INTO {$tSlides} (`slot`,`creative_type`,`title`,`subtitle`,`image_url`,`link_url`,`link_text`,`sort`,`status`,`open_new_tab`,`created_at`,`updated_at`) VALUES ('home_carousel','carousel',?,?,?,?,?,?,1,0,?,?)");
        } else {
            $insSlideInc = $pdo->prepare("INSERT INTO {$tSlides} (`title`,`subtitle`,`image_url`,`link_url`,`link_text`,`sort`,`status`,`open_new_tab`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,1,0,?,?)");
        }
        foreach ([
            ['工业自动化与测控解决方案', '传感器 · 变送器 · 记录仪 · 系统集成', $carouselImgs[0], '/about', '了解我们', 1],
            ['面向流程工业的现场测控', '石化 · 电力 · 冶金 · 水处理 · 装备制造', $carouselImgs[1], '/news', '新闻动态', 2],
            ['产品选型与技术支持', '手册下载 · 安装指南 · 在线询价 · 售后响应', $carouselImgs[2], '/downloads', '资料下载', 3],
            ['典型行业应用案例', '从单机仪表到整厂测控改造的可交付经验', $carouselImgs[3], '/cases', '应用案例', 4],
        ] as $row) {
            $insSlideInc->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $now, $now]);
        }
        echo "  site_slides (home_carousel) OK (incremental fill)\n";
    }
    goto seed_community_demo_documents;
}

if ($purgeLegacy) {
    $argv = ['purge_demo_site_legacy.php'];
    include __DIR__ . '/purge_demo_site_legacy.php';
    echo "\n";
}

$cfg = [
    'site_name'        => '华仪智控',
    'site_title'       => '华仪智控 — 工业自动化与测控设备',
    'site_description' => '专注工业自动化、过程测控与智能装备，为制造企业提供可靠的产品、系统方案与全生命周期服务。',
    'site_keywords'    => '工业自动化,测控仪器,压力变送器,无纸记录仪,智能制造,华仪智控',
    'site_copyright'   => '© ' . date('Y') . ' 华仪智控股份有限公司',
    'site_phone'       => '400-800-6688',
    'site_email'       => 'service@huayi-ctrl.com',
    'site_address'     => '上海市浦东新区张江镇科苑路 888 号华仪智控大厦',
    'site_wechat_qr'   => '',
    'site_wechat_mp_qr'=> '',
    'site_map_enabled' => '1',
    'site_map_lat'     => '31.204',
    'site_map_lng'     => '121.588',
    'site_map_note'    => '地铁 2 号线张江高科站，步行约 12 分钟；驾车请导航至「科苑路 888 号华仪智控大厦」。',
    'site_icp'         => '沪ICP备2026008800号-1',
    'site_logo'        => $siteLogo,
    'site_logo_hero'   => (string) ($imagesCfg['logo_hero'] ?? ''),
    'payment_pay_mode' => 'demo',
    'favorite_open'    => '1',
    'favorite_guest'   => '1',
];
if ($applyTheme) {
    $cfg['site_theme'] = 'default';
}
foreach ($cfg as $key => $val) {
    $stmt = $pdo->prepare("SELECT `value` FROM {$tCfg} WHERE `key` = ? LIMIT 1");
    $stmt->execute([$key]);
    if ($stmt->fetchColumn() === false) {
        $pdo->prepare("INSERT INTO {$tCfg} (`group`,`key`,`value`,`type`,`created_at`,`updated_at`) VALUES ('system',?,?,?,NOW(),NOW())")
            ->execute([$key, $val, 'string']);
    } else {
        $pdo->prepare("UPDATE {$tCfg} SET `value`=?, `updated_at`=NOW() WHERE `key`=?")->execute([$val, $key]);
    }
}

require __DIR__ . '/lib_seed_contact_vars.php';
pivark_seed_contact_config($pdo, $tCfg, [
    'site_phone'        => (string) $cfg['site_phone'],
    'site_email'        => (string) $cfg['site_email'],
    'site_address'      => (string) $cfg['site_address'],
    'site_wechat_qr'    => (string) $cfg['site_wechat_qr'],
    'site_wechat_mp_qr' => (string) $cfg['site_wechat_mp_qr'],
], true);
echo "  contact custom vars OK\n";

$pdo->prepare("INSERT INTO {$tGroups} (`id`,`name`,`sort`,`status`,`created_at`,`updated_at`) VALUES (?,?,?,1,?,?)
    ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `updated_at`=VALUES(`updated_at`)")
    ->execute([10, '默认标签', 1, $now, $now]);

$tags = [
    ['pv-demo-news', '新闻动态', 'list_document_news.php', 1, 3, '公司新闻、行业动态与项目案例', 'news'],
    ['pv-demo-download', '资料下载', 'list_document_download.php', 1, 7, '产品手册、安装指南、资质文件与 CAD 图纸', 'downloads'],
    ['pv-demo-video', '产品视频', 'list_document_video.php', 1, 5, '产品介绍、操作指南与案例视频', 'video'],
    ['pv-demo-gallery', '应用案例', 'list_document_gallery.php', 1, 6, '典型行业现场与项目实施图集', 'cases'],
    ['pv-demo-product', '产品中心', 'list_document_product.php', 1, 4, '测控仪器、系统集成与配件耗材 — 华仪智控面向流程工业的全栈产品体系，支持按型号与参数组合检索。', ''],
    ['pv-demo-cat-digital', '测控仪器', 'list_document_product.php', 0, 0, '智能变送器、流量/液位仪表、无纸记录仪与温度采集模块 — 为石化、电力、冶金等现场提供可靠的过程测控与数据记录能力。', 'chanpin-yiqi'],
    ['pv-demo-cat-service', '系统集成', 'list_document_product.php', 0, 0, '过程监控 Turnkey、DCS/PLC 改造、能源数据采集平台与远程运维 — 从方案设计到 FAT/SAT 验收的一站式工程交付。', 'chanpin-jicheng'],
    ['pv-demo-cat-resource', '配件耗材', 'list_document_product.php', 0, 0, '膜片备件、通信线缆、校准工具与安装附件 — 原厂配套，保障 HY 系列仪表全生命周期稳定运行。', 'chanpin-peijian'],
];
if (InstallSeedContext::isActive()) {
    $allowedSlugs = array_fill_keys(InstallSeedContext::allowedTagSlugs(), true);
    $tags = array_values(array_filter(
        $tags,
        static fn (array $row): bool => isset($allowedSlugs[(string) ($row[0] ?? '')])
    ));
}
$tagCover = [
    'pv-demo-news' => $imageMap['pv-demo-news-001'] ?? $fallbackImg,
    'pv-demo-download' => $imageMap['pv-demo-download-01'] ?? $fallbackImg,
    'pv-demo-video' => $imageMap['pv-demo-video-01'] ?? $fallbackImg,
    'pv-demo-gallery' => $imageMap['pv-demo-gallery-01'] ?? $fallbackImg,
    'pv-demo-product' => $imageMap['pv-demo-product-01'] ?? $fallbackImg,
    'pv-demo-cat-digital' => $imageMap['pv-demo-product-01'] ?? $fallbackImg,
    'pv-demo-cat-service' => $imageMap['pv-demo-product-05'] ?? $fallbackImg,
    'pv-demo-cat-resource' => $imageMap['pv-demo-product-09'] ?? $fallbackImg,
];
$tagIds = [];
foreach ($tags as [$slug, $name, $tpl, $showNav, $navSort, $desc, $urlPath]) {
    $urlPath = trim((string) ($urlPath ?? ''));
    $cover = $tagCover[$slug] ?? $fallbackImg;
    $stmt = $pdo->prepare("SELECT id FROM {$tTags} WHERE slug = ? LIMIT 1");
    $stmt->execute([$slug]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        $pdo->prepare("UPDATE {$tTags} SET `name`=?, `group_id`=10, `nav_sort`=?, `description`=?, `seo_title`=?, `tpl_name`=?, `litpic`=?, `url_path`=?, `status`=1, `updated_at`=? WHERE id=?")
            ->execute([$name, $navSort, $desc, $name, $tpl, $cover, $urlPath, $now, (int) $existing]);
        $tagIds[$slug] = (int) $existing;
    } else {
        $pdo->prepare("INSERT INTO {$tTags} (`name`,`slug`,`kind`,`group_id`,`description`,`seo_title`,`tpl_name`,`litpic`,`url_path`,`status`,`nav_sort`,`use_count`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,1,?,0,?,?)")
            ->execute([$name, $slug, 'topic', 10, $desc, $name, $tpl, $cover, $urlPath, $navSort, $now, $now]);
        $tagIds[$slug] = (int) $pdo->lastInsertId();
    }
}
$hubId = (int) ($tagIds['pv-demo-product'] ?? 0);
if ($hubId > 0) {
    $updParent = $pdo->prepare("UPDATE {$tTags} SET parent_id = ? WHERE slug = ? AND id <> ?");
    foreach (['pv-demo-cat-digital', 'pv-demo-cat-service', 'pv-demo-cat-resource'] as $childSlug) {
        $updParent->execute([$hubId, $childSlug, $hubId]);
    }
}
echo "  tags OK\n";

if (!$refreshNav) {
    echo "  site_nav skipped (pass --refresh-nav or --full to reset)\n";
} else {
$pdo->exec("DELETE FROM {$tNav}");
// id,parent_id,title,nav_type,target,sort,content_kind,url_path,tpl_name（真栏目字段，勿只插 type/target）
$navRows = [
        [1, 0, '网站首页', 'route', '/', 1, 'home', '', ''],
        [2, 0, '关于我们', 'page', 'about', 2, 'page', '', ''],
        [7, 0, '产品', 'page', 'chanpin', 3, 'product', '', ''],
        [71, 7, '测控仪器', 'route', '/chanpin-yiqi', 1, 'product', 'chanpin-yiqi', 'list_document_product.php'],
        [72, 7, '系统集成', 'route', '/chanpin-jicheng', 2, 'product', 'chanpin-jicheng', 'list_document_product.php'],
        [73, 7, '配件耗材', 'route', '/chanpin-peijian', 3, 'product', 'chanpin-peijian', 'list_document_product.php'],
        [3, 0, '新闻动态', 'route', '/news', 4, 'document', 'news', 'list_document_news.php'],
        [6, 0, '应用案例', 'route', '/cases', 5, 'document', 'cases', 'list_document_gallery.php'],
        [5, 0, '产品视频', 'route', '/video', 6, 'document', 'video', 'list_document_video.php'],
        [4, 0, '资料下载', 'route', '/downloads', 7, 'document', 'downloads', 'list_document_download.php'],
        [8, 0, '联系我们', 'page', 'contact', 8, 'page', '', ''],
];
// 真分类门牌已是 route+url_path；装机精简不再按 Tag slug 裁栏目（Tag 种子仍走 allowedTagSlugs）
$ins = $pdo->prepare(
    "INSERT INTO {$tNav} (`id`,`parent_id`,`title`,`nav_type`,`target`,`content_kind`,`url_path`,`tpl_name`,`sort`,`status`,`open_new_tab`,`created_at`,`updated_at`)
     VALUES (?,?,?,?,?,?,?,?,?,1,0,?,?)"
);
foreach ($navRows as $row) {
    $ins->execute([
        $row[0], $row[1], $row[2], $row[3], $row[4],
        $row[6], $row[7], $row[8], $row[5],
        $now, $now,
    ]);
}
echo "  site_nav OK (content_kind/url_path/tpl_name)\n";
}

// 广告位默认行（与 init_db 一致；演示灌种前必须存在，否则 listPublic 因 slot 无效返回空）
$tAdSlots = "`{$pfx}site_ad_slots`";
$pdo->exec(
    "INSERT INTO {$tAdSlots} (`code`,`name`,`remark`,`default_creative_type`,`sort`,`status`) VALUES
    ('home_carousel','首页轮播','首页顶部轮播区','carousel',1,1),
    ('home_hero','首页主图（单图）','首页单图 Banner','single_image',2,1),
    ('sidebar','侧栏条幅','列表/详情侧栏','single_image',3,1),
    ('list_top','列表页顶栏','频道列表顶部','single_image',4,1),
    ('footer_strip','页脚通栏','全站页脚通栏','single_image',5,1),
    ('popup','弹窗/浮层','营销弹窗或浮层','single_image',6,1)
    ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `status`=VALUES(`status`)"
);
echo "  site_ad_slots OK\n";

// home_carousel：全量刷新或表空时灌种（增量路径不得永久跳过导致前台空轮播）
$hasSlot = migration_column_exists($db)($pdo, $pfx, 'site_slides', 'slot');
$homeCarouselCount = $hasSlot
    ? (int) $pdo->query("SELECT COUNT(*) FROM {$tSlides} WHERE `slot` = 'home_carousel' AND `status` = 1")->fetchColumn()
    : (int) $pdo->query("SELECT COUNT(*) FROM {$tSlides} WHERE `status` = 1")->fetchColumn();
$seedHomeCarousel = $refreshNav || $homeCarouselCount < 1;
if ($seedHomeCarousel) {
    if ($refreshNav) {
        $pdo->exec("DELETE FROM {$tSlides}");
    } elseif ($hasSlot) {
        $pdo->exec("DELETE FROM {$tSlides} WHERE `slot` = 'home_carousel'");
    } else {
        $pdo->exec("DELETE FROM {$tSlides}");
    }
    if ($hasSlot) {
        $insSlide = $pdo->prepare("INSERT INTO {$tSlides} (`slot`,`creative_type`,`title`,`subtitle`,`image_url`,`link_url`,`link_text`,`sort`,`status`,`open_new_tab`,`created_at`,`updated_at`) VALUES ('home_carousel','carousel',?,?,?,?,?,?,1,0,?,?)");
    } else {
        $insSlide = $pdo->prepare("INSERT INTO {$tSlides} (`title`,`subtitle`,`image_url`,`link_url`,`link_text`,`sort`,`status`,`open_new_tab`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,1,0,?,?)");
    }
    foreach ([
        ['工业自动化与测控解决方案', '传感器 · 变送器 · 记录仪 · 系统集成', $carouselImgs[0], '/about', '了解我们', 1],
        ['面向流程工业的现场测控', '石化 · 电力 · 冶金 · 水处理 · 装备制造', $carouselImgs[1], '/news', '新闻动态', 2],
        ['产品选型与技术支持', '手册下载 · 安装指南 · 在线询价 · 售后响应', $carouselImgs[2], '/downloads', '资料下载', 3],
        ['典型行业应用案例', '从单机仪表到整厂测控改造的可交付经验', $carouselImgs[3], '/cases', '应用案例', 4],
    ] as $row) {
        $insSlide->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $now, $now]);
    }
    echo "  site_slides (home_carousel) OK\n";
    if ($hasSlot) {
        $pdo->prepare("DELETE FROM {$tSlides} WHERE `slot` = 'list_top'")->execute();
        echo "  site_slides (list_top cleared for demo channel hero) OK\n";
    }
} else {
    echo "  site_slides skip (home_carousel already={$homeCarouselCount})\n";
}

$tLinks = "`{$pfx}site_links`";
$pdo->exec("DELETE FROM {$tLinks}");
$insLink = $pdo->prepare("INSERT INTO {$tLinks} (`title`,`url`,`logo_url`,`sort`,`status`,`open_new_tab`,`created_at`,`updated_at`) VALUES (?,?,?,?,1,1,?,?)");
$partnerLogo = static fn (string $file): string => '/uploads/demo-seed/partners/' . $file;
foreach ([
    ['中国移动', 'https://www.10086.cn', $partnerLogo('china-mobile.svg'), 1],
    ['中国电信', 'https://www.chinatelecom.com.cn', $partnerLogo('china-telecom.svg'), 2],
    ['中国联通', 'https://www.chinaunicom.com.cn', $partnerLogo('china-unicom.svg'), 3],
    ['华为', 'https://www.huawei.com', $partnerLogo('huawei.svg'), 4],
    ['国家电网', 'https://www.sgcc.com.cn', $partnerLogo('sgcc.svg'), 5],
    ['中国石化', 'https://www.sinopec.com', $partnerLogo('sinopec.svg'), 6],
    ['中国石油', 'https://www.cnpc.com.cn', $partnerLogo('cnpc.svg'), 7],
    ['中国建筑', 'https://www.cscec.com', $partnerLogo('cscec.svg'), 8],
    ['中兴通讯', 'https://www.zte.com.cn', $partnerLogo('zte.svg'), 9],
    ['中国自动化学会', 'https://www.caa.org.cn', $partnerLogo('caa.svg'), 10],
] as [$title, $url, $logo, $sort]) {
    $insLink->execute([$title, $url, $logo, $sort, $now, $now]);
}
echo "  site_links (logo+text) OK\n";

seed_community_demo_documents:

$upsertDocument = static function (
    PDO $pdo,
    string $tDocuments,
    string $htmlName,
    string $title,
    string $summary,
    string $content,
    string $litpic = '',
    string $attrFlags = 'has_image',
    int $click = 0,
    int $daysAgo = 0
): int {
    $stmt = $pdo->prepare("SELECT id FROM {$tDocuments} WHERE html_name = ? LIMIT 1");
    $stmt->execute([$htmlName]);
    $did = $stmt->fetchColumn();
    $click = $click > 0 ? $click : random_int(100, 800);
    $ts    = strtotime('-' . max(0, $daysAgo) . ' days');
    $pub   = date('Y-m-d H:i:s', $ts !== false ? $ts : time());
    $now   = date('Y-m-d H:i:s');
    if (!$did) {
        $pdo->prepare("INSERT INTO {$tDocuments}
            (`title`,`summary`,`content`,`html_name`,`litpic`,`attr_flags`,`status`,`author_id`,`author_name`,`click`,`published_at`,`seo_title`,`seo_description`,`created_at`,`updated_at`)
            VALUES (?,?,?,?,?,?,1,1,'华仪智控',?,?,?,?,?,?)")
            ->execute([$title, $summary, $content, $htmlName, $litpic, $attrFlags, $click, $pub, $title, $summary, $pub, $now]);
        return (int) $pdo->lastInsertId();
    }
    $pdo->prepare("UPDATE {$tDocuments} SET `title`=?, `summary`=?, `content`=?, `litpic`=?, `attr_flags`=?, `click`=?, `published_at`=?, `updated_at`=? WHERE id=?")
        ->execute([$title, $summary, $content, $litpic, $attrFlags, $click, $pub, $now, (int) $did]);
    return (int) $did;
};

$linkTags = static function (PDO $pdo, string $tArtTags, int $documentId, array $slugs, array $tagIds, string $now): void {
    foreach ($slugs as $slug) {
        if (!isset($tagIds[$slug])) {
            continue;
        }
        $tid = $tagIds[$slug];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tArtTags} WHERE document_id = ? AND tag_id = ?");
        $stmt->execute([$documentId, $tid]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO {$tArtTags} (`document_id`,`tag_id`,`created_at`) VALUES (?,?,?)")
                ->execute([$documentId, $tid, $now]);
        }
    }
};

$docBatch = require $dataDir . '/demo_showcase_documents.php';
$docEnrichPath = $dataDir . '/demo_showcase_documents_enrich.php';
if (is_file($docEnrichPath)) {
    $docEnrich = require $docEnrichPath;
    if (is_array($docEnrich)) {
        $docBatch = array_merge($docBatch, $docEnrich);
    }
}
$imgEnrichPath = $dataDir . '/demo_showcase_images_enrich.php';
if (is_file($imgEnrichPath)) {
    $imgEnrich = require $imgEnrichPath;
    if (is_array($imgEnrich['map'] ?? null)) {
        $imageMap = array_merge($imageMap, $imgEnrich['map']);
    }
}
$docCount = 0;
foreach ($docBatch as $row) {
    if (!is_array($row) || count($row) < 8) {
        continue;
    }
    [$html, $title, $tagCsv, $flags, $summary, $content, $click, $daysAgo] = $row;
    $docTags = array_values(array_filter(array_map('trim', explode(',', (string) $tagCsv))));
    if (InstallSeedContext::isActive() && !InstallSeedContext::allowsDocumentTags($docTags)) {
        continue;
    }
    $wantCover = str_contains((string) $flags, 'has_image');
    $litpic = $wantCover ? ($imageMap[$html] ?? $fallbackImg) : '';
    $did = $upsertDocument($pdo, $tDocuments, $html, $title, $summary, $content, $litpic, $flags, (int) $click, (int) $daysAgo);
    $linkTags($pdo, $tArtTags, $did, explode(',', $tagCsv), $tagIds, $now);
    $docCount++;
}
echo "  documents {$docCount} OK\n";


$aboutHtml = require $dataDir . '/demo_about_content.php';

$faqHtml = <<<'HTML'
<h3>如何选型压力/差压变送器？</h3>
<p>请确认介质、量程、精度等级、过程温度、防爆分区与输出协议（4~20 mA+HART 或 Modbus）。详细参数表见资料下载中的《HY-810 选型手册》。</p>
<h3>是否提供现场校准与售后？</h3>
<p>华北、华东、华南设有服务中心，可预约上门校准、故障诊断与年度巡检。全国统一热线 400-800-6688（7×12 小时）。</p>
<h3>订货与交付周期？</h3>
<p>常规型号 3~7 个工作日发货；定制量程或防爆规格以合同交期为准。大宗项目可签署框架协议分批供货。</p>
HTML;

$privacyHtml = <<<'HTML'
<p>华仪智控股份有限公司重视用户隐私。您在本站表单、在线咨询中提交的信息，仅用于业务联系、方案沟通与售后服务，不会出售给第三方。</p>
<p>我们可能记录访问日志（IP、浏览器类型）用于安全与统计，保留期限不超过 12 个月。如需查询、更正或删除个人信息，请邮件联系 service@huayi-ctrl.com。</p>
HTML;

$termsHtml = <<<'HTML'
<p>访问华仪智控网站即表示您同意合法使用本站内容与资料。产品手册、软件及文档著作权归华仪智控或权利人所有，未经授权不得复制用于商业目的。</p>
<p>本站产品信息仅供参考，最终以合同与技术协议为准。因不可抗力或网络故障导致的服务中断，我们将尽力恢复但不承担间接损失。</p>
HTML;

$upsertPage = static function (PDO $pdo, string $tPages, $db, string $pfx, string $title, string $path, string $tpl, string $seo, string $content, string $now, string $seoDescription = ''): void {
    $seoDescription = trim($seoDescription) !== '' ? trim($seoDescription) : mb_substr(strip_tags($content), 0, 200);
    $stmt = $pdo->prepare("SELECT id FROM {$tPages} WHERE path = ? LIMIT 1");
    $stmt->execute([$path]);
    $pageId = $stmt->fetchColumn();
    $hasContentCol = migration_column_exists($db)($pdo, $pfx, 'site_pages', 'content');
    if (!$pageId) {
        if ($hasContentCol) {
            $pdo->prepare("INSERT INTO {$tPages} (`title`,`path`,`tpl_name`,`content`,`seo_title`,`seo_description`,`status`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,1,?,?)")
                ->execute([$title, $path, $tpl, $content, $seo, $seoDescription, $now, $now]);
        } else {
            $pdo->prepare("INSERT INTO {$tPages} (`title`,`path`,`tpl_name`,`seo_title`,`status`,`created_at`,`updated_at`) VALUES (?,?,?,?,1,?,?)")
                ->execute([$title, $path, $tpl, $seo, $now, $now]);
        }
    } elseif ($hasContentCol) {
        $pdo->prepare("UPDATE {$tPages} SET `title`=?, `path`=?, `tpl_name`=?, `content`=?, `seo_title`=?, `seo_description`=?, `status`=1, `updated_at`=? WHERE id=?")
            ->execute([$title, $path, $tpl, $content, $seo, $seoDescription, $now, (int) $pageId]);
    } else {
        $pdo->prepare("UPDATE {$tPages} SET `title`=?, `path`=?, `tpl_name`=?, `seo_title`=?, `status`=1, `updated_at`=? WHERE id=?")
            ->execute([$title, $path, $tpl, $seo, $now, (int) $pageId]);
    }
};

$pdo->prepare("UPDATE {$tPages} SET `status` = 0, `updated_at` = ? WHERE path = 'pricing'")->execute([$now]);

$productHubHtml = require $dataDir . '/demo_product_hub_content.php';

foreach ([
    ['关于我们', 'about', 'list_page_about', '关于我们 — 华仪智控', $aboutHtml, '专注工业自动化与过程测控 · 为石化、电力、冶金等行业提供可靠仪表与系统方案'],
    ['联系我们', 'contact', 'list_page_contact', '联系我们 — 华仪智控', '<p class="lead mb-0">欢迎留下您的需求与联系方式，我们的应用工程师将在 <strong>1 个工作日</strong>内回复。也可直接拨打全国统一服务热线 <strong>400-800-6688</strong>（7×12 小时），或发送邮件至 <a href="mailto:service@huayi-ctrl.com">service@huayi-ctrl.com</a>。</p>', '产品咨询 · 方案评估 · 商务合作 · 技术支持 — 1 个工作日内回复'],
    ['常见问题', 'faq', 'list_page', '常见问题 — 华仪智控', $faqHtml, '产品选型、交付周期、售后与校准等常见疑问'],
    ['隐私政策', 'privacy', 'list_page', '隐私政策 — 华仪智控', $privacyHtml, '我们如何收集、使用与保护您的个人信息'],
    ['服务条款', 'terms', 'list_page', '服务条款 — 华仪智控', $termsHtml, '网站使用规则与免责声明'],
    ['产品', 'chanpin', 'list_page_products', '产品 — 华仪智控', $productHubHtml, '在售型号一览，支持按分类、输出信号与精度等级筛选检索'],
] as [$title, $path, $tpl, $seo, $content, $heroDesc]) {
    $upsertPage($pdo, $tPages, $db, $pfx, $title, $path, $tpl, $seo, $content, $now, $heroDesc);
}
echo "  site_pages (about/contact/faq/…) OK\n";

$pdo->exec("UPDATE {$tTags} t SET t.use_count = (SELECT COUNT(*) FROM {$tArtTags} at WHERE at.tag_id = t.id)");

// 迁移阶段可能早于灌文；种子结束后再幂等回填 nav_id（与 migrate_content_nav_id_backfill 同库）
$siteRoot = dirname(__DIR__, 3);
$backfillLib = __DIR__ . '/lib_content_nav_id_backfill.php';
if (is_file($backfillLib)) {
    require_once $backfillLib;
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "  post-seed content nav_id backfill…\n";
    pivark_content_nav_id_backfill($pdo, $pfx, $dbName);
} else {
    echo "  WARN post-seed backfill lib missing\n";
}

// 默认联系表单（SSOT：app/.../fixtures/default_contact_form.php；幂等仅缺失时插入）
$contactFixture = __DIR__ . '/data/default_contact_form.php';
if (is_file($contactFixture)) {
    $fixture = require $contactFixture;
    $slug = (string) ($fixture['slug'] ?? 'contact');
    $tForms = "`{$pfx}forms`";
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM {$tForms} WHERE slug=" . $pdo->quote($slug))->fetchColumn();
    if ($exists < 1) {
        $pdo->prepare(
            "INSERT INTO {$tForms} (`slug`,`title`,`fields_json`,`settings_json`,`status`,`sort`,`created_at`,`updated_at`)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $slug,
            (string) ($fixture['title'] ?? '在线留言'),
            json_encode($fixture['fields'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($fixture['settings'] ?? [], JSON_UNESCAPED_UNICODE),
            (int) ($fixture['status'] ?? 1),
            (int) ($fixture['sort'] ?? 0),
            $now,
            $now,
        ]);
        echo "  default contact form OK\n";
    } else {
        echo "  default contact form skip (exists)\n";
    }
} else {
    echo "  WARN contact form fixture missing\n";
}

echo "=== seed_community_demo done ===\n";
if ($applyTheme) {
    echo "已设置 site_theme=default\n";
}
if (\class_exists(\app\common\service\site\SiteAdSlotService::class, false)
    || \class_exists(\app\common\service\site\SiteAdSlotService::class)) {
    try {
        app(\app\common\service\infra\MetaSqlCacheService::class)->clearFrontMeta();
        app(\app\common\service\site\SiteAdSlotService::class)->bustPublicCache();
        app(\app\common\service\site\SiteSlideService::class); // ensure class loaded
        $ref = new \ReflectionClass(\app\common\service\site\SiteSlideService::class);
        if ($ref->hasProperty('listPublicCache')) {
            $p = $ref->getProperty('listPublicCache');
            $p->setAccessible(true);
            $p->setValue(null, []);
        }
    } catch (\Throwable) {
        // ignore
    }
}
