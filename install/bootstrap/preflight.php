<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 *
 * 安装/启动极早预检：禁止依赖 vendor / ThinkPHP / mbstring。
 * 缺必装扩展时直接输出可读页面，避免 HTTP 500 空白。
 */
declare(strict_types=1);

/**
 * 探测面板 / Web 服务器（纯 PHP，open_basedir 安全）。
 *
 * @return array{panel:string,label:string,server:string}
 */
function pivark_preflight_detect_env(): array
{
    $serverSoft = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
    $sapi = strtolower((string) PHP_SAPI);
    $ini = str_replace('\\', '/', (string) (php_ini_loaded_file() ?: ''));
    $os = strtoupper(substr(PHP_OS, 0, 3));

    $canProbe = static function (string $path): bool {
        $basedir = (string) ini_get('open_basedir');
        if ($basedir === '') {
            return true;
        }
        $norm = str_replace('\\', '/', $path);
        foreach (explode(PATH_SEPARATOR, $basedir) as $base) {
            $base = rtrim(str_replace('\\', '/', $base), '/');
            if ($base === '') {
                continue;
            }
            if ($norm === $base || str_starts_with($norm, $base . '/')) {
                return true;
            }
        }

        return false;
    };

    $isDir = static function (string $path) use ($canProbe): bool {
        return $canProbe($path) && is_dir($path);
    };

    $baotaSignals = 0;
    if ($isDir('/www/server/panel')) {
        $baotaSignals += 2;
    }
    if (getenv('BT_PANEL') !== false && getenv('BT_PANEL') !== '') {
        $baotaSignals += 2;
    }
    if ($ini !== '' && str_contains($ini, '/www/server/php/')) {
        $baotaSignals += 2;
    }
    if ($isDir('/www/server/nginx') || $isDir('/www/server/apache')) {
        $baotaSignals += 1;
    }

    $server = 'generic';
    if (str_contains($serverSoft, 'nginx') || str_contains($sapi, 'fpm')) {
        $server = 'nginx';
    } elseif (str_contains($serverSoft, 'apache') || str_contains($serverSoft, 'httpd')) {
        $server = 'apache';
    } elseif (str_contains($serverSoft, 'microsoft-iis') || str_contains($serverSoft, 'iis') || $os === 'WIN') {
        // Windows 且非明确 nginx/apache 时按 IIS/通用 Windows 处理
        $server = str_contains($serverSoft, 'iis') ? 'iis' : ($os === 'WIN' ? 'windows' : 'generic');
    }

    if ($baotaSignals >= 2) {
        return [
            'panel'  => 'baota',
            'label'  => '宝塔面板',
            'server' => $server,
        ];
    }

    if ($server === 'iis') {
        return [
            'panel'  => 'iis',
            'label'  => 'IIS / Windows',
            'server' => 'iis',
        ];
    }

    if ($server === 'windows') {
        return [
            'panel'  => 'windows',
            'label'  => 'Windows PHP',
            'server' => 'windows',
        ];
    }

    if ($server === 'nginx') {
        return [
            'panel'  => 'nginx',
            'label'  => 'Nginx + PHP-FPM',
            'server' => 'nginx',
        ];
    }

    if ($server === 'apache') {
        return [
            'panel'  => 'apache',
            'label'  => 'Apache',
            'server' => 'apache',
        ];
    }

    return [
        'panel'  => 'generic',
        'label'  => '通用 Linux / 其他面板',
        'server' => 'generic',
    ];
}

/**
 * 按缺项 key + 环境给出修复指引（一句，给人看）。
 */
