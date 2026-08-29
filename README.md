<h1 align="center">元舟 PivArk</h1>

<p align="center">
  <b>企业数字中枢</b><br/>
  开源中枢基座 · 内容 · 会员 · 品项 · API · 应用中心<br/>
  按需点亮商城 / OA / CRM / ERP …
</p>

<p align="center">
  <a href="https://www.pivark.cn"><b>官网</b></a>
  · <a href="https://www.pivark.cn/docs/"><b>在线文档</b></a>
  · <a href="https://demo.pivark.cn"><b>在线演示</b></a>
  · <a href="https://gitee.com/pivark/pivark/releases"><b>下载 Community 1.6.5</b></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/ThinkPHP-8.1-black?logo=php&logoColor=white" alt="ThinkPHP">
  <img src="https://img.shields.io/badge/Vue-3-42b883?logo=vuedotjs&logoColor=white" alt="Vue3">
  <img src="https://img.shields.io/badge/MySQL-8.0%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/License-Community-2ea44f" alt="License">
  <img src="https://img.shields.io/badge/Release-1.6.5-blue" alt="Release">
</p>

---

## 一句话

**元舟 PivArk = 企业数字中枢。**  
开源中枢基座（内容、会员、品项、开放 API、应用中心）已交付；CMS、商城、OA/CRM/ERP… 是基座上的 **触点或经营模块**，不是产品身份。

