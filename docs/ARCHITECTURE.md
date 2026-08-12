# 架构速览（Community）

> 读代码的入口地图。细节以仓库源码为准。

## 分层

| 层 | 路径 | 职责 |
|----|------|------|
| 前台主题 | `template/` | PC/移动门户渲染 |
| 后台 SPA | `public/static/admin/dist/`（源在发行构建产物） | Vue3 运营后台 |
| HTTP / 路由 | `app/` · `route` 相关 | 管理端与前台控制器 |
| 领域服务 | `app/common/service/` | 业务真源（内容、站点、支付管道等） |
| 插件 | `weapp/{id}/` | 仅经 Gateway 扩展；**勿改 `app/`** |
| 安装向导 | `install/` | 首次部署 |
| 配置 / 数据 | `config/` · `data/` | 环境与运行态（`data/` 勿提交密钥） |

## 技术栈

- **后端**：PHP **8.1+**，ThinkPHP **8.x**（`topthink/framework`），MySQL **8.0+**
- **后台**：Vue **3** · Vue Router · Pinia · Element Plus
- **可选**：Redis、MeiliSearch、对象存储（后台配置）

## 关键设计取舍

1. **一个内核**：门户、会员、商城、开放 API 同源数据与权限。  
2. **列表 vs 搜索分流**：目录筛选走 catalog；关键词走 search。  
3. **插件隔离**：业务扩展进 `weapp/`，经统一生命周期与授权（Entitlement）。  
4. **JSON API**：2xx `{ data }` / 4xx `{ error }`（见在线文档约定）。

## 建议阅读顺序

1. `index.php` → 启动  
2. `install/` → 装机契约  
3. `app/common/service/` 下与内容 / 站点相关的 Service  
4. `weapp/doc_comment/`（或其它已装插件）→ 插件边界范例  
5. [PLUGIN-DEV.md](PLUGIN-DEV.md)

本仓为 **Community 可发行树**（含 `vendor/`），便于直接部署；二次开发请先读 [CONTRIBUTING.md](../CONTRIBUTING.md)。