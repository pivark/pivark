# 插件开发入门（第三方）

> 方便第三方写插件、共建生态。完整规范以官网文档为准；本文是仓库内**最短可上手版**。

## 心智模型

```text
PivArk 内核  = 操作系统（app/）
你的插件    = 用户态应用（只能放在 weapp/{id}/）
Gateway     = 系统调用（向内核要能力，禁止直掏内核私货）
```

**禁止修改 `app/`。** 只提交 `weapp/{你的插件id}/`。

## 最小目录

```text
weapp/{identifier}/
├── plugin.json          # 清单：id、版本、hooks/extensions
├── Plugin.php           # install / enable / boot
├── README.md            # 功能介绍 + 使用说明
├── service/             # 业务只写这里
├── database/            # install.sql 或迁移
├── admin/               # 后台页（若需要）
└── assets/              # 前台 CSS/JS
```

可参考本仓库已有插件目录（如 `weapp/doc_comment/`）对照结构。

## 五步上手

1. **定 id 与能力边界** — 一个插件一件事；命名稳定后勿乱改  
2. **写 `plugin.json` + `Plugin.php`** — 安装时建表，boot 时经 **Weapp\*Gateway** 注册扩展点  
3. **业务进 `service/`** — 配置保存、列表、前台 API 都走 Service  
4. **需要挂文档/品项时** — 用官方 DocumentAddon / Item 等扩展点，不要改内核表结构乱挂  
5. **自测与审包** — 本地装启停、权限、前台调用；上架前按官网「第三方插件集成 / 审包」清单自检  

## 扩展方式（常见）

| 方式 | 用途 |
|------|------|
| 后台菜单 / SPA 页 | 运营配置与列表 |
| 前台模板标签 / 资源 | 门户展示 |
| REST（经插件 PublicApi） | 小程序 / 外围系统 |
| 事件订阅 | 订单完成、文档保存等信号 |

## 文档与上架

- 插件内 `README.md`：给使用者看的功能介绍与调用说明  
- 官网更全：[插件开发规范](https://www.pivark.cn/docs/) · 第三方集成指南（在线文档站）  
- 商业上架走官方应用市场审包流程（以官网当期说明为准）  

## 不要做的事

- 改 `app/`、改别人的 `weapp/`  
- 硬编码绕过授权 / 权限  
- 在插件里另起一套与内核冲突的用户/支付体系（应走内核 Gateway）  