| 你想… | 去哪 |
|------|------|
| 看全业务愿景 / 四象限 | [企业数字中枢蓝图](https://www.pivark.cn/docs/#/00-入门/企业数字中枢蓝图) |
| 15 分钟装起来 | [快速开始](https://www.pivark.cn/docs/#/00-入门/快速开始) |
| 懂架构与请求链 | [系统全景图](https://www.pivark.cn/docs/#/01-架构/系统全景图) |
| 后台怎么用 | [站长图文手册](https://www.pivark.cn/docs/#/02-后台功能/站长图文手册) |

---

## 能力分层（图）

<p align="center">
  <img src="https://www.pivark.cn/docs/assets/product/pivark-suite-overview.svg" alt="元舟能力分层示意" width="920">
</p>

| 层 | 你得到什么 |
|----|------------|
| **中枢基座** | 搜索 · AI · 支付 · 缓存/存储 · URL/SEO · 运维安全 · API · 插件框架（开源） |
| **系统功能** | 表单 · 悬浮联系 · 会员等（开源内置） |
| **内容插件** | 下载 · 图集 · 评论 · 视频 · 标书…（插件中心） |
| **企业应用** | 商城 · 分销 · OA · CRM · ERP · PLM · MES…（Enterprise / 授权） |
| **多端触点** | 官网模板 · 小程序 · `/api/v1` 同源出口 |

详述 → [产品简介](https://www.pivark.cn/docs/#/00-入门/产品简介)

---

## 和传统站差在哪

<p align="center">
  <img src="https://www.pivark.cn/docs/assets/product/pivark-vs-traditional.svg" alt="元舟 vs 传统内容站" width="920">
</p>

<p align="center">
  <img src="https://www.pivark.cn/docs/assets/product/pivark-three-layers-rings.svg" alt="三层同心能力示意" width="720">
</p>

---

## 文档库怎么读（已改版）

在线文档与发行包同源，目录为 **00～08**：

| 分区 | 内容 | 入口 |
|------|------|------|
| **00 入门** | 蓝图 · 简介 · 快速开始 · 版本 | [文档中心](https://www.pivark.cn/docs/) |
| **01 架构** | 全景图 · 品项内核 · TAG | [系统全景图](https://www.pivark.cn/docs/#/01-架构/系统全景图) |
| **02 后台** | 站长图文手册 · CMS 运营 | [站长图文手册](https://www.pivark.cn/docs/#/02-后台功能/站长图文手册) |
| **03 开发** | 本地环境 · 能力扩展 | [开发](https://www.pivark.cn/docs/#/03-开发/README) |
| **04 API** | `/api/v1` 约定与接口 | [API 使用概述](https://www.pivark.cn/docs/#/04-API/使用概述) |
| **05 参考** | 数据字典等 | [参考](https://www.pivark.cn/docs/#/05-参考/README) |
| **06 插件** | shop · 标书 · 官方插件 | [插件](https://www.pivark.cn/docs/#/06-插件/README) |
| **07 模板标签** | `{pv:*}` 手册 | [标签手册](https://www.pivark.cn/docs/#/07-模板标签/标签手册) |
| **08 运维** | 安装边界 · 交付 | [运维交付](https://www.pivark.cn/docs/#/08-运维交付/README) |

全库目录树 → [知识库导航](https://www.pivark.cn/docs/#/00-入门/知识库导航)  
当前发行版 → **开源版 1.6.5**（[当前版本](https://www.pivark.cn/docs/#/00-入门/当前版本) · [CHANGELOG](https://www.pivark.cn/docs/#/CHANGELOG)）

---

## 5 分钟装站（推荐路径）

> 建站请下 **Releases 里的 `pivark-community-install-*.zip`**（含 `vendor/` 与后台 dist）。  
> 不要把平台自动生成的「源码 zip」当安装包。

```text
1. 环境：PHP 8.2+ · MySQL 8.0+ · Nginx/Apache/宝塔
2. 下载安装包 → 解压到站点根（与 index.php 同级）
3. 浏览器打开 https://你的域名/install
4. 按向导填库 → 完成 → 登录后台
```

| 步骤说明 | 链接 |
|----------|------|
| 完整图文步骤 | [快速开始](https://www.pivark.cn/docs/#/00-入门/快速开始) |
| 目录树 / 权限 | [安装包与目录](https://www.pivark.cn/docs/#/00-入门/安装包目录结构) |
| 下载 | [Gitee Releases](https://gitee.com/pivark/pivark/releases) · [GitHub Releases](https://github.com/pivark/pivark/releases) · [官网镜像](https://www.pivark.cn/static/release/) |

演示账号以演示站提示为准；**生产环境请立即修改默认管理员密码**。

---

## 技术栈

| 层 | 技术 |
|----|------|
| 后端 | PHP **8.2+** · ThinkPHP **8.1** |
| 后台 | Vue 3 + Element Plus（安装包已含 `public/static/admin/dist/`） |
| 前台 | PHP 模板 + `{pv:*}` 标签引擎 |
| 插件 | `weapp/` 包 |
| 数据 | MySQL 8.0+ · 可选 Redis / MeiliSearch / 对象存储 |

---

## Community 与 Enterprise

| | **Community（本仓库）** | **Enterprise** |
|---|------------------------|----------------|
| 定位 | 中枢基座开源版 | 经营模块与商业授权 |
| 得到 | CMS 形态 · 会员 · API · 应用中心 · 官方内容插件等 | 商城 / OA / CRM / ERP… |
| 协议 | **[PivArk Community 开源许可](LICENSE)**（非 MIT/Apache） | 商务授权 · [pivark.cn](https://www.pivark.cn) |

认购关系与能力分层 → [产品简介](https://www.pivark.cn/docs/#/00-入门/产品简介#开源版-vs-企业版)

---

## 许可证

本仓库采用 **[PivArk Community 开源许可](LICENSE)**（定制条款 / source-available）。

- **允许**：学习、研究、修改、私有部署与商业建站使用（须保留「Powered by 元舟 PivArk」等版权标识）
- **不是** Apache-2.0 / MIT 等 OSI 标准协议；去版权、Enterprise 模块等须另行商业授权
- 全文见根目录 [`LICENSE`](LICENSE) · 贡献见 [`CONTRIBUTING.md`](CONTRIBUTING.md)


---

## 仓库说明

- 本仓 **长期分支仅 `master`**；版本用 **tag**（如 `v1.6.5`）
- **Gitee** 为主公示；**GitHub** 为镜像
- 贡献：Fork → PR → `master`（见 [CONTRIBUTING.md](https://www.pivark.cn/docs/#/00-入门/CONTRIBUTING)）
- Issues / PR 请尽量附：版本号、PHP/MySQL 版本、复现步骤

---

## 相关链接

| | |
|--|--|
| 官网 | https://www.pivark.cn |
| 文档 | https://www.pivark.cn/docs/ |
| 演示 | https://demo.pivark.cn |
| Gitee | https://gitee.com/pivark/pivark |
| GitHub | https://github.com/pivark/pivark |

<p align="center">
  <sub>© 元舟 PivArk · 企业数字中枢</sub>
</p>
