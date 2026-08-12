# 参与贡献（Community）

感谢你关注元舟 PivArk。本仓库面向 **Community 开源版** 的安装、阅读与二次开发协作。

## 先读这些

| 文档 | 内容 |
|------|------|
| [README.md](README.md) | 使命、环境、安装入口、许可 |
| [docs/BRANCHING.md](docs/BRANCHING.md) | **分支模型（develop / master / release / hotfix）** |
| [docs/INSTALL.md](docs/INSTALL.md) | 安装 |
| [docs/UPGRADE.md](docs/UPGRADE.md) | 升级与数据库平滑迁移 |
| [docs/PLUGIN-DEV.md](docs/PLUGIN-DEV.md) | 第三方插件入门 |
| [docs/SECURITY.md](docs/SECURITY.md) | 部署安全与漏洞通报 |
| [LICENSE](LICENSE) | 开源许可全文 |

## 贡献流程（标准）

1. Fork 本仓（Gitee 或 GitHub）。
2. 从 **`develop`** 拉取分支：`feature/<你的主题>`。
3. 本地按 [INSTALL.md](docs/INSTALL.md) 装通，完成改动与自测。
4. 向本仓 **`develop`** 提交 Pull Request / Merge Request（**不要**直接打向 `master`）。
5. 维护者 Review 后合入；发版时由维护者走 `release/*` → `master` + 标签。

紧急线上修复见 [BRANCHING.md](docs/BRANCHING.md) 的 `hotfix/*` 说明。

## 接受什么 / 暂不接受什么

**欢迎**

- Bug 修复、文档纠错、安装/升级说明改进
- 在插件扩展点内的示例与文档
- 可复现的问题报告（环境、版本、步骤、期望/实际）

**请先开 Issue 讨论**

- 大范围重构、换框架、平行造第二套核心模块
- 改变对外 API / 装包契约的破坏性改动

**请勿提交**

- 密钥、令牌、客户数据、内部 dig 专属文档
- 仅服务于私有部署的硬编码域名/后门

## 行为与许可

- 保持友善、就事论事；攻击性言论可关闭讨论。
- 贡献代码默认按本仓 [LICENSE](LICENSE) 授权。

## 维护者发版（摘要）

见 [docs/BRANCHING.md](docs/BRANCHING.md)：`develop` → `release/x.y.z` → `master` + `vX.Y.Z` + Releases 完整安装包附件。