function pivark_preflight_fix_hint(string $panel, string $key): string
{
    $baotaExt = '软件商店 → 已安装 → 你的 PHP 版本 → 设置 → 安装扩展 → ';
    $baotaPhp = '软件商店 → 运行环境 → 安装 PHP 8.1+（推荐 8.2/8.3）→ 网站 PHP 版本选同一版本';
    $baotaDir = '网站 → 站点根目录 → 对应目录权限设为可写（755/775，属主为网站运行用户）';

    $nginxExt = '在对应 PHP 版本的 php.ini 启用 extension=…，然后 systemctl reload php-fpm（或重启 php-fpm）';
    $apacheExt = '在对应 PHP 的 php.ini 启用扩展后，重启 Apache（apachectl graceful / systemctl reload httpd）';
    $iisExt = 'IIS 管理器确认站点 PHP 处理程序版本；用该版本的 php.ini 启用扩展，再回收应用程序池';
    $winExt = '编辑当前 Web 使用的 php.ini，取消 extension= 注释后重启站点 / php-cgi';
    $genericExt = '编辑站点实际加载的 php.ini（phpinfo 可见路径），启用扩展后重启 PHP';

    // 不用 match：本文件须在 PHP 8.0 以下仍可解析（版本软门之后才应到达；双保险）
    $extMap = [
        'pdo' => 'pdo_mysql',
        'json' => 'json',
        'mbstring' => 'mbstring',
        'openssl' => 'openssl',
        'curl' => 'curl',
        'gd' => 'gd',
        'zip' => 'zip',
        'fileinfo' => 'fileinfo',
    ];
    $extName = $extMap[$key] ?? '';

    if ($key === 'php') {
        if ($panel === 'baota') {
            return $baotaPhp;
        }
        if ($panel === 'iis' || $panel === 'windows') {
            return '安装 PHP 8.1+（推荐 8.2/8.3），并在站点处理程序中选定该版本';
        }

        return '升级系统 PHP 到 8.1+（推荐 8.2/8.3），并确认 Web 与 CLI 版本一致';
    }

    if (in_array($key, ['data', 'runtime', 'uploads'], true)) {
        if ($key === 'data') {
            $dirHint = 'data/';
        } elseif ($key === 'runtime') {
            $dirHint = 'data/runtime/';
        } else {
            $dirHint = 'public/uploads/';
        }

        if ($panel === 'baota') {
            return $baotaDir . '（' . $dirHint . '）';
        }
        if ($panel === 'iis' || $panel === 'windows') {
            return '给 IIS 应用程序池标识对 ' . $dirHint . ' 写权限（修改权限）';
        }

        return 'chmod/chown 确保 Web 用户可写 ' . $dirHint . '（常见 755/775）';
    }

    if ($key === 'cli_subprocess') {
        if ($panel === 'baota') {
            return '软件商店 → 已安装 → 你的 PHP 版本 → 设置 →「禁用函数」→ 从列表删掉 exec、shell_exec、proc_open → 保存 → 重载 PHP';
        }
        if ($panel === 'iis' || $panel === 'windows') {
            return '编辑该站点 php.ini 的 disable_functions，去掉 exec / shell_exec / proc_open 后回收应用程序池';
        }

        return '编辑 Web 实际加载的 php.ini：disable_functions 中去掉 exec、shell_exec、proc_open，然后重载 php-fpm / Web';
    }

    if ($extName === '') {
        if ($panel === 'baota') {
            return '按宝塔「软件商店 → PHP → 设置」排查后重启 PHP';
        }

        return '按当前面板/发行说明安装缺失组件后重启 PHP';
    }

    if ($panel === 'baota') {
        return $baotaExt . $extName;
    }
    if ($panel === 'nginx') {
        return $nginxExt . '（' . $extName . '）';
    }
    if ($panel === 'apache') {
        return $apacheExt . '（' . $extName . '）';
    }
    if ($panel === 'iis') {
        return $iisExt . '（' . $extName . '）';
    }
    if ($panel === 'windows') {
        return $winExt . '（' . $extName . '）';
    }

    return $genericExt . '（' . $extName . '）';
}

