# comment 后台 UI（SSOT）

Vue SFC：`weapp/doc_comment/admin/ui/`。

**零外放**：禁止在 `admin/src/views/weapp/doc_comment/` 放 junction 或副本。`access.ts` 通过 `import.meta.glob('../../../weapp/*/admin/ui/**/*.vue')` 自动纳入 pageMap；业务页经 `/weapp/host/doc_comment/{page}` + 通用壳 `admin/src/views/weapp/host/index.vue` 动态加载。旧书签 `/weapp/doc_comment/{page}` 由 `AdminSpa` 入口脚本纠偏到 host 路径。

路由由 `CommentAdminSpaRoutes` + 后端菜单注册。
