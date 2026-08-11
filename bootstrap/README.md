# bootstrap/

HTTP 真入口与站点根启动件（**非** `app/bootstrap` CLI）。

| 文件 | 作用 |
|------|------|
| `web_entry.php` | ThinkPHP / PHP 8.1+ 启动；由根目录 `index.php` 薄壳 `require` |

根目录只保留 `index.php`（须 PHP 5 可解析的版本软门壳）。禁止浏览器直访本目录（`.htaccess` / 宝塔片段已 deny）。
