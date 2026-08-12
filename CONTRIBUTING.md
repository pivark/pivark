# Contributing to PivArk 开源版

感谢关注元舟 PivArk 开源版。本文说明如何参与贡献。

> **分支政策（2026-08-12）：** 开源仓 **只保留 `master`**。请 Fork 后提 PR 到 `master`；不要再开 `dev`/`develop` 长期分支。维护者说明见仓内 `docs/_team`（若你克隆的是完整维护树）。

## 开始之前

1. 阅读 [docs/00-入门/快速开始.md](docs/00-入门/快速开始.md) 跑通本地环境  
2. 编码规范 SSOT：[docs/03-开发/开发规范.md](docs/03-开发/开发规范.md)  
3. 插件边界：[docs/06-插件/插件伙伴红线卡.md](docs/06-插件/插件伙伴红线卡.md)

## 分支与 PR

| 步骤 | 说明 |
|------|------|
| 1 | Fork [Gitee](https://gitee.com/pivark/pivark) 或 [GitHub](https://github.com/pivark/pivark) |
| 2 | 从 **`master`** 拉短期分支：`feat/xxx`、`fix/xxx`、`docs/xxx` |
| 3 | 提 **Pull Request → `master`** |
| 4 | 描述写清：做了什么、为什么、如何验证 |

提交信息建议：`feat|fix|refactor|docs|chore(scope): 简述`（中英文均可）。

**不要**直接向 `master` 强推；版本发行由维护者打 **tag**（如 `v1.6.5`）并挂 Release 安装包。

## 合入前自检

```bash
npm run test:unit
npm run test:standards
```

若改动路由、权限、迁移或 `docs/` 链接，另跑：

```bash
npm run test:routes
npm run test:docs
```

## 代码要求

- PHP 文件保留完整版权头（见开发规范）
- 业务逻辑写在 `app/common/service/`，控制器保持薄
- 新表/字段须有中文 MySQL `COMMENT`
- 插件业务放在 `weapp/{identifier}/`，勿改内核塞插件特例

## 文档

- 契约/API 变更须更新对应文档
- 文档内链用相对路径；发现错误也可用 [文档说明与贡献](docs/00-入门/文档说明与贡献.md) 留言

## 许可证

贡献的代码将按 [LICENSE](LICENSE) 发布。提交 PR 即表示您同意该许可条款。

## 行为准则

保持尊重、就事论事。骚扰、歧视或泄露他人隐私的内容将被拒绝。
