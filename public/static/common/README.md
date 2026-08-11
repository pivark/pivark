# 内核静态资源（只读）

本目录由 PivArk 内核维护，升级时会被覆盖。请勿手改业务站主题目录来「顶替」此处。

| 子目录 | 用途 |
|--------|------|
| `js/` | L1 前台脚本（pv-urls、收藏、表单、统计、悬浮联系等） |
| `css/` | L1 前台样式 |
| `fonts/` | 内核 TTF（如 captcha.ttf） |
| `vendor/` | **全站共用第三方库**（门户主题模板可直接写死路径引用） |

内核自有脚本 URL：`PvPublicAsset::js()` / `PvPublicAsset::css()`。

---

## vendor/ — 前台模板直接引用

约定路径：

```text
/static/common/vendor/{库名}/...
```

模板里写死即可，**不必**再包 `{$vendor_*}` 标签。主题小众插件（如 PrintArea、countup）继续放在主题自己的 `assets/lib/`，不要塞进内核。

### Bootstrap 5.3.8

```html
<link href="/static/common/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<script src="/static/common/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
```

### Bootstrap Icons 1.11.3

```html
<link href="/static/common/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
```

### Font Awesome Free 6.4.0

```html
<link href="/static/common/vendor/fontawesome/css/all.min.css" rel="stylesheet">
```

### Swiper 11.0.3

```html
<link href="/static/common/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">
<script src="/static/common/vendor/swiper/swiper-bundle.min.js"></script>
```

### jQuery 3.5.1

```html
<script src="/static/common/vendor/jquery/jquery-3.5.1.min.js"></script>
```

### 以后加库

按同样结构丢进 `vendor/{name}/`，并在本 README 补一行引用示例即可。

> 说明：`FrontVendorAsset` / 主题自带 `assets/vendor` 仍可并存；本目录是「内核公共库」这条独立路径，不强制旧主题立刻迁移。