/** disable_functions 是否禁止某函数（含未编译进 PHP 的情况） */
function pivark_preflight_function_allowed(string $function): bool
{
    if (!function_exists($function)) {
        return false;
    }
    $disabled = ini_get('disable_functions');
    if (!is_string($disabled) || trim($disabled) === '') {
        return true;
    }
    $list = array_map(
        static function ($fn) {
            return strtolower(trim((string) $fn));
        },
        explode(',', $disabled)
    );

    return !in_array(strtolower($function), $list, true);
}

/** 安装/升级偶发需要的 PHP 子进程能力（任一可用即可） */
function pivark_preflight_cli_subprocess_ok(): bool
{
    foreach (['exec', 'shell_exec', 'proc_open'] as $fn) {
        if (pivark_preflight_function_allowed($fn)) {
            return true;
        }
    }

    return false;
}

/**
 * 环境总指引（多行步骤文案）。
 *
 * @return list<string>
 */
function pivark_preflight_env_steps(string $panel): array
{
    if ($panel === 'baota') {
        return [
            '检测到宝塔环境。按下面顺序操作后，回到本页点击「重新检测环境」：',
            '1. 登录宝塔 → 软件商店 → 已安装 → 点击站点使用的 PHP 版本 → 设置',
            '2. 切到「安装扩展」，对下面每一项点「安装」',
            '3. 安装完成后点「服务」→「重载配置」或「重启」PHP-FPM',
            '4. 网站 → 你的站点 → PHP 版本，确认与上一步是同一版本',
            '5. 返回本安装页，点击「重新检测环境」，全部必选项为 ✓ 后再「开始安装」',
        ];
    }
    if ($panel === 'nginx') {
        return [
            '检测到 Nginx + PHP-FPM。按下面处理缺项后刷新本页：',
            '1. 用 phpinfo() 或 php --ini 确认 Web 实际 php.ini 路径',
            '2. 启用缺失扩展（extension=…）并保存',
            '3. systemctl reload php-fpm（或 restart php*-fpm）',
            '4. 确认站点 root 指向 public/，data 与 public/uploads 可写',
        ];
    }
    if ($panel === 'apache') {
        return [
            '检测到 Apache。按下面处理缺项后刷新本页：',
            '1. 确认站点使用的 PHP 模块 / php-fpm 版本',
            '2. 在对应 php.ini 启用缺失扩展',
            '3. apachectl graceful 或 systemctl reload httpd/apache2',
            '4. 确认 DocumentRoot 为 public/，目录可写',
        ];
    }
    if ($panel === 'iis' || $panel === 'windows') {
        return [
            '检测到 Windows / IIS 环境。按下面处理缺项后刷新本页：',
            '1. IIS 管理器 → 处理程序映射，确认 PHP 版本 ≥ 8.1',
            '2. 编辑该 PHP 的 php.ini，启用缺失扩展（pdo_mysql、mbstring、openssl、curl 等）',
            '3. 回收应用程序池；必要时重启 IIS（iisreset）',
            '4. 给站点标识对 data/、data/runtime/、public/uploads/ 写权限',
        ];
    }

    return [
        '未识别专用面板，按通用 Linux 指引处理：',
        '1. 以站点 phpinfo 显示的 php.ini 为准（Web 与 CLI 可能不同）',
        '2. 安装/启用缺失 PHP 扩展后重启 PHP-FPM / Web 服务',
        '3. 确保 data/、data/runtime/、public/uploads/ 对 Web 用户可写',
    ];
}

/**
 * 框架启动前必检项（与安装向导环境检测必装扩展对齐）。
 *
 * @return list<array{key:string,label:string,ok:bool,detail:string,level:string,fix:string,fix_baota:string}>
 */
