# 文档评论（`comment`）

> **用户文档 SSOT：** 本目录 `README.md`（`## 功能介绍` · `## 使用说明` · `## 升级日志` · `## 安装与开发`） · v1.0.0 · `pivark/doc_comment`；后台与市场直读，改完即生效。

## 功能介绍

<h3>这个插件能帮你做什么？</h3>
<p>当你希望访客在文章、资料页底部<strong>直接讨论、提问、点赞</strong>时，评论插件帮你零开发上线完整互动区——支持游客/会员、回复楼中楼、先审后发，后台一键审核。</p>
<p class="pv-tip">适合新闻资讯、产品说明、下载资料页、会员社区等需要「看完就能留言」的场景。</p>

<h3>典型场景</h3>
<figure class="pv-guide-figure">
    <img src="guide-scenarios.svg" alt="新闻、资料、社区、审核四类场景" loading="lazy" width="720" />
    <figcaption>同一套评论能力可覆盖资讯讨论、资料反馈与会员社区。</figcaption>
</figure>

<h3>什么时候会生效？</h3>
<table>
    <thead><tr><th>情况</th><th>前台表现</th></tr></thead>
    <tbody>
        <tr><td>插件已启用，模板已放 <code>{pv:doc_comment}</code></td><td>显示评论表单与列表</td></tr>
        <tr><td>开启「先审后发」</td><td>新评论待后台通过后展示</td></tr>
        <tr><td>关闭游客评论</td><td>未登录访客只能浏览，不能提交</td></tr>
        <tr><td>模板未放置标签</td><td>该页不出现评论区块</td></tr>
    </tbody>
</table>

<h3>站长怎么用？</h3>
<figure class="pv-guide-figure">
    <img src="guide-admin-flow.svg" alt="启用、设置、加标签、审核四步" loading="lazy" width="720" />
    <figcaption>从插件中心启用到前台可见，通常四步即可完成。</figcaption>
</figure>
<ol>
    <li>在插件中心<strong>安装并启用</strong>「评论」。</li>
    <li>打开「<strong>基础设置</strong>」：顶部数据概览可查看评论总数、待审核与已通过；下方配置开关、敏感词与会员等级权限。</li>
    <li>在文档详情模板正文下方加入一行 <code>{pv:doc_comment}</code>（详见「前台调用说明」）。</li>
    <li>在「评论管理」审核、删除不当内容；支持按文档、状态筛选。</li>
</ol>

<h3>访客在前台看到什么？</h3>
<ul>
    <li>评论列表（昵称、时间、内容、点赞数）；</li>
    <li>回复按钮与楼中楼结构；</li>
    <li>未配置或未启用时：<strong>整块不出现</strong>，不影响页面其它内容。</li>
</ul>

<h3>常见问题</h3>
<ul>
    <li><strong>为什么前台没有评论框？</strong> 检查插件是否启用、模板是否含 <code>{pv:doc_comment}</code>、当前页是否为文档详情。</li>
    <li><strong>评论提交了看不到？</strong> 可能开启了先审后发，到「评论管理」通过即可。</li>
</ul>

## 使用说明

<h3>功能简介（前端向）</h3>
<p>评论数据按当前文档 <code>document_id</code> 自动关联。推荐在<strong>详情页模板</strong>正文下方放置 <code>{pv:doc_comment}</code>；需要自定义列表或 SPA 时可调 REST 接口。</p>

<figure class="pv-guide-figure">
    <img src="usage-template-wireframe.svg" alt="详情页底部评论区块位置" loading="lazy" width="640" />
    <figcaption>标签通常放在正文与页脚之间。</figcaption>
</figure>

<h3>推荐写法（模板标签）</h3>
<pre class="pv-weapp-code">{pv:doc_comment}</pre>
<p>系统会自动传入当前文档 ID，无需手写 <code>id</code> 属性。</p>

<h3>自定义容器（可选属性）</h3>
<table>
    <thead><tr><th>属性</th><th>说明</th><th>示例</th></tr></thead>
    <tbody>
        <tr><td><code>id</code></td><td>文档 ID（默认当前页）</td><td><code>{pv:doc_comment id="$document_id"}</code></td></tr>
        <tr><td><code>page_size</code></td><td>每页条数</td><td><code>{pv:doc_comment page_size="20"}</code></td></tr>
    </tbody>
</table>

<h3>REST API</h3>
<table>
    <thead><tr><th>接口</th><th>说明</th></tr></thead>
    <tbody>
        <tr><td><code>GET /api/v1/plugins/doc_comment/list/{document_id}?page=1</code></td><td>分页拉取评论列表 JSON</td></tr>
        <tr><td><code>POST /api/v1/plugins/doc_comment/submit</code></td><td>提交评论（需登录或按配置允许游客）</td></tr>
    </tbody>
</table>
<pre class="pv-weapp-code">GET /api/v1/plugins/doc_comment/list/123?page=1</pre>

<h3>前台效果示意</h3>
<figure class="pv-guide-figure">
    <img src="usage-front-mockup.svg" alt="评论列表与回复区 mockup" loading="lazy" width="640" />
    <figcaption>默认样式可通过主题 CSS 覆盖 <code>.pv-comment</code> 相关类名。</figcaption>
</figure>

<p class="pv-tip">先审后发时，API 提交成功不代表立即可见；需后台审核通过后才会出现在列表接口中。</p>

## 升级日志

### 1.0.x · 2026-07-17

- 游客评论开启时，回复与主评一致可发；关闭时前台显示「去登录」引导，不再空表单提交失败
- 列表引用块 enrichment 对齐 JSON `data` 列表；配置保存同步游客等级权限行
- 盖楼视觉：楼号 `#N`、嵌套竖线轨与卡片层级，替代纯缩进
- 游客昵称输入、字数计数、待审内联提示、已赞态；点赞/加载失败人话
- 列表按「每页条数」先显示主楼，可「加载更多」；后台评论管理支持一键通过全部待审
- 安装演示：主评 + 回复楼中楼样例

### 1.0.0 · 2026-01-15

- 文档评论、回复与点赞
- 会员等级权限、敏感词过滤与后台审核
- 前台 `{pv:doc_comment}` 标签挂载
- 开源标装（MIT）可二次开发

## 安装与开发

### 目录

```text
weapp/doc_comment/
├── plugin.json
├── Plugin.php
├── service/          # 业务 ONLY HERE
├── api/controller/
├── admin/            # 后台路由/控制器（如有）
└── database/
```

### 说明

评论；配置与列表在 service/

### 超级搜索

评论 UGC **不参与**站内 `search_text` / Meili。见 [docs/06-插件/comment.md](../../docs/06-插件/comment.md)。

### 标装进度

| 项 | 状态 |
|----|------|
| `service/` 业务集中 | ✅ |
| `composer.json` | ✅ `pivark/doc_comment` |
| `PluginApiRegistry` 对外 API | ✅ `CommentPublic` |

### 禁止

- 在 `app/common/service/` 写业务 extends 壳（document-addon 桥接仅 `document/bridge/Document*Service`）
- 插件内引用 `app/common/service/{Plugin}*` 业务空壳
