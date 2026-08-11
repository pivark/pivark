# install/assets — 安装专用资源（装完可删）

> **装后动作：** 安装成功后删除整个 `install/`（含 `setup/` 与本目录）

## 目录约定

| 路径 | 内容 | 装后 |
|------|------|------|
| `../setup/`（即 `install/setup/`） | `init_db` / `init_configs` / `init_document` 建库脚本 | **可删** |
| `packages/` | 官方内容增强包 zip（仅向导**勾选**的会解压到 `weapp/`） | **可删**（完成步已自动删除 `*.zip`） |
| `seed/` | 华仪智控**系统**演示脚本 + `data/`（勾选导入时执行） | **可删** |

## 与内核的分工

| 保留（`app/`，装后不可删） | 可删（根目录 `install/`） |
|---------------------------|---------------------------|
| `devtools/daily/schema/migrations/` 升级 | `install/setup/init_*.php` 建库 |
| `app/database/seeds/admin_menu_ssot.php` 菜单 SSOT | `packages/*.zip`、`seed/` 演示、向导 UI |

客户包**不含** `public/static/market` / `release`（市场与核心更新走官网 URL）。

发行版打包：生成增强包 zip 后复制到 `install/assets/packages/`（须过插件包审计；**禁止** Windows `Compress-Archive`）。  
装机 finish 会尝试软链 `uploads`/`static` 与 `public/static/theme/member`→`template/member`；向导 Nginx 配置为宝塔伪静态短片段（含 uploads/static/member 映射）。

## 删除顺序

1. `install/assets/packages/`
2. `install/assets/seed/`
3. `install/assets/`
4. `install/setup/`
5. `install/`（整目录）
