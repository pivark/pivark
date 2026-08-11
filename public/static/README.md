# public/static 目录（Community 发行布局）

根目录**只允许**下列子目录 + `README.md`；其余一律删除或迁入 `common/` / `devtools/` / `site-kit/`。

| 目录/文件 | 角色 | 用户可否手改 |
|-----------|------|--------------|
| **common/** | 内核 JS/CSS/vendor/fonts（L1，含 captcha.ttf） | **否** · 升级覆盖 |
| **theme/** | 主题 publish 产物 | 否 · template/ 改后 publish |
| **admin/** | 后台 SPA dist | 否 |
| **market/** | 插件市场 catalog | 否 |
| **www/** | 官网 docs-manifest（platform） | 否 |
| **pivui/** · **pivadmin/** | site-kit 构建产物（部分同步）· Community 安装包不含 | 否 · 改 site-kit 后重建 |
| **release/** | `updates.json` + `fingerprints/*.json`（禁止 zip 进仓库；运行真源发到官网 b，非 Gitee） | 否 |
| **README.md** | 本说明 | 否 |

已删除/禁止恢复：

- `pv/`、`js/`、`qr/`、`home/`、`fonts/`、`bootstrap_theme/`、`demo-fulltype-form.html`
- **`pivcss/`、`pivcss-animate/`、`pivcss-icons/`**（PivCSS 未就绪；产物只在 `site-kit/pivcss*`，dev 经 `.htaccess` 映射 `/static/pivcss/` URL，**禁止**复制进本目录）
- `release/*.zip`（构建产物放 `build/output/`）
- 二维码缓存：`data/runtime/qr/`（路由 `document/qrcode/{id}`）

门禁：`php devtools/test/tests/standards/check_front_static_assets.php`（已接入 test:standards）

## thumb/

doc_thumb 插件运行态缩略图缓存（/static/thumb/doc/…），可删可再生；勿手工塞业务静态。
