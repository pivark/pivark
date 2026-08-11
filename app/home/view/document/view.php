<?php
use app\common\support\SiteUrl;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($document_title ?? '') ?> - PivArk</title>
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
        .detail-container { padding: 40px 0; }
        .detail-content { background: #fff; padding: 40px; border-radius: 10px; border: 1px solid var(--border-color); }
        .detail-content h1 { font-size: 28px; color: var(--primary-color); margin-bottom: 20px; font-weight: 600; }
        .detail-content .meta { margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color); }
        .detail-content .meta span { color: var(--text-muted); font-size: 14px; margin-right: 20px; }
        .detail-content .meta span i { margin-right: 5px; }
        .detail-content .content { color: var(--text-color); line-height: 1.9; font-size: 16px; }
        .detail-content .content p { margin-bottom: 20px; }
        .detail-content .content img { max-width: 100%; height: auto; border-radius: 8px; margin: 15px 0; }
        .detail-content .content h2 { font-size: 24px; color: var(--primary-color); margin: 30px 0 15px; font-weight: 600; }
        .detail-content .content h3 { font-size: 20px; color: var(--primary-color); margin: 25px 0 12px; font-weight: 600; }
        .detail-content .content ul, .detail-content .content ol { margin: 15px 0; padding-left: 30px; }
        .detail-content .content li { margin-bottom: 8px; }
        .tags-section { margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color); }
        .tags-section h4 { font-size: 16px; color: var(--primary-color); margin-bottom: 15px; font-weight: 600; }
        .page-nav { margin-top: 30px; padding: 20px; background: #fff; border-radius: 10px; border: 1px solid var(--border-color); }
        .page-nav .nav-item { display: flex; justify-content: space-between; align-items: center; }
        .page-nav .nav-item a { color: var(--secondary-color); text-decoration: none; }
        .page-nav .nav-item a:hover { text-decoration: underline; }
        .related-articles { margin-top: 30px; padding: 30px; background: #fff; border-radius: 10px; border: 1px solid var(--border-color); }
        .related-articles h3 { font-size: 20px; color: var(--primary-color); margin-bottom: 20px; font-weight: 600; }
        .related-articles ul { list-style: none; padding: 0; }
        .related-articles li { margin-bottom: 12px; }
        .related-articles li a { color: var(--text-color); text-decoration: none; transition: color 0.3s; }
        .related-articles li a:hover { color: var(--secondary-color); }
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
                    <li class="breadcrumb-item"><a href="<?= SiteUrl::documents() ?>">文档中心</a></li>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($document_title ?? '') ?></li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- Detail Container -->
    <div class="detail-container">
        <div class="container">
            <div class="detail-content">
                <h1 class="<?= htmlspecialchars($document_title_class ?? '') ?>"><?= htmlspecialchars($document_title ?? '') ?></h1>
                <div class="meta">
                    <span><i class="fas fa-calendar"></i> <?= htmlspecialchars($document_date ?? '') ?></span>
                    <span><i class="fas fa-eye"></i> <?= (int) ($document_click ?? 0) ?></span>
                    <?php if (!empty($document_author)): ?>
                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($document_author) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($document_attr_label_text)): ?>
                    <span><i class="fas fa-tag"></i> <?= htmlspecialchars($document_attr_label_text) ?></span>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($document_litpic)): ?>
                <img src="<?= htmlspecialchars($document_litpic) ?>" alt="" class="img-fluid mb-4">
                <?php endif; ?>
                
                <div class="content">
                    <?= $document_content ?? '' ?>
                </div>
                
                <?php if (!empty($document_tags)): ?>
                <div class="tags-section">
                    <h4>相关标签</h4>
                    <div>
                        <?php foreach ($document_tags as $t): ?>
                        <a class="badge bg-primary me-1" href="<?= htmlspecialchars($t['url'] ?? SiteUrl::tag((string) ($t['slug'] ?? ''))) ?>">
                            <?= htmlspecialchars((string) ($t['name'] ?? '')) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Previous/Next Navigation -->
            <div class="page-nav">
                <div class="row">
                    <div class="col-md-6">
                        <span class="label text-muted">上一篇</span>
                        <?php if (!empty($prev_document)): ?>
                        <a href="<?= htmlspecialchars($prev_document['url']) ?>"><?= htmlspecialchars($prev_document['title']) ?></a>
                        <?php else: ?>
                        <span class="text-muted">没有了</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 text-end">
                        <span class="label text-muted">下一篇</span>
                        <?php if (!empty($next_document)): ?>
                        <a href="<?= htmlspecialchars($next_document['url']) ?>"><?= htmlspecialchars($next_document['title']) ?></a>
                        <?php else: ?>
                        <span class="text-muted">没有了</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Related Articles -->
            <?php if (!empty($related_documents)): ?>
            <div class="related-articles">
                <h3>相关推荐</h3>
                <ul>
                    <?php foreach ($related_documents as $item): ?>
                    <li>
                        <a href="<?= htmlspecialchars($item['url']) ?>"><?= htmlspecialchars($item['title']) ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
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