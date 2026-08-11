<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 *
 * PHP 版本软门（弱验证）：须在任何 PHP 7/8 语法文件之前加载。
 * 语法目标：PHP 5.0+ 可解析。对方哪怕是 PHP 5，也能打开本页看到改配置指引。
 * 视觉与 install/view 安装向导统一（同色板 / 卡片 / 进度条 / 按钮），全屏居中。
 */
if (!function_exists('pivark_php_version_gate')) {
    function pivark_php_version_gate()
    {
        if (version_compare(PHP_VERSION, '8.1.0', '>=')) {
            return;
        }

        $isBaota = false;
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
        $serverSoft = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';
        if ($docRoot !== '' && (strpos($docRoot, '/www/wwwroot/') !== false || strpos($docRoot, '\\www\\wwwroot\\') !== false)) {
            $isBaota = true;
        }
        if (!$isBaota && @is_dir('/www/server/panel')) {
            $isBaota = true;
        }
        if (!$isBaota && stripos($serverSoft, 'baota') !== false) {
            $isBaota = true;
        }

        $panelLabel = $isBaota ? '宝塔面板' : '通用环境';
        $ver = htmlspecialchars(PHP_VERSION, ENT_QUOTES);
        $panelEsc = htmlspecialchars($panelLabel, ENT_QUOTES);

        if ($isBaota) {
            $guideTitle = '宝塔面板 · 修复指引';
            $bannerText = 'PHP 版本过低（当前 ' . $ver . '）。请先改服务器配置，不是安装包坏了。';
            $steps = array(
                '登录宝塔 → 软件商店 → 运行环境 → 安装 PHP 8.1+（推荐 8.2 / 8.3）',
                '网站 → 你的站点 → 设置 → PHP 版本 → 选刚装的 8.1+（与上一步同一版本）',
                '软件商店 → 已安装 → 点该 PHP → 设置 → 安装扩展：pdo_mysql、mbstring、openssl、curl、fileinfo、zip、gd',
                '同一处「服务」→「重载配置」或「重启」PHP-FPM',
                '回到本页点「重新检测」，通过后进入许可声明与完整安装向导',
            );
        } else {
            $guideTitle = '环境修复指引';
            $bannerText = 'PHP 版本过低（当前 ' . $ver . '）。请先把站点 PHP 升到 8.1+ 再继续。';
            $steps = array(
                '确认 Web 实际使用的 PHP 版本（phpinfo / 面板站点设置），不要只改 CLI',
                '安装 PHP 8.1+（推荐 8.2 / 8.3），站点处理程序切到该版本',
                '启用扩展：pdo_mysql、mbstring、openssl、curl、fileinfo、zip、gd',
                '重载 php-fpm / 回收应用程序池 / 重启站点',
                '回到本页点「重新检测」，通过后进入许可声明与完整安装向导',
            );
        }

        $stepsHtml = '';
        foreach ($steps as $step) {
            $stepsHtml .= '<li>' . htmlspecialchars($step, ENT_QUOTES) . '</li>';
        }

        if (!headers_sent()) {
            header('HTTP/1.1 200 OK');
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex');
            header('Cache-Control: no-store');
        }

        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>安装向导 - 元舟 PivArk</title>'
            . '<style>'
            . 'html,body{height:100%;}'
            . 'body{margin:0;min-height:100%;display:flex;align-items:center;justify-content:center;padding:24px 16px;box-sizing:border-box;'
            . 'background:linear-gradient(180deg,#eef3fb 0%,#f5f7fa 220px);font-family:"PingFang SC","Microsoft YaHei",sans-serif;color:#303133;}'
            . '.wrap{width:100%;max-width:860px;background:#fff;border-radius:12px;box-shadow:0 8px 28px rgba(30,60,114,.08);padding:32px 36px 28px;box-sizing:border-box;}'
            . 'h1{font-size:24px;margin:0 0 6px;color:#1e3c72;letter-spacing:.02em;}'
            . '.sub{color:#909399;margin:0 0 26px;font-size:13px;line-height:1.6;}'
            . '.progress-bar{display:flex;margin-bottom:28px;padding:10px 0 18px;border-bottom:1px solid #edf0f5;gap:4px;}'
            . '.progress-item{flex:1;text-align:center;position:relative;min-width:0;}'
            . '.progress-item::after{content:"";position:absolute;top:13px;right:-50%;width:100%;height:2px;background:#e4e7ed;z-index:0;}'
            . '.progress-item:last-child::after{display:none;}'
            . '.progress-item::before{content:attr(data-step);display:block;width:26px;height:26px;border-radius:50%;background:#e4e7ed;color:#909399;'
            . 'font-size:12px;line-height:26px;text-align:center;margin:0 auto 8px;position:relative;z-index:1;font-weight:600;}'
            . '.progress-item.active::before{background:#409eff;color:#fff;box-shadow:0 0 0 4px rgba(64,158,255,.15);}'
            . '.progress-item span{display:block;font-size:12px;color:#909399;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:0 2px;}'
            . '.progress-item.active span{color:#303133;font-weight:600;}'
            . 'h3{font-size:17px;margin:0 0 8px;color:#1e3c72;font-weight:600;}'
            . '.step-desc{color:#909399;font-size:13px;margin:0 0 16px;line-height:1.6;}'
            . '.env-overview{display:table;width:100%;border-collapse:separate;border-spacing:12px 0;margin:0 -12px 16px;}'
            . '.env-card{display:table-cell;width:33.33%;background:#f8fafc;border:1px solid #ebeef5;border-radius:10px;padding:14px 16px;vertical-align:top;}'
            . '.env-card-label{font-size:12px;color:#909399;margin-bottom:6px;}'
            . '.env-card-value{font-size:15px;font-weight:600;color:#303133;word-break:break-all;}'
            . '.bt-panel{display:inline-block;background:#20a53a;color:#fff;font-size:12px;padding:2px 10px;border-radius:12px;margin-left:6px;vertical-align:middle;}'
            . '.env-status-banner{display:block;padding:12px 16px;border-radius:10px;margin-bottom:14px;font-size:13px;line-height:1.55;}'
            . '.env-status-banner.bad{background:#fef0f0;border:1px solid #fbc4c4;color:#c45656;}'
            . '.env-table-wrap{border:1px solid #ebeef5;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.03);}'
            . '.env-table{width:100%;border-collapse:collapse;font-size:13px;}'
            . '.env-table th,.env-table td{padding:11px 14px;text-align:left;vertical-align:middle;border-bottom:1px solid #ebeef5;}'
            . '.env-table th{background:#f5f7fa;color:#606266;font-weight:600;font-size:12px;}'
            . '.env-table tbody tr:last-child td{border-bottom:none;}'
            . '.env-table tbody tr.row-fail{background:#fffafa;}'
            . '.col-status{width:108px;text-align:center;}'
            . '.col-level{width:72px;text-align:center;}'
            . '.pill{display:inline-block;min-width:58px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.4;text-align:center;}'
            . '.pill-bad{background:#fdecea;color:#f56c6c;}'
            . '.pill-level{background:#eef2ff;color:#5b6ee1;}'
            . '.detail-cell{color:#909399;font-size:12px;word-break:break-all;}'
            . '.bt-guide{background:#f0faf3;border:1px solid #b7ebc6;border-radius:10px;padding:14px 16px;margin:14px 0 0;font-size:13px;line-height:1.7;color:#333;}'
            . '.bt-guide strong{display:block;margin-bottom:4px;color:#1e3c72;}'
            . '.bt-guide ol{margin:8px 0 0 18px;padding:0;}'
            . '.bt-guide li{margin:4px 0;}'
            . '.note{margin-top:14px;padding:12px 14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;color:#9a3412;font-size:13px;line-height:1.6;}'
            . '.wizard-nav{display:block;margin-top:28px;padding-top:20px;border-top:1px solid #edf0f5;text-align:right;}'
            . '.pv-btn{display:inline-block;padding:0 16px;height:36px;line-height:36px;border:1px solid #dcdfe6;border-radius:8px;background:#fff;'
            . 'color:#606266;font-size:13px;cursor:pointer;text-decoration:none;}'
            . '.pv-btn-primary{background:#409eff;border-color:#409eff;color:#fff;}'
            . '@media (max-width:720px){'
            . '.wrap{padding:24px 18px 20px;}'
            . '.env-overview,.env-card{display:block;width:auto;margin:0 0 10px;}'
            . '.env-overview{margin:0 0 6px;}'
            . '.progress-item span{font-size:11px;}'
            . '}'
            . '</style></head><body><div class="wrap">'
            . '<h1>元舟 PivArk 安装向导</h1>'
            . '<p class="sub">先确认服务器环境。当前 PHP 不满足时，按下方指引改配置后再继续（不是安装包缺文件）。</p>'
            . '<div class="progress-bar">'
            . '<div class="progress-item" data-step="1"><span>许可声明</span></div>'
            . '<div class="progress-item active" data-step="2"><span>环境检测</span></div>'
            . '<div class="progress-item" data-step="3"><span>数据库</span></div>'
            . '<div class="progress-item" data-step="4"><span>管理员</span></div>'
            . '<div class="progress-item" data-step="5"><span>完成安装</span></div>'
            . '</div>'
            . '<h3>2. 环境检测</h3>'
            . '<p class="step-desc">必选项须全部通过。把站点 PHP 升到 8.1+ 并装齐扩展后，点「重新检测」。</p>'
            . '<div class="env-overview">'
            . '<div class="env-card"><div class="env-card-label">运行环境</div><div class="env-card-value">'
            . $panelEsc
            . ($isBaota ? '<span class="bt-panel">宝塔</span>' : '')
            . '</div></div>'
            . '<div class="env-card"><div class="env-card-label">PHP 版本</div><div class="env-card-value" style="color:#f56c6c;">' . $ver . '</div></div>'
            . '<div class="env-card"><div class="env-card-label">要求</div><div class="env-card-value">PHP &gt;= 8.1</div></div>'
            . '</div>'
            . '<div class="env-status-banner bad">' . htmlspecialchars($bannerText, ENT_QUOTES) . '</div>'
            . '<div class="env-table-wrap"><table class="env-table"><thead><tr>'
            . '<th>检测项</th><th class="col-level">类型</th><th class="col-status">状态</th><th>详情</th>'
            . '</tr></thead><tbody>'
            . '<tr class="row-fail">'
            . '<td>PHP &gt;= 8.1</td>'
            . '<td class="col-level"><span class="pill pill-level">必选</span></td>'
            . '<td class="col-status"><span class="pill pill-bad">未通过</span></td>'
            . '<td class="detail-cell">当前 ' . $ver . ' · 推荐 8.2 / 8.3</td>'
            . '</tr></tbody></table></div>'
            . '<div class="bt-guide"><strong>' . htmlspecialchars($guideTitle, ENT_QUOTES) . '</strong><ol>'
            . $stepsHtml
            . '</ol></div>'
            . '<div class="note">换宝塔或重装面板后，站点常会落回默认 PHP 5/7。改完「网站 PHP 版本」并装齐扩展后，再点下方按钮。</div>'
            . '<div class="wizard-nav"><button type="button" class="pv-btn pv-btn-primary" onclick="location.reload()">重新检测</button></div>'
            . '</div></body></html>';
        exit;
    }
}

pivark_php_version_gate();