function pivark_preflight_critical_checks(?string $root = null): array
{
    $root = $root !== null && $root !== ''
        ? rtrim(str_replace('\\', '/', $root), '/') . '/'
        : rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/') . '/';

    $env = pivark_preflight_detect_env();
    $panel = $env['panel'];

    $ext = static function (string $name): bool {
        return extension_loaded($name);
    };

    $writable = static function (string $dir): bool {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return is_writable($dir);
    };

    $uploads = $root . 'public/uploads';

    $row = static function (string $key, string $label, bool $ok, string $detail) use ($panel): array {
        $fix = pivark_preflight_fix_hint($panel, $key);

        return [
            'key'        => $key,
            'label'      => $label,
            'ok'         => $ok,
            'detail'     => $detail,
            'level'      => 'required',
            'fix'        => $fix,
            // 兼容旧字段名 fix_baota（向导/文档历史别名）
            'fix_baota'  => $fix,
        ];
    };

    return [
        $row('php', 'PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION),
        $row('pdo', 'PDO MySQL', $ext('pdo_mysql'), $ext('pdo_mysql') ? 'ok' : 'missing'),
        $row('json', 'JSON', $ext('json'), $ext('json') ? 'ok' : 'missing'),
        $row('mbstring', 'mbstring', $ext('mbstring'), $ext('mbstring') ? 'ok' : 'missing'),
        $row('openssl', 'OpenSSL', $ext('openssl'), $ext('openssl') ? 'ok' : 'missing'),
        $row('curl', 'cURL', $ext('curl'), $ext('curl') ? 'ok' : 'missing'),
        $row('data', 'data/ 可写', $writable($root . 'data'), $root . 'data'),
        $row('runtime', 'data/runtime/ 可写', $writable($root . 'data/runtime'), $root . 'data/runtime'),
        $row('uploads', 'public/uploads/ 可写', $writable($uploads), $uploads),
    ];
}

/** @return list<string> */
function pivark_preflight_required_keys(): array
{
    return ['php', 'pdo', 'json', 'mbstring', 'openssl', 'curl', 'data', 'runtime', 'uploads'];
}

/**
 * @param list<array{key:string,label:string,ok:bool,detail:string,level:string,fix?:string,fix_baota?:string}> $checks
 * @return list<array{key:string,label:string,ok:bool,detail:string,level:string,fix?:string,fix_baota?:string}>
 */
function pivark_preflight_missing_required(array $checks): array
{
    $out = [];
    foreach ($checks as $row) {
        if (!empty($row['ok'])) {
            continue;
        }
        if (($row['level'] ?? '') !== 'required') {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * 缺必装项：仍先打开安装页（许可 → 环境），不要一上来空白/吓退。
 * 全绿则静默返回，交给完整向导。
 */
function pivark_preflight_abort_if_unmet(?string $root = null): void
{
    $checks = pivark_preflight_critical_checks($root);
    $missing = pivark_preflight_missing_required($checks);
    if ($missing === []) {
        return;
    }

    $env = pivark_preflight_detect_env();
    $panelLabel = $env['label'];

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store');
    }

    $h = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $rowsHtml = '';
    foreach ($missing as $row) {
        $fix = (string) ($row['fix'] ?? $row['fix_baota'] ?? '');
        $tip = $fix !== ''
            ? '<span class="env-help" tabindex="0" aria-label="怎么打开"><span class="env-help-mark">?</span>'
            . '<span class="env-help-pop" role="tooltip"><strong>怎么处理</strong><br>' . $h($fix) . '</span></span>'
            : '';
        $rowsHtml .= '<tr>'
            . '<td><span class="label-with-help">' . $h((string) $row['label']) . $tip . '</span></td>'
            . '<td>' . $h((string) $row['detail']) . '</td>'
            . '<td class="fix-cell">' . $h($fix) . '</td>'
            . '</tr>';
    }

    $steps = pivark_preflight_env_steps($env['panel']);
    $stepsHtml = '<ol class="steps">';
    foreach ($steps as $i => $step) {
        if ($i === 0) {
            $stepsHtml .= '<li class="lead">' . $h($step) . '</li>';
            continue;
        }
        $stepsHtml .= '<li>' . $h($step) . '</li>';
    }
    $stepsHtml .= '</ol>';

    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>PivArk 安装向导</title>'
        . '<style>'
        . 'html,body{height:100%;}'
        . 'body{font-family:"PingFang SC","Microsoft YaHei",Segoe UI,sans-serif;margin:0;min-height:100%;display:flex;align-items:center;justify-content:center;'
        . 'padding:24px 16px;box-sizing:border-box;background:linear-gradient(180deg,#eef3fb 0%,#f5f7fa 220px);color:#303133}'
        . '.wrap{width:100%;max-width:860px;background:#fff;border-radius:12px;box-shadow:0 8px 28px rgba(30,60,114,.08);padding:32px 36px 28px;box-sizing:border-box}'
        . 'h1{font-size:24px;margin:0 0 6px;color:#1e3c72;letter-spacing:.02em}'
        . '.sub{color:#909399;margin:0 0 22px;font-size:13px;line-height:1.6}'
        . '.progress{display:flex;gap:4px;margin:0 0 24px;padding:0 0 16px;border-bottom:1px solid #edf0f5}'
        . '.progress span{flex:1;text-align:center;font-size:12px;color:#909399;position:relative}'
        . '.progress span::before{content:attr(data-n);display:block;width:26px;height:26px;border-radius:50%;background:#e4e7ed;color:#909399;line-height:26px;margin:0 auto 8px;font-weight:600}'
        . '.progress span.on{color:#303133;font-weight:600}'
        . '.progress span.on::before{background:#409eff;color:#fff;box-shadow:0 0 0 4px rgba(64,158,255,.15)}'
        . '.progress span.done::before{background:#67c23a;color:#fff}'
        . '.panel{display:none}.panel.on{display:block}'
        . '.license{background:#f8fafc;border:1px solid #e4e7ed;border-radius:10px;padding:16px 18px;max-height:240px;overflow:auto;font-size:13px;line-height:1.75;color:#606266;margin-bottom:14px}'
        . '.check{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;background:#f0f7ff;border:1px solid #c6e2ff;border-radius:10px;font-size:14px}'
        . '.nav{display:flex;justify-content:flex-end;gap:8px;margin-top:22px;padding-top:18px;border-top:1px solid #edf0f5}'
        . 'button{height:36px;padding:0 16px;border-radius:8px;border:1px solid #dcdfe6;background:#fff;cursor:pointer;font-size:13px}'
        . 'button.primary{background:#409eff;border-color:#409eff;color:#fff}'
        . 'button:disabled{opacity:.45;cursor:not-allowed}'
        . '.badge{display:inline-block;padding:2px 10px;border-radius:999px;background:#20a53a;color:#fff;font-size:12px;margin-bottom:10px}'
        . 'table{width:100%;border-collapse:collapse;font-size:13px;border:1px solid #ebeef5;border-radius:10px;overflow:hidden}'
        . 'th,td{border-top:1px solid #ebeef5;padding:11px 12px;text-align:left;vertical-align:top}'
        . 'th{background:#f5f7fa;color:#606266;font-weight:600}'
        . '.label-with-help{display:inline-flex;align-items:center;gap:6px}'
        . '.env-help{position:relative;display:inline-flex;width:16px;height:16px;flex-shrink:0}'
        . '.env-help-mark{width:16px;height:16px;border-radius:50%;background:#909399;color:#fff;font-size:11px;line-height:16px;text-align:center;cursor:help;font-weight:700}'
        . '.env-help-pop{display:none;position:absolute;left:50%;bottom:calc(100% + 8px);transform:translateX(-50%);width:280px;padding:10px 12px;background:#1f2937;color:#f9fafb;border-radius:8px;font-size:12px;line-height:1.55;box-shadow:0 8px 20px rgba(0,0,0,.18);z-index:5}'
        . '.env-help-pop::after{content:"";position:absolute;left:50%;top:100%;transform:translateX(-50%);border:6px solid transparent;border-top-color:#1f2937}'
        . '.env-help:hover .env-help-pop,.env-help:focus .env-help-pop,.env-help:focus-within .env-help-pop{display:block}'
        . '.fix-cell{color:#909399;font-size:12px;max-width:320px}'
        . '.steps{margin:16px 0 0;padding-left:1.2rem;color:#374151;font-size:13px;line-height:1.55}'
        . '.steps .lead{list-style:none;margin-left:-1.2rem;margin-bottom:8px;color:#9a3412;font-weight:600}'
        . '.note{margin-top:14px;padding:12px 14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;color:#9a3412;font-size:13px}'
        . '</style></head><body><div class="wrap">'
        . '<h1>元舟 PivArk 安装向导</h1>'
        . '<p class="sub">先阅读许可，再处理环境缺项。缺的是服务器配置，不是安装包少文件。</p>'
        . '<div class="progress">'
        . '<span class="on" data-n="1" id="prog-1">许可声明</span>'
        . '<span data-n="2" id="prog-2">环境检测</span>'
        . '</div>'
        . '<div class="panel on" id="panel-1">'
        . '<div class="license"><p><strong>Community 版许可（摘要）</strong></p>'
        . '<p>本软件按 Community 条款提供：可用于自用站点部署；商业分发、二次销售或去掉版权标识须另行授权。'
        . '继续安装即表示你已代表部署方阅读并接受完整条款（完整版见安装包内《安装说明》与官方站点）。</p>'
        . '<ul><li>请使用受支持的 PHP / MySQL 环境</li><li>请勿将未授权版本用于商业转售</li><li>安装完成后请妥善保管管理员账号</li></ul></div>'
        . '<label class="check"><input type="checkbox" id="agree"> <span>我已阅读并同意上述许可声明</span></label>'
        . '<div class="nav"><button type="button" class="primary" id="btn-next" disabled>下一步</button></div>'
        . '</div>'
        . '<div class="panel" id="panel-2">'
        . '<div class="badge">已识别环境：' . $h($panelLabel) . '</div>'
        . '<p class="sub" style="margin-bottom:12px">下列必选项未通过。把鼠标移到灰色 <strong>?</strong> 上可看怎么开；处理完后点「重新检测」。</p>'
        . '<table><thead><tr><th>检测项</th><th>当前</th><th>常见处理</th></tr></thead><tbody>'
        . $rowsHtml
        . '</tbody></table>'
        . $stepsHtml
        . '<div class="note">改完扩展 / 禁用函数 / 目录权限后，请重载 PHP（宝塔：PHP → 服务 → 重载），再点「重新检测」。Web 与 CLI 的 php.ini 可能不同，以本页为准。</div>'
        . '<div class="nav">'
        . '<button type="button" id="btn-back">上一步</button>'
        . '<button type="button" class="primary" id="btn-recheck">重新检测</button>'
        . '</div></div>'
        . '<script>(function(){'
        . 'var agree=document.getElementById("agree"),btn=document.getElementById("btn-next");'
        . 'agree.addEventListener("change",function(){btn.disabled=!agree.checked;});'
        . 'btn.addEventListener("click",function(){if(!agree.checked)return;'
        . 'document.getElementById("panel-1").classList.remove("on");'
        . 'document.getElementById("panel-2").classList.add("on");'
        . 'document.getElementById("prog-1").classList.remove("on");document.getElementById("prog-1").classList.add("done");'
        . 'document.getElementById("prog-2").classList.add("on");});'
        . 'document.getElementById("btn-back").addEventListener("click",function(){'
        . 'document.getElementById("panel-2").classList.remove("on");'
        . 'document.getElementById("panel-1").classList.add("on");'
        . 'document.getElementById("prog-2").classList.remove("on");'
        . 'document.getElementById("prog-1").classList.remove("done");document.getElementById("prog-1").classList.add("on");});'
        . 'document.getElementById("btn-recheck").addEventListener("click",function(){location.reload();});'
        . '})();</script>'
        . '</div></body></html>';
    exit;
}
