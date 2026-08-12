# 安装指南（Community）

> 与包内「安装说明.txt」一致；冲突时以包内说明与本仓库当前版为准。

## 环境要求

| 依赖 | 要求 |
|------|------|
| PHP | **8.1+** |
| 必选扩展 | `pdo_mysql` · `json` · `mbstring` · `openssl` · `curl` |
| 建议扩展 | `gd` · `zip` · `fileinfo` |
| MySQL | **8.0+** |
| Web | Nginx / Apache / IIS |
| 可写 | `data/` · `data/runtime/` · `public/uploads/` |

## 获取安装包

请下载 **`pivark-community-install-{版本}.zip`**（完整安装包）。  
不要用发行页自动生成的 Source code 压缩包当装机包。

当前推荐： [1.6.5 完整包（Gitee）](https://gitee.com/pivark/pivark/releases/download/v1.6.5/pivark-community-install-1.6.5.zip)

## 安装步骤

1. 解压到网站根目录（与 `index.php` 同级，勿多一层空目录）  
2. 创建空库，准备数据库账号  
3. 绑定域名，PHP ≥ 8.1  
4. 访问 `https://你的域名/` 或 `/install`  
5. 完成向导四步：环境检测 → 数据库（先测连接）→ **自设管理员** → 完成  
6. 打开 `/admin` 登录；**立即修改密码**  

安装会写入 `data/site.env`（或根目录 `.env`，以实际产物为准）与 `data/install.lock`。

## 后台账号

**无出厂默认账号。** 管理员在向导中由你创建。

## 装完检查

- [ ] 前台 `/` 可打开  
- [ ] 后台 `/admin` 可登录  
- [ ] 已按 [SECURITY.md](SECURITY.md) 收紧 `/install` 与调试开关  

## 重装（慎用）

生产环境不建议随意重装。若必须：备份库与 `data/`、上传目录 → 删除 `data/install.lock` → 再访问 `/install`（向导可能要求确认清空旧表）。