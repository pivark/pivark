# 网盘平台图标

统一规格：SVG，`viewBox="0 0 24 24"`，`width/height="24"`。

| 文件 | 来源 |
|------|------|
| `baidu.svg` `aliyun.svg` `google.svg` `onedrive.svg` `mega.svg` `feishu.svg` `weiyun.svg` | [Simple Icons](https://simpleicons.org/)（MIT） |
| 其余 | 项目内自制标识（品牌色 + 字形），Simple Icons 无对应条目时使用 |

后台引用：`/static/admin/images/pan/{platformId}.svg`，见 `pan-platform.ts`。
