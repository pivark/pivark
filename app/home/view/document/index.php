<?php
use app\common\support\SiteUrl;
$tagSlug = $tagSlug ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= !empty($tag) ? '标签：' . htmlspecialchars((string) $tag) . ' - ' : '' ?>文档中心 - PivArk</title>
    <meta name="description" content="<?= htmlspecialchars((string) ($seo_description ?? '')) ?>">
    <meta name="keywords" content="<?= htmlspecialchars((string) ($seo_keywords ?? '')) ?>">
    <link rel="stylesheet" href="/static/common/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --light-bg: #f8f9fa;
            --text-color: #333;
            --text-muted: #7f8c8d;
            --border-color: #eee;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Microsoft YaHei', sans-serif; line-height: 1.6; color: var(--text-color); background-color: var(--light-bg); }
        header { position: fixed; width: 100%; top: 0; z-index: 999; background: linear-gradient(135deg, var(--primary-color) 0%, #34495e 100%); }
        header .navbar { padding: 0; }
        header .navbar-brand img { height: 45px; width: auto; }
        header .navbar-nav .nav-link { color: rgba(255, 255, 255, 0.9) !important; font-size: 15px; padding: 15px 20px !important; transition: all 0.3s; }
        header .navbar-nav .nav-link:hover { color: var(--secondary-color) !important; }
        .breadcrumb { background: var(--light-bg); padding: 10px 0; margin-top: 70px; }
        .breadcrumb .breadcrumb-item a { color: var(--secondary-color); text-decoration: none; }
        .breadcrumb .breadcrumb-item.active { color: var(--text-muted); }
        .list-container { padding: 40px 0; }
        .list-sidebar { background: #fff; padding: 25px; border-radius: 10px; border: 1px solid var(--border-color); }
        .list-sidebar h3 { font-size: 18px; color: var(--primary-color); margin-bottom: 20px; font-weight: 600; padding-bottom: 15px; border-bottom: 2px solid var(--secondary-color); }
        .list-sidebar ul { list-style: none; padding: 0; }
        .list-sidebar ul li { margin-bottom: 10px; }
        .list-sidebar ul li a { color: var(--text-color); text-decoration: none; padding: 8px 12px; display: block; border-radius: 5px; transition: all 0.3s; }
        .list-sidebar ul li a:hover, .list-sidebar ul li a.active { background: var(--secondary-color); color: #fff; }
        .list-item { background: #fff; padding: 25px; margin-bottom: 20px; border-radius: 10px; border: 1px solid var(--border-color); transition: all 0.3s; }
        .list-item:hover { box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05); }
        .list-item h3 { font-size: 20px; margin-bottom: 15px; }
        .list-item h3 a { color: var(--primary-color); text-decoration: none; transition: color 0.3s; }
        .list-item h3 a:hover { color: var(--secondary-color); }
        .list-item .meta { margin-bottom: 15px; }
        .list-item .meta span { color: var(--text-muted); font-size: 14px; margin-right: 20px; }
        .list-item .meta span i { margin-right: 5px; }
        .list-item p { color: var(--text-muted); line-height: 1.8; }
        .pagination { justify-content: center; margin-top: 30px; }
        .pagination .page-item .page-link { color: var(--primary-color); border: none; margin: 0 5px; border-radius: 50px !important; padding: 10px 18px; transition: all 0.3s; }
        .pagination .page-item.active .page-link { background: var(--secondary-color); border-color: var(--secondary-color); }
        .pagination .page-item .page-link:hover { background: var(--secondary-color); color: #fff; }
        footer { background: var(--primary-color); padding: 60px 0 30px; color: #fff; }
        footer h4 { font-size: 18px; margin-bottom: 20px; font-weight: 600; }
        footer ul { list-style: none; padding: 0; }
        footer ul li { margin-bottom: 10px; }
        footer ul li a { color: rgba(255, 255, 255, 0.7); text-decoration: none; transition: color 0.3s; }
        footer ul li a:hover { color: var(--secondary-color); }
        .footer-bottom { text-align: center; padding-top: 30px; border-top: 1px solid rgba(255, 255, 255, 0.1); margin-top: 30px; color: rgba(255, 255, 255, 0.6); }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <nav class="navbar navbar-expand-lg">
            <div class="container">
                <a class="navbar-brand" href="<?= SiteUrl::home() ?>">
                    <img src="https://trae-api-cn.mchost.guru/api/ide/v1/text_to_image?prompt=modern%20company%20logo%20design%20simple%20clean%20blue%20tech&image_size=square" alt="PivArk">
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="<?= SiteUrl::home() ?>">首页</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="<?= SiteUrl::documents() ?>">文档中心</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?= SiteUrl::tags() ?>">标签云</a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
    </header>

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= SiteUrl::home() ?>">首页</a></li>
                    <li class="breadcrumb-item active">文档中心</li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- List Container -->
    <div class="list-container">
        <div class="container">
            <div class="row">
                <div class="col-lg-3">
                    <div class="list-sidebar">
                        <h3>文档分类</h3>
                        <ul>
                            <li><a href="<?= SiteUrl::documents() ?>" class="<?= $tagSlug === '' ? 'active' : '' ?>">全部文档</a></li>
                            <li><a href="<?= SiteUrl::tags() ?>">标签云</a></li>
                            <?php foreach ($tags_nav ?? [] as $t): ?>
                            <li>
                                <a href="<?= SiteUrl::tag((string) $t['slug']) ?>"
                                   class="<?= $tagSlug === ($t['slug'] ?? '') ? 'active' : '' ?>">
                                    <?= htmlspecialchars((string) $t['name']) ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                
                <div class="col-lg-9">
                    <h2 class="page-title mb-4"><?= !empty($tag) ? '标签：' . htmlspecialchars((string) $tag) : '全部文档' ?></h2>
                    
                    <?php if (empty($list)): ?>
                    <div class="text-center py-5 bg-white rounded-lg">
                        <p class="text-muted">暂无文档</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($list as $item): ?>
                    <div class="list-item">
                        <h3><a href="<?= htmlspecialchars($item['url']) ?>"><?= htmlspecialchars($item['title']) ?></a></h3>
                        <div class="meta">
                            <span><i class="fas fa-calendar"></i> <?= htmlspecialchars($item['create_date'] ?? '') ?></span>
                            <span><i class="fas fa-eye"></i> <?= (int) ($item['click'] ?? 0) ?></span>
                        </div>
                        <p><?= htmlspecialchars(mb_substr((string) ($item['excerpt'] ?? ''), 0, 200)) ?></p>
                        <?php if (!empty($item['tags'])): ?>
                        <div class="tags mt-3">
                            <?php foreach ($item['tags'] as $t): ?>
                            <a class="badge bg-primary me-1" href="<?= htmlspecialchars($t['url'] ?? SiteUrl::tag((string) ($t['slug'] ?? ''))) ?>">
                                <?= htmlspecialchars((string) ($t['name'] ?? '')) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($pagination)): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <?php foreach ($pagination as $p): ?>
                            <li class="page-item <?= $p['active'] ? 'active' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars($p['url']) ?>"><?= $p['label'] ?></a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-3 mb-4">
                    <h4>关于我们</h4>
                    <ul>
                        <li><a href="#">公司介绍</a></li>
                        <li><a href="#">团队成员</a></li>
                        <li><a href="#">发展历程</a></li>
                        <li><a href="#">联系我们</a></li>
                    </ul>
                </div>
                <div class="col-md-3 mb-4">
                    <h4>产品服务</h4>
                    <ul>
                        <li><a href="#">功能介绍</a></li>
                        <li><a href="#">价格方案</a></li>
                        <li><a href="<?= SiteUrl::documents() ?>">文档中心</a></li>
                        <li><a href="<?= SiteUrl::tags() ?>">标签云</a></li>
                    </ul>
                </div>
                <div class="col-md-3 mb-4">
                    <h4>帮助支持</h4>
                    <ul>
                        <li><a href="#">帮助文档</a></li>
                        <li><a href="#">开发文档</a></li>
                        <li><a href="#">常见问题</a></li>
                        <li><a href="#">社区论坛</a></li>
                    </ul>
                </div>
                <div class="col-md-3 mb-4">
                    <h4>关注我们</h4>
                    <ul>
                        <li><a href="#"><i class="fab fa-weixin"></i> 微信公众号</a></li>
                        <li><a href="#"><i class="fab fa-qq"></i> QQ交流群</a></li>
                        <li><a href="#"><i class="fab fa-github"></i> GitHub</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>© 2026 PivArk. 保留所有权利.</p>
            </div>
        </div>
    </footer>

    <script src="/static/common/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>