# install/service — 装站向导业务（一次性）

装完可随 `install/` 删除。常驻能力勿放此处（见 `PluginBundledPackageLocator`、`InstallGate`）。

| 类 | 职责 |
|----|------|
| `InstallCompletionLinksService` | 完成页外链 cn/com 探测 |
| `InstallEnvironmentCheck` | PHP/扩展/权限与宝塔指引 |
| `InstallEnhancementPackService` | 增强包勾选 UI（zip→PluginBundledPackageLocator） |
| `InstallStepService` | 分步执行 + 完成页（含可删 install/ 说明） |
| `InstallSeedService` | 演示种子 |
| `InstallDatabaseService` | 库连接/建表/迁移 |
| `InstallService` | 向导门面（controller 入口） |
