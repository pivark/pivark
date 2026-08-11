# Community 安装向导演示数据

> **读者：** 打包 Community zip 的维护者  
> **Lane 灌数 SSOT（dev/demo1 日常）：** [`app/database/setup/seeds/demo/`](../../app/database/setup/seeds/demo/) · [`data/`](../../app/database/setup/seeds/demo/data/)

## 分工

| 目录 | 何时用 | 主题 |
|------|--------|------|
| `install/assets/seed/`（本目录） | Web 安装向导勾选「导入演示数据」 | `site_theme=default`（客户首装） |
| `app/database/setup/seeds/demo/` | `npm run seed:demo` · demo1 运维灌数 | `site_theme=demo` |

## 维护约定

1. **开发改样例**：优先改 `setup/seeds/demo/seed_showcase.php` 与 `setup/seeds/demo/data/`；加灌用同目录 `demo_showcase_*_enrich.php`（`npm run seed:demo:volume`）。
2. **发版装包前**：将 `setup/seeds/demo/data/*.php` **同步拷贝**到 `install/assets/seed/data/`（安装包不含 demtools 时，向导仍须自带 data）；enrich 由 `seed_community_demo.php` 合并加载。
3. **禁止**在 `app/common/service` 内嵌 fixture / 再造 sync·seed 编排；lane 重灌与四站 sync **只认 demtools**（`seed:test:volume` / `qa_seed_sixpack_*` / `lanes:*`）。旧路径找不到 → 先 rg demtools，禁止拉回内核。
4. **案例/视频/下载**：社区种子灌文档与导航；插件功能挂表由 weapp `*DemoVolumeSeedService` 负责。装机在 `InstallSeedService` 社区种子**之后**回灌已选插件 Volume（避免有栏目无图集/播放器）。
5. **产品中心**：勾选演示时**无条件灌**品项 + 参数组（`seed_community_demo_items` / extras 内核段）。开源档只藏 UI（`ProductCenterGateService`）；装机写闸放行见 `ItemCapabilityGate::isInstallDemoSeedContext`。升专业版+打开即有样例。shop SKU 仅有 shop 时灌，**不得**因无 shop 跳过参数组。

## 脚本

| 文件 | 说明 |
|------|------|
| `seed_community_demo.php` | 向导主入口（华仪样例 + 本目录 `data/*_enrich.php` 加灌） |
| `data/demo_showcase_*.php` | 文档/配图 SSOT（与 `setup/seeds/demo/data` 同步） |
| `data/demo_showcase_*_enrich.php` | 新闻/案例加量（装包自带，不依赖 app/database/setup） |
| `seed_community_demo_items.php` | 品项 + 默认参数组/defs |
| `seed_community_demo_item_relations.php` | 品项关联 |
| `seed_community_demo_product_extras.php` | 产品扩展（参数组扩展始终；shop SKU 可选） |

## 演示素材目录

业务配图/样例附件统一在 `public/uploads/demo-seed/`（路径字面量 `/uploads/demo-seed/`（`DemoSeedPaths` 已退役））。
装完若不要演示素材：**整夹删除 `public/uploads/demo-seed/`** 即可；勿删主题 `images/logo*`。
插件 `weapp/*/assets/guide` 为说明图，不属于演示包。
