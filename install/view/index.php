<?php
$channel = app(\install\service\InstallService::class)->channelBranding();
$title = $channel['title'] !== '' ? $channel['title'] : '安装向导 - 元舟 PivArk';
$channelSubtitle = $channel['subtitle'] ?? '';
$installServerSoftware = $installServerSoftware ?? 'Unknown';
$serverInfo = app(\install\service\InstallService::class)->getServerInfo($installServerSoftware);
$envReport  = app(\install\service\InstallService::class)->environmentReport();
$checks     = $envReport['checks'] ?? [];
$envMeta    = $envReport['meta'] ?? [];
$baotaSteps = $envReport['baota_steps'] ?? [];
$rewriteSetupGuides = [
    'nginx'  => \install\support\InstallEditionRules::rewriteSetupGuideZh('nginx'),
    'apache' => \install\support\InstallEditionRules::rewriteSetupGuideZh('apache'),
    'iis'    => \install\support\InstallEditionRules::rewriteSetupGuideZh('iis'),
];
$rewriteGuideInitial = $rewriteSetupGuides[$serverInfo['server'] ?? '']
    ?? $rewriteSetupGuides['nginx'];
$canInstall = (bool) ($envMeta['can_install'] ?? false);
$licenseTerms = app(\install\service\InstallService::class)->communityLicenseTerms();
$enhancementPack = app(\install\service\InstallService::class)->enhancementPackCatalog();
$enhancementPackTrialDays = max(1, (int) config('plugin.commercial.default_trial_days', 90));
$dbDefaults = app(\install\service\InstallService::class)->readExistingDatabaseDefaults();
$isReinstall = app(\install\service\InstallService::class)->hasPriorInstallArtifacts();
$installConfigDomain = (string) (\think\facade\Request::host() ?: 'localhost');
$installServerConfigs = [
    'nginx'  => app(\install\service\InstallService::class)->generateServerConfig('nginx', $installConfigDomain),
    'apache' => app(\install\service\InstallService::class)->generateServerConfig('apache', $installConfigDomain),
    'iis'    => app(\install\service\InstallService::class)->generateServerConfig('iis', $installConfigDomain),
];
$installServerConfigInitial = $installServerConfigs[$serverInfo['server'] ?? '']
    ?? $installServerConfigs['nginx'];
$serverLabels = [
    'apache'      => 'Apache',
    'nginx'       => 'Nginx',
    'iis'         => 'IIS',
    'php-builtin' => 'PHP 内置服务器',
    'unknown'     => '未知',
    'litespeed'   => 'LiteSpeed',
];
$serverLabel = $serverLabels[$serverInfo['server']] ?? '未知';
$serverBadgeClass = match ($serverInfo['server']) {
    'apache' => 'apache',
    'nginx'  => 'nginx',
    'iis'    => 'iis',
    default  => 'other',
};
$requiredTotal = 0;
$requiredOk = 0;
$recommendedTotal = 0;
$recommendedOk = 0;
foreach ($checks as $c) {
    $level = (string) ($c['level'] ?? 'required');
    $ok = !empty($c['ok']);
    if ($level === 'recommended') {
        $recommendedTotal++;
        if ($ok) {
            $recommendedOk++;
        }
    } else {
        $requiredTotal++;
        if ($ok) {
            $requiredOk++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?></title>
<style>
html{height:100%;}
body{
  min-height:100%;
  min-height:100vh;
  min-height:100dvh;
  margin:0;
  color:#303133;
  font-family:"PingFang SC","Microsoft YaHei",sans-serif;
  background:
    radial-gradient(1200px 480px at 50% -10%, rgba(64,158,255,.14), transparent 60%),
    linear-gradient(165deg, #e8eef8 0%, #f3f6fb 42%, #eef2f7 100%);
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  box-sizing:border-box;
  padding:28px 16px;
}
.install-stage{width:100%;max-width:860px;}
.wrap{
  width:100%;
  max-width:860px;
  margin:0;
  background:#fff;
  border-radius:16px;
  border:1px solid rgba(30,60,114,.06);
  box-shadow:0 18px 48px rgba(30,60,114,.10), 0 2px 8px rgba(30,60,114,.04);
  padding:36px 40px 30px;
  box-sizing:border-box;
  max-height:calc(100vh - 56px);
  max-height:calc(100dvh - 56px);
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
}
.wrap-head{text-align:center;margin:0 0 8px;}
.wrap-head h1{font-size:24px;margin:0 0 8px;color:#1e3c72;letter-spacing:.02em;font-weight:700;}
.wrap-head .sub{color:#909399;margin:0 0 22px;font-size:13px;line-height:1.6;}
h1{font-size:24px;margin:0 0 6px;color:#1e3c72;letter-spacing:.02em;}
.sub{color:#909399;margin-bottom:26px;font-size:13px;}
.channel-footer{margin-top:12px;padding:0;text-align:center;color:#909399;font-size:12px;line-height:1.6;}
.ok{color:#16b777}.bad{color:#ff5722}.warn{color:#e6a23c}
.pv-btn:disabled{opacity:.45;cursor:not-allowed;}
.progress-bar{display:flex;margin-bottom:28px;padding:10px 0 18px;border-bottom:1px solid #edf0f5;gap:4px;}
.progress-item{flex:1;text-align:center;position:relative;min-width:0;}
.progress-item::after{content:'';position:absolute;top:13px;right:-50%;width:100%;height:2px;background:#e4e7ed;z-index:0;}
.progress-item:last-child::after{display:none;}
.progress-item.done::after{background:#67c23a;}
.progress-item::before{content:attr(data-step);display:block;width:26px;height:26px;border-radius:50%;background:#e4e7ed;color:#909399;font-size:12px;line-height:26px;text-align:center;margin:0 auto 8px;position:relative;z-index:1;font-weight:600;}
.progress-item.active::before{background:#409eff;color:#fff;box-shadow:0 0 0 4px rgba(64,158,255,.15);}
.progress-item.done::before{background:#67c23a;color:#fff;}
.progress-item span{display:block;font-size:12px;color:#909399;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:0 2px;}
.progress-item.active span,.progress-item.done span{color:#303133;font-weight:600;}
.wizard-panel{display:none;}
.wizard-panel.active{display:block;animation:fadeIn .25s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.wizard-panel h3{font-size:17px;margin:0 0 14px;color:#1e3c72;font-weight:600;}
.wizard-panel .step-desc{color:#909399;font-size:13px;margin:-6px 0 16px;line-height:1.6;}
.pv-input{width:100%;box-sizing:border-box;height:40px;padding:0 12px;border:1px solid #dcdfe6;border-radius:8px;margin-bottom:10px;font-size:14px;transition:border-color .2s,box-shadow .2s;}
.pv-input:focus{outline:none;border-color:#409eff;box-shadow:0 0 0 3px rgba(64,158,255,.12);}
.pv-btn{display:inline-flex;align-items:center;justify-content:center;padding:0 16px;height:36px;line-height:1;border:1px solid #dcdfe6;border-radius:8px;background:#fff;color:#606266;font-size:13px;cursor:pointer;transition:all .2s;}
.pv-btn:hover{border-color:#409eff;color:#409eff;}
.pv-btn-primary{background:#409eff;border-color:#409eff;color:#fff;}
.pv-btn-primary:hover{opacity:.92;color:#fff;border-color:#409eff;box-shadow:0 4px 12px rgba(64,158,255,.25);}
.pv-btn-block{min-width:140px;}
.pv-toast{position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:10px 18px;border-radius:8px;color:#fff;font-size:14px;z-index:9999;opacity:0;transition:opacity .2s;max-width:90vw;box-shadow:0 8px 24px rgba(0,0,0,.12);}
.pv-toast.show{opacity:1;}
.pv-toast.ok{background:#67c23a;}
.pv-toast.err{background:#f56c6c;}
.pv-loading-mask{position:fixed;inset:0;background:rgba(255,255,255,.72);z-index:9998;display:none;align-items:center;justify-content:center;}
.pv-loading-mask.show{display:flex;}
.wizard-nav{display:flex;justify-content:space-between;align-items:center;margin-top:28px;padding-top:20px;border-top:1px solid #edf0f5;gap:12px;position:sticky;bottom:0;background:linear-gradient(180deg,rgba(255,255,255,0),#fff 28%);padding-bottom:2px;z-index:2;}
.wizard-nav .nav-right{display:flex;gap:8px;margin-left:auto;}
.license-box{background:#f8fafc;border:1px solid #e4e7ed;border-radius:10px;padding:18px 20px;margin:0 0 16px;max-height:min(280px,36vh);overflow-y:auto;font-size:13px;line-height:1.8;color:#606266;}
.license-box h4{margin:0 0 10px;font-size:15px;color:#1e3c72;}
.license-box p{margin:0 0 12px;}
.license-box ul{margin:0;padding-left:18px;}
.license-box li{margin:6px 0;}
.license-check{display:flex;align-items:flex-start;gap:10px;padding:14px 16px;background:#f0f7ff;border:1px solid #c6e2ff;border-radius:10px;font-size:14px;color:#303133;}
.license-check input{margin-top:4px;flex-shrink:0;width:16px;height:16px;}
.env-overview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px;}
.env-card{background:#f8fafc;border:1px solid #ebeef5;border-radius:10px;padding:14px 16px;}
.env-card-label{font-size:12px;color:#909399;margin-bottom:6px;}
.env-card-value{font-size:15px;font-weight:600;color:#303133;word-break:break-all;}
.env-status-banner{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-radius:10px;margin-bottom:14px;font-size:13px;}
.env-status-banner.ok{background:#f0f9eb;border:1px solid #c2e7b0;color:#2d6a32;}
.env-status-banner.bad{background:#fef0f0;border:1px solid #fbc4c4;color:#c45656;}
.env-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.env-stat{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:12px;background:#f4f4f5;color:#606266;}
.env-stat strong{color:#303133;}
.env-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
.env-table-wrap{border:1px solid #ebeef5;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.03);}
.env-table{width:100%;border-collapse:collapse;font-size:13px;}
.env-table th,.env-table td{padding:11px 14px;text-align:left;vertical-align:middle;border-bottom:1px solid #ebeef5;}
.env-table th{background:#f5f7fa;color:#606266;font-weight:600;font-size:12px;}
.env-table tbody tr:last-child td{border-bottom:none;}
.env-table tbody tr.row-pass{background:#fcfffc;}
.env-table tbody tr.row-fail{background:#fffafa;}
.env-table tbody tr.row-warn{background:#fffdf5;}
.env-table .col-status{width:108px;text-align:center;}
.env-table .col-level{width:72px;text-align:center;}
.env-label{display:inline-flex;align-items:center;gap:6px;max-width:100%;}
.env-help{position:relative;display:inline-flex;width:16px;height:16px;flex-shrink:0;}
.env-help-mark{width:16px;height:16px;border-radius:50%;background:#909399;color:#fff;font-size:11px;line-height:16px;text-align:center;cursor:help;font-weight:700;}
.env-help-pop{display:none;position:absolute;left:50%;bottom:calc(100% + 8px);transform:translateX(-50%);width:280px;padding:10px 12px;background:#1f2937;color:#f9fafb;border-radius:8px;font-size:12px;line-height:1.55;box-shadow:0 8px 20px rgba(0,0,0,.18);z-index:20;text-align:left;font-weight:400;white-space:normal;}
.env-help-pop::after{content:"";position:absolute;left:50%;top:100%;transform:translateX(-50%);border:6px solid transparent;border-top-color:#1f2937;}
.env-help:hover .env-help-pop,.env-help:focus .env-help-pop,.env-help:focus-within .env-help-pop{display:block;}
.pill{display:inline-flex;align-items:center;justify-content:center;min-width:58px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.4;}
.pill-ok{background:#e8f7ee;color:#16b777;}
.pill-bad{background:#fdecea;color:#f56c6c;}
.pill-warn{background:#fdf6ec;color:#e6a23c;}
.pill-level{background:#eef2ff;color:#5b6ee1;}
.pill-level-rec{background:#f4f4f5;color:#909399;}
.detail-cell{color:#909399;font-size:12px;word-break:break-all;max-width:280px;}
.bt-panel{display:inline-block;background:#20a53a;color:#fff;font-size:12px;padding:2px 10px;border-radius:12px;margin-left:6px;vertical-align:middle;}
.bt-guide{background:#f0faf3;border:1px solid #b7ebc6;border-radius:10px;padding:14px 16px;margin:14px 0 0;font-size:13px;line-height:1.7;color:#333;}
.bt-guide ol{margin:8px 0 0 18px;padding:0;}
.bt-guide li{margin:4px 0;}
.db-panel{border:1px solid #ebeef5;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.03);}
.db-tip{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;background:#f0f7ff;border-bottom:1px solid #d9ecff;font-size:13px;line-height:1.65;color:#3d5a80;}
.db-tip strong{color:#1e3c72;}
.db-section{padding:18px 20px;border-bottom:1px solid #edf0f5;}
.db-section:last-child{border-bottom:none;}
.db-section-title{font-size:13px;font-weight:600;color:#606266;margin:0 0 14px;display:flex;align-items:center;gap:8px;}
.db-section-title::before{content:'';width:3px;height:14px;border-radius:2px;background:#409eff;flex-shrink:0;}
.db-form-grid{display:grid;grid-template-columns:1fr 120px;gap:12px 14px;}
.db-form-grid.single{grid-template-columns:1fr;}
.form-field{margin:0;}
.form-field label{display:block;font-size:12px;color:#909399;margin-bottom:6px;font-weight:500;}
.form-field .pv-input{margin-bottom:0;}
.form-field .field-hint{display:block;font-size:11px;color:#c0c4cc;margin-top:5px;line-height:1.4;}
.db-test-banner{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-radius:10px;font-size:13px;margin-top:16px;}
.db-test-banner.pending{background:#f8fafc;border:1px solid #ebeef5;color:#909399;}
.db-test-banner.ok{background:#f0f9eb;border:1px solid #c2e7b0;color:#2d6a32;}
.db-test-banner.bad{background:#fef0f0;border:1px solid #fbc4c4;color:#c45656;}
.db-test-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px;}
.db-test-status{display:flex;align-items:center;gap:8px;flex:1;min-width:0;}
.db-test-status .status-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;background:#c0c4cc;}
.db-test-banner.ok .status-dot{background:#67c23a;}
.db-test-banner.bad .status-dot{background:#f56c6c;}
.db-test-banner.pending .status-dot{background:#e6a23c;}
.db-tip.warn{background:#fdf6ec;border-bottom-color:#faecd8;color:#7a5c2e;}
.db-tip.warn strong{color:#b88230;}
.password-meter{height:4px;background:#ebeef5;border-radius:2px;margin-top:8px;overflow:hidden;}
.password-meter-bar{height:100%;width:0;border-radius:2px;transition:width .2s,background .2s;background:#f56c6c;}
.password-meter-bar.weak{width:33%;background:#f56c6c;}
.password-meter-bar.mid{width:66%;background:#e6a23c;}
.password-meter-bar.good{width:100%;background:#67c23a;}
.pv-pass-wrap{position:relative;}
.pv-pass-wrap .pv-input{padding-right:72px;}
.pv-pass-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#909399;font-size:12px;line-height:1;padding:6px 8px;cursor:pointer;border-radius:6px;}
.pv-pass-toggle:hover{color:#409eff;background:#f0f7ff;}
.pv-pass-toggle:focus-visible{outline:2px solid #b3d8ff;outline-offset:1px;}
.field-hint.bad{color:#f56c6c;}
.admin-preview{display:flex;align-items:center;gap:12px;padding:14px 16px;background:#f8fafc;border:1px solid #ebeef5;border-radius:10px;margin-top:16px;}
.admin-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#409eff,#1e3c72);color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;flex-shrink:0;}
.admin-preview-meta{min-width:0;}
.admin-preview-name{font-size:14px;font-weight:600;color:#303133;}
.admin-preview-user{font-size:12px;color:#909399;margin-top:2px;}
.review-panel{border:1px solid #ebeef5;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.03);margin-top:16px;}
.review-head{padding:14px 18px;background:#f5f7fa;border-bottom:1px solid #edf0f5;}
.review-head-title{font-size:14px;font-weight:600;color:#303133;margin:0 0 4px;}
.review-head-desc{font-size:12px;color:#909399;margin:0;line-height:1.5;}
.review-list{padding:6px 0;}
.review-item{display:flex;align-items:flex-start;gap:12px;padding:12px 18px;border-bottom:1px solid #f2f4f7;}
.review-item:last-child{border-bottom:none;}
.review-icon{width:32px;height:32px;border-radius:8px;background:#f0f7ff;color:#409eff;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.review-body{flex:1;min-width:0;}
.review-label{font-size:12px;color:#909399;margin-bottom:3px;}
.review-value{font-size:14px;color:#303133;font-weight:500;word-break:break-all;line-height:1.5;}
.review-item .pill{flex-shrink:0;margin-top:4px;}
.plugin-list{display:flex;flex-direction:column;gap:8px;}
.plugin-list.enhancement-pack-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
.plugin-option{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;background:#f8fafc;border:1px solid #ebeef5;border-radius:10px;font-size:13px;color:#606266;cursor:pointer;transition:border-color .2s,box-shadow .2s;}
.plugin-option:hover{border-color:#c6e2ff;background:#fff;}
.plugin-option:has(input:checked){border-color:#b3d8ff;background:#f5faff;box-shadow:0 0 0 1px rgba(64,158,255,.08);}
.plugin-option input{margin-top:2px;flex-shrink:0;}
.plugin-option-body{flex:1;min-width:0;}
.plugin-option-head{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:4px;}
.plugin-option-head strong{color:#303133;font-size:14px;line-height:1.4;}
.plugin-option .chip-tag{font-size:11px;padding:2px 8px;border-radius:999px;line-height:1.5;white-space:nowrap;}
.plugin-option .chip-tag.price{background:#e8f7ee;color:#16b777;}
.plugin-option .chip-tag.table{background:#ecf5ff;color:#409eff;}
.plugin-option .chip-tag.muted{background:#f4f4f5;color:#909399;}
.plugin-option-hint{display:block;font-size:12px;color:#909399;line-height:1.55;}
.enhancement-pack-total{font-size:12px;color:#606266;margin:0 0 12px;padding:8px 12px;background:#f5f7fa;border-radius:8px;border:1px solid #ebeef5;}
.enhancement-pack-total strong{color:#303133;}
.plugin-chip{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;background:#f8fafc;border:1px solid #ebeef5;border-radius:8px;font-size:13px;color:#606266;}
.plugin-chip strong{color:#303133;}
.plugin-chip .chip-tag{font-size:11px;padding:2px 8px;border-radius:999px;background:#e8f7ee;color:#16b777;}
.enhancement-pack-note{font-size:12px;color:#909399;line-height:1.65;margin:0 0 12px;}
.install-steps{margin-top:16px;padding:14px 16px;background:#f8fafc;border:1px solid #ebeef5;border-radius:10px;}
.install-steps-title{font-size:13px;font-weight:600;color:#606266;margin:0 0 10px;}
.install-steps ol{margin:0;padding-left:20px;font-size:13px;color:#606266;line-height:1.85;}
.install-steps li{margin:2px 0;}
.install-progress{margin-top:16px;padding:16px;background:#fff;border:1px solid #ebeef5;border-radius:10px;}
.install-progress-title{font-size:14px;font-weight:600;color:#303133;margin:0 0 12px;}
.install-migrate-bar{display:none;height:6px;background:#eef2f6;border-radius:999px;overflow:hidden;margin:0 0 12px;}
.install-migrate-bar.show{display:block;}
.install-migrate-bar-fill{height:100%;width:0;background:linear-gradient(90deg,#409eff,#67c23a);transition:width .25s ease;}
.install-progress-list{list-style:none;margin:0;padding:0;}
.install-progress-item{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid #f0f2f5;font-size:13px;color:#909399;}
.install-progress-item:last-child{border-bottom:none;}
.install-progress-item .dot{width:10px;height:10px;border-radius:50%;background:#dcdfe6;flex-shrink:0;}
.install-progress-item .label{flex:1;min-width:0;line-height:1.45;}
.install-progress-item .label-sub{display:block;font-size:12px;color:#909399;font-weight:400;margin-top:2px;word-break:break-all;}
.install-progress-item .progress-meta{font-size:12px;color:#909399;white-space:nowrap;flex-shrink:0;min-width:88px;text-align:right;}
.install-progress-item.running .progress-meta{color:#409eff;font-weight:600;}
.install-progress-item.done .progress-meta{color:#67c23a;}
.install-progress-item.running{color:#303133;font-weight:500;}
.install-progress-item.running .dot{background:#409eff;box-shadow:0 0 0 3px rgba(64,158,255,.22);}
.install-progress-item.done{color:#606266;}
.install-progress-item.done .dot{background:#16b777;}
.install-progress-item.error{color:#f56c6c;}
.install-progress-item.error .dot{background:#f56c6c;}
.install-success-panel{display:none;margin-top:8px;}
.install-success-panel.show{display:block;animation:fadeIn .35s ease;}
.install-success-hero{text-align:center;padding:28px 16px 20px;background:linear-gradient(180deg,#f0f9eb 0%,#fff 100%);border:1px solid #c2e7b0;border-radius:12px;margin-bottom:18px;}
.install-success-hero .success-icon{font-size:42px;line-height:1;margin-bottom:10px;}
.install-success-hero h3{margin:0 0 8px;font-size:22px;color:#1e3c72;}
.install-success-hero p{margin:0;font-size:14px;color:#606266;line-height:1.7;}
.install-success-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:20px;}
.install-success-actions .pv-btn{min-width:148px;height:40px;font-size:14px;}
.install-success-section{border:1px solid #ebeef5;border-radius:10px;padding:16px 18px;margin-bottom:14px;background:#fafbfc;}
.install-success-section h4{margin:0 0 10px;font-size:14px;color:#303133;font-weight:600;}
.install-success-links{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
.install-success-link{display:block;padding:12px 14px;border:1px solid #e4e7ed;border-radius:8px;background:#fff;text-decoration:none;color:inherit;transition:border-color .2s,box-shadow .2s;}
.install-success-link:hover{border-color:#409eff;box-shadow:0 4px 12px rgba(64,158,255,.12);}
.install-success-link strong{display:block;font-size:14px;color:#1e3c72;margin-bottom:4px;}
.install-success-link span{font-size:12px;color:#909399;line-height:1.5;}
.install-success-cleanup{background:#fff7e6;border-color:#faecd8;}
.install-success-cleanup p{margin:0 0 10px;font-size:13px;color:#7a5c2e;line-height:1.7;}
.install-success-cleanup ul{margin:0;padding-left:18px;font-size:13px;color:#7a5c2e;line-height:1.75;}
.install-success-cleanup code{font-size:12px;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #f5dab1;}
.install-wizard-done .progress-item[data-step="5"]::before{content:"✓";}
@media (max-width:720px){
  .install-success-links{grid-template-columns:1fr;}
}
.site-preview{display:flex;align-items:center;gap:12px;padding:14px 16px;background:linear-gradient(135deg,#f0f7ff 0%,#f8fafc 100%);border:1px solid #d9ecff;border-radius:10px;margin-top:16px;}
.site-preview-logo{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#409eff,#1e3c72);color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;flex-shrink:0;}
.site-preview-meta{min-width:0;}
.site-preview-name{font-size:16px;font-weight:600;color:#1e3c72;}
.site-preview-theme{font-size:12px;color:#909399;margin-top:3px;}
.config-box{background:#f8fafc;border:1px solid #ebeef5;border-radius:10px;padding:16px;margin-top:16px;}
.config-box textarea{width:100%;height:120px;border:1px solid #e0e0e0;border-radius:8px;padding:10px;font-family:Consolas,monospace;font-size:12px;box-sizing:border-box;background:#fff;}
.config-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:12px;}
.config-head span{font-size:13px;color:#606266;font-weight:600;}
.btn-group{display:flex;gap:8px;margin-top:12px;}
.server-badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;}
.server-apache{background:#f3e7d8;color:#8b5a2b;}
.server-nginx{background:#e5f5e6;color:#2d5a27;}
.server-iis{background:#e8ebf4;color:#3d4f6f;}
.server-other{background:#ececec;color:#666;}
@media (max-width:720px){
  body{padding:12px 10px;align-items:flex-start;}
  .wrap{margin:0;padding:22px 16px 18px;max-height:none;border-radius:12px;box-shadow:0 10px 28px rgba(30,60,114,.08);}
  .wrap-head h1{font-size:20px;}
  .env-overview{grid-template-columns:1fr;}
  .db-form-grid{grid-template-columns:1fr;}
  .progress-item span{font-size:11px;}
  .plugin-list.enhancement-pack-grid{grid-template-columns:1fr;}
}
@media (min-width:721px) and (max-height:760px){
  body{align-items:flex-start;padding-top:20px;padding-bottom:20px;}
  .wrap{max-height:none;}
}
</style>
</head>
<body>
<div class="install-stage">
<div class="wrap">
<div class="wrap-head">
<h1><?= htmlspecialchars($channel['title'] !== '' ? $channel['title'] : '元舟 PivArk 安装向导') ?></h1>
<p class="sub"><?= htmlspecialchars($channelSubtitle !== '' ? $channelSubtitle : '共 5 步：许可声明 → 环境检测 → 数据库 → 管理员 → 站点与插件确认；点击「开始安装」后按清单逐项执行') ?></p>
<?php if (($channel['logo_url'] ?? '') !== ''): ?>
<p style="text-align:center;margin:0 0 18px;"><img src="<?= htmlspecialchars((string) $channel['logo_url']) ?>" alt="" style="max-height:48px;max-width:220px;"></p>
<?php endif; ?>
</div>

<div class="progress-bar" id="progress-bar">
    <div class="progress-item" data-step="1"><span>许可声明</span></div>
    <div class="progress-item" data-step="2"><span>环境检测</span></div>
    <div class="progress-item" data-step="3"><span>数据库</span></div>
    <div class="progress-item" data-step="4"><span>管理员</span></div>
    <div class="progress-item" data-step="5"><span>完成安装</span></div>
</div>

<form id="install-form">
<div class="wizard-panel active" id="panel-1" data-step="1">
<h3>1. 开源许可与使用声明</h3>
<p class="step-desc">继续安装前，请完整阅读 Community 版许可条款。勾选并进入下一步，即表示你代表部署方接受本声明。</p>
<div class="license-box" id="license-box">
    <h4><?= htmlspecialchars((string)($licenseTerms['title'] ?? '开源许可与使用声明')) ?></h4>
    <?php if (!empty($licenseTerms['intro'])): ?>
    <p><?= htmlspecialchars((string)$licenseTerms['intro']) ?></p>
    <?php endif; ?>
    <ul>
    <?php foreach (($licenseTerms['bullets'] ?? []) as $bullet): ?>
        <li><?= htmlspecialchars((string)$bullet) ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<label class="license-check" for="accept-license-terms">
    <input type="checkbox" name="accept_license_terms" value="1" id="accept-license-terms">
    <span><?= htmlspecialchars((string)($licenseTerms['checkbox_label'] ?? '我已阅读并同意上述开源许可与使用声明')) ?></span>
</label>
</div>

<div class="wizard-panel" id="panel-2" data-step="2">
<h3>2. 环境检测</h3>
<p class="step-desc">确认 PHP 扩展、目录权限与禁用函数。必选项全过才能配库；未通过项旁的灰色 <strong>?</strong> 悬停可看怎么打开。</p>
<div class="env-overview">
    <div class="env-card">
        <div class="env-card-label">Web 服务器</div>
        <div class="env-card-value">
            <span class="server-badge server-<?= $serverBadgeClass ?>"><?= htmlspecialchars($serverLabel) ?></span>
            <?php
            $panelLabelUi = trim((string) ($envMeta['panel_label'] ?? ''));
            if ($panelLabelUi !== ''): ?>
            <span class="bt-panel"><?= htmlspecialchars($panelLabelUi) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="env-card">
        <div class="env-card-label">PHP 版本</div>
        <div class="env-card-value"><?= htmlspecialchars((string)($envMeta['php_version'] ?? '')) ?></div>
    </div>
    <div class="env-card">
        <div class="env-card-label">运行方式</div>
        <div class="env-card-value"><?= htmlspecialchars((string)($envMeta['php_sapi'] ?? '')) ?></div>
    </div>
</div>
<div class="env-status-banner <?= $canInstall ? 'ok' : 'bad' ?>" id="env-status-banner">
    <span id="env-hint"><?= $canInstall ? '环境检测通过，可以进入下一步' : '存在未通过的必选项，请先按下方指引处理' ?></span>
    <button type="button" class="pv-btn pv-btn-primary" id="btn-recheck-env">重新检测</button>
</div>
<div class="env-stats" id="env-stats">
    <span class="env-stat">必选项 <strong id="stat-required"><?= $requiredOk ?>/<?= $requiredTotal ?></strong> 通过</span>
    <span class="env-stat">建议项 <strong id="stat-recommended"><?= $recommendedOk ?>/<?= $recommendedTotal ?></strong> 通过</span>
</div>
<div class="env-table-wrap">
<table class="env-table" id="env-check-table">
<thead>
<tr>
    <th>检测项</th>
    <th class="col-level">类型</th>
    <th class="col-status">状态</th>
    <th>详情</th>
</tr>
</thead>
<tbody id="env-check-body">
<?php foreach ($checks as $c):
    $level = (string)($c['level'] ?? 'required');
    $ok = !empty($c['ok']);
    $rowCls = $ok ? 'row-pass' : ($level === 'required' ? 'row-fail' : 'row-warn');
    $pillCls = $ok ? 'pill-ok' : ($level === 'required' ? 'pill-bad' : 'pill-warn');
    $status = $ok ? '通过' : ($level === 'required' ? '未通过' : '建议处理');
    $levelLabel = $level === 'recommended' ? '建议' : '必选';
    $levelPill = $level === 'recommended' ? 'pill-level-rec' : 'pill-level';
    $fixText = trim((string) ($c['fix'] ?? $c['fix_baota'] ?? ''));
?>
<tr class="<?= $rowCls ?>" data-key="<?= htmlspecialchars((string)($c['key'] ?? '')) ?>">
    <td>
        <span class="env-label">
            <?= htmlspecialchars((string)($c['label'] ?? '')) ?>
            <?php if (!$ok && $fixText !== ''): ?>
            <span class="env-help" tabindex="0" aria-label="怎么处理">
                <span class="env-help-mark">?</span>
                <span class="env-help-pop" role="tooltip"><strong>怎么处理</strong><br><?= htmlspecialchars($fixText) ?></span>
            </span>
            <?php endif; ?>
        </span>
    </td>
    <td class="col-level"><span class="pill <?= $levelPill ?>"><?= $levelLabel ?></span></td>
    <td class="col-status"><span class="pill <?= $pillCls ?>"><?= $status ?></span></td>
    <td class="detail-cell"><?= htmlspecialchars((string)($c['detail'] ?? '')) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if (!empty($envMeta['php_ini'])): ?>
<p style="font-size:12px;color:#909399;margin:10px 0 0;word-break:break-all;">php.ini：<?= htmlspecialchars((string)$envMeta['php_ini']) ?></p>
<?php endif; ?>
<?php if ($baotaSteps !== []): ?>
<div class="bt-guide" id="bt-guide">
    <strong><?= htmlspecialchars(trim((string) ($envMeta['panel_label'] ?? '')) !== '' ? ((string) $envMeta['panel_label'] . ' · 修复指引') : '环境修复指引') ?></strong>
    <ol>
    <?php foreach ($baotaSteps as $step): ?>
        <li><?= htmlspecialchars((string)$step) ?></li>
    <?php endforeach; ?>
    </ol>
</div>
<?php endif; ?>
<div class="config-box">
    <div class="config-head">
        <span>Web 服务器伪静态 · 先选服务器类型，再按步骤操作</span>
        <select id="config-type" class="pv-input" style="width:160px;margin-bottom:0;height:34px;">
            <option value="nginx" <?= ($serverInfo['server'] ?? '') === 'nginx' ? 'selected' : '' ?>>Nginx（宝塔伪静态）</option>
            <option value="apache" <?= ($serverInfo['server'] ?? '') === 'apache' ? 'selected' : '' ?>>Apache（.htaccess）</option>
            <option value="iis" <?= ($serverInfo['server'] ?? '') === 'iis' ? 'selected' : '' ?>>IIS（web.config）</option>
        </select>
    </div>
    <p id="config-rewrite-title" style="margin:0 0 8px;font-size:14px;font-weight:600;color:#303133;"><?= htmlspecialchars((string) ($rewriteGuideInitial['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    <p id="config-rewrite-hint" class="muted" style="margin:0 0 10px;font-size:12px;line-height:1.7;"><?= htmlspecialchars((string) ($rewriteGuideInitial['hint'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    <ol id="config-rewrite-steps" class="db-tip" style="margin:0 0 12px;padding-left:22px;line-height:1.7;">
        <?php foreach (($rewriteGuideInitial['steps'] ?? []) as $pasteStep): ?>
        <li><?= htmlspecialchars((string) $pasteStep) ?></li>
        <?php endforeach; ?>
    </ol>
    <textarea id="config-content" readonly><?= htmlspecialchars((string) $installServerConfigInitial, ENT_QUOTES, 'UTF-8') ?></textarea>
    <div class="btn-group">
        <button type="button" class="pv-btn" id="btn-copy-config">复制配置</button>
        <button type="button" class="pv-btn" id="btn-download-config">下载配置</button>
    </div>
</div>
</div>

<div class="wizard-panel" id="panel-3" data-step="3">
<h3>3. 数据库配置</h3>
<?php if ($isReinstall): ?>
<div class="db-tip warn" style="margin-bottom:12px;">
    <span>♻️</span>
    <span>检测到<strong>重装</strong>：只需删除过 <code>data/install.lock</code> 即可进入向导；旧的 <code>site.env</code> 已用于预填下方信息，安装时会自动覆盖。若库中仍有旧表，请在最后一步勾选「确认清空并覆盖」。</span>
</div>
<?php endif; ?>
<p class="step-desc">填写 MySQL 连接信息并测试通过。建议使用<strong>空数据库</strong>，安装程序将自动建表。</p>
<div class="db-panel">
    <div class="db-tip">
        <span>💡</span>
        <span>请先在宝塔 / phpMyAdmin 等面板<strong>创建空数据库</strong>，再填写下方信息。账号需具备建表与写入权限；修改任意字段后需重新测试连接。</span>
    </div>
    <div class="db-section">
        <div class="db-section-title">连接信息</div>
        <div class="db-form-grid">
            <div class="form-field">
                <label for="db-host">数据库主机</label>
                <input type="text" name="db_host" id="db-host" class="pv-input" placeholder="通常为 127.0.0.1 或 localhost" value="<?= htmlspecialchars((string) ($dbDefaults['db_host'] ?? '127.0.0.1')) ?>">
            </div>
            <div class="form-field">
                <label for="db-port">端口</label>
                <input type="text" name="db_port" id="db-port" class="pv-input" placeholder="3306" value="<?= htmlspecialchars((string) ($dbDefaults['db_port'] ?? '3306')) ?>">
            </div>
        </div>
    </div>
    <div class="db-section">
        <div class="db-section-title">数据库与账号</div>
        <div class="db-form-grid single">
            <div class="form-field">
                <label for="db-name">数据库名称 <span class="bad">*</span></label>
                <input type="text" name="db_name" id="db-name" class="pv-input" placeholder="例如 pivark" value="<?= htmlspecialchars((string) ($dbDefaults['db_name'] ?? '')) ?>" required>
                <span class="field-hint">须为已创建的空库，安装时将写入表结构</span>
            </div>
            <div class="form-field">
                <label for="db-user">用户名 <span class="bad">*</span></label>
                <input type="text" name="db_user" id="db-user" class="pv-input" placeholder="数据库用户名" value="<?= htmlspecialchars((string) ($dbDefaults['db_user'] ?? '')) ?>" required>
            </div>
            <div class="form-field">
                <label for="db-pass">密码</label>
                <input type="password" name="db_pass" id="db-pass" class="pv-input" placeholder="无密码可留空" value="<?= htmlspecialchars((string) ($dbDefaults['db_pass'] ?? '')) ?>" autocomplete="new-password">
            </div>
        </div>
    </div>
    <div class="db-section">
        <div class="db-section-title">高级选项</div>
        <div class="db-form-grid single">
            <div class="form-field">
                <label for="db-prefix">表前缀</label>
                <input type="text" name="db_prefix" id="db-prefix" class="pv-input" placeholder="pv_" value="<?= htmlspecialchars((string) ($dbDefaults['db_prefix'] ?? 'pv_')) ?>">
                <span class="field-hint">同一数据库多站点时可改前缀；一般保持默认即可</span>
            </div>
        </div>
    </div>
</div>
<div class="db-tip warn" id="db-existing-hint-step3" style="display:none;margin-top:12px;">
    <span>⚠️</span>
    <span id="db-existing-hint-step3-text"></span>
</div>
<div class="db-test-banner pending" id="db-test-banner">
    <div class="db-test-status">
        <span class="status-dot"></span>
        <span id="db-test-hint">进入下一步前请先测试连接</span>
    </div>
    <button type="button" class="pv-btn pv-btn-primary" id="btn-test-db">测试连接</button>
</div>
</div>

<div class="wizard-panel" id="panel-4" data-step="4">
<h3>4. 管理员账号</h3>
<p class="step-desc">创建站点<strong>超级管理员</strong>，拥有后台全部权限。安装完成后请尽快登录并修改密码。</p>
<div class="db-panel">
    <div class="db-tip warn">
        <span>🔒</span>
        <span>请使用<strong>强密码</strong>并妥善保管。该账号可管理全站内容与配置，勿使用弱口令或在公网环境暴露默认账号。</span>
    </div>
    <div class="db-section">
        <div class="db-section-title">登录账号</div>
        <div class="db-form-grid single">
            <div class="form-field">
                <label for="admin-user">用户名 <span class="bad">*</span></label>
                <input type="text" name="admin_user" id="admin-user" class="pv-input" placeholder="登录后台使用的账号" value="admin" required autocomplete="username">
                <span class="field-hint">建议使用字母数字组合，避免与常见默认名重复</span>
            </div>
            <div class="form-field">
                <label for="admin-pass">密码 <span class="bad">*</span></label>
                <div class="pv-pass-wrap">
                    <input type="password" name="admin_pass" id="admin-pass" class="pv-input" placeholder="至少 6 位" required autocomplete="new-password">
                    <button type="button" class="pv-pass-toggle" data-pass-toggle="admin-pass" aria-label="显示密码" aria-pressed="false">显示</button>
                </div>
                <div class="password-meter" aria-hidden="true"><div class="password-meter-bar" id="password-meter-bar"></div></div>
                <span class="field-hint" id="admin-pass-hint">密码长度至少 6 位，建议包含大小写字母与数字</span>
            </div>
            <div class="form-field">
                <label for="admin-pass-confirm">确认密码 <span class="bad">*</span></label>
                <div class="pv-pass-wrap">
                    <input type="password" name="admin_pass_confirm" id="admin-pass-confirm" class="pv-input" placeholder="再输入一次密码" required autocomplete="new-password">
                    <button type="button" class="pv-pass-toggle" data-pass-toggle="admin-pass-confirm" aria-label="显示确认密码" aria-pressed="false">显示</button>
                </div>
                <span class="field-hint" id="admin-pass-confirm-hint">须与上方密码一致</span>
            </div>
        </div>
    </div>
    <div class="db-section">
        <div class="db-section-title">显示信息</div>
        <div class="db-form-grid single">
            <div class="form-field">
                <label for="admin-name">昵称</label>
                <input type="text" name="admin_name" id="admin-name" class="pv-input" placeholder="后台显示的称呼" value="超级管理员">
                <span class="field-hint">仅用于后台展示，不影响登录账号</span>
            </div>
        </div>
    </div>
</div>
<div class="admin-preview" id="admin-preview">
    <div class="admin-avatar" id="admin-avatar">超</div>
    <div class="admin-preview-meta">
        <div class="admin-preview-name" id="admin-preview-name">超级管理员</div>
        <div class="admin-preview-user" id="admin-preview-user">admin</div>
    </div>
</div>
<div class="db-test-banner pending" id="admin-status-banner">
    <div class="db-test-status">
        <span class="status-dot"></span>
        <span id="admin-hint">请填写用户名与密码（至少 6 位）</span>
    </div>
</div>
</div>

<div class="wizard-panel" id="panel-5" data-step="5">
<h3>5. 站点配置与确认</h3>
<p class="step-desc">最后一步：设置站点名称与前台主题，并核对下方清单。确认无误后点击「开始安装」。</p>
<div class="db-panel">
    <div class="db-tip">
        <span>✅</span>
        <span>安装将写入数据库、创建管理员并初始化站点。过程通常 <strong>1～2 分钟</strong>，请勿关闭页面。</span>
    </div>
    <div class="db-section">
        <div class="db-section-title">站点基础设置</div>
        <div class="db-form-grid single">
            <div class="form-field">
                <label for="site-name">站点名称</label>
                <input type="text" name="site_name" id="site-name" class="pv-input" placeholder="显示在浏览器标题与后台" value="元舟 PivArk">
                <span class="field-hint">安装后可于后台「站点设置」修改</span>
            </div>
            <div class="form-field">
                <label for="site-theme">前台主题</label>
                <select name="site_theme" id="site-theme" class="pv-input" style="margin-bottom:0;">
                    <option value="default" selected>default — 系统默认主题</option>
                </select>
                <span class="field-hint">决定访客看到的前台样式；Community 版默认使用 default</span>
            </div>
            <div class="form-field">
                <label for="site-url">站点地址 <span class="bad">*</span></label>
                <input type="text" name="site_url" id="site-url" class="pv-input" placeholder="http://your.domain.com/" required>
                <span class="field-hint">访客前台与后台顶部「首页」链接，请填写完整 URL（含 http:// 或 https://）</span>
            </div>
            <div class="form-field">
                <label for="admin-entry">后台目录名 <span class="bad">*</span></label>
                <input type="text" name="admin_entry" id="admin-entry" class="pv-input" placeholder="admin" value="admin" required autocomplete="off">
                <span class="field-hint">浏览器地址栏中的后台路径，默认 <code>admin</code>；可改为 <code>manage</code> 等（安装后登录地址为：站点地址 + 目录名 + /auth/login）</span>
            </div>
        </div>
    </div>
    <div class="db-section">
        <div class="db-section-title">官方内容增强包</div>
        <p class="enhancement-pack-note">开源版增强包为<strong><?= (int) $enhancementPackTrialDays ?> 天免费试用</strong>（到期后可在插件市场续费或购买）。勾选后将<strong>安装对应插件能力</strong>；演示样例仅在下方勾选「导入演示数据」时写入（后台再装插件不会自动灌数）。</p>
        <p class="enhancement-pack-total" id="enhancement-pack-total" aria-live="polite"></p>
        <input type="hidden" name="plugins_csv" id="plugins-csv" value="">
        <div class="plugin-list enhancement-pack-grid" id="enhancement-pack-list">
            <?php foreach ($enhancementPack as $packRow):
                $tableCount = (int) ($packRow['table_count'] ?? 0);
                $tableChipClass = $tableCount > 0 ? 'table' : 'muted';
            ?>
            <label class="plugin-option" data-plugin-id="<?= htmlspecialchars((string) ($packRow['id'] ?? '')) ?>" data-table-count="<?= $tableCount ?>">
                <input type="checkbox" class="plugin-checkbox" name="plugins[]" value="<?= htmlspecialchars((string) ($packRow['id'] ?? '')) ?>" <?= !empty($packRow['default']) ? 'checked' : '' ?>>
                <span class="plugin-option-body">
                    <span class="plugin-option-head">
                        <strong><?= htmlspecialchars((string) ($packRow['name'] ?? '')) ?></strong>
                        <span class="chip-tag price"><?= htmlspecialchars((string) ($packRow['price_label'] ?? ($enhancementPackTrialDays . '天免费试用'))) ?></span>
                        <span class="chip-tag <?= $tableChipClass ?>"><?= htmlspecialchars((string) ($packRow['table_label'] ?? '')) ?></span>
                    </span>
                    <?php if (!empty($packRow['hint'])): ?>
                    <span class="plugin-option-hint"><?= htmlspecialchars((string) $packRow['hint']) ?></span>
                    <?php endif; ?>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="db-section">
        <input type="hidden" name="import_demo" id="import-demo-value" value="0">
        <label class="plugin-option" for="import-demo">
            <input type="checkbox" id="import-demo" value="1">
            <span class="plugin-option-body">
                <span class="plugin-option-head">
                    <strong>导入华仪智控演示数据</strong>
                    <span class="chip-tag muted">可选</span>
                </span>
                <span class="plugin-option-hint">勾选：导入华仪智控<strong>系统级</strong>演示（导航、产品样板页、轮播、友链等）及上方已选增强包的<strong>插件样例</strong>。不勾选 = <strong>空站</strong>：前台<strong>无导航、无样例栏目/文档</strong>（只装插件能力，看起来会像「没装好」）。完整<strong>产品中心后台</strong>需<strong>专业版及以上</strong>授权</span>
            </span>
        </label>
    </div>
</div>
<div class="site-preview" id="site-preview">
    <div class="site-preview-logo" id="site-preview-logo">元</div>
    <div class="site-preview-meta">
        <div class="site-preview-name" id="site-preview-name">元舟 PivArk</div>
        <div class="site-preview-theme" id="site-preview-theme">前台主题：default</div>
    </div>
</div>
<div class="review-panel">
    <div class="review-head">
        <div class="review-head-title">安装前确认清单</div>
        <div class="review-head-desc">请逐项核对前四步配置；全部显示「就绪」后即可开始安装</div>
    </div>
    <div class="review-list" id="install-summary"></div>
</div>
<div class="install-steps">
    <div class="install-steps-title">点击「开始安装」后将自动完成</div>
    <ol id="install-plan-list">
        <li>写入数据库表结构与初始配置数据</li>
        <li>创建超级管理员账号并绑定权限</li>
        <li>写入站点名称、主题、站点地址等基础设置</li>
        <li id="install-plan-plugins">安装已勾选的官方内容增强包并开通授权</li>
        <li id="install-plan-demo">可选：导入演示数据（系统样板 + 插件样例；不勾选=空站，前台无导航）</li>
    </ol>
</div>
<div class="install-progress" id="install-progress-panel" style="display:none;">
    <div class="install-progress-title">正在安装，请勿关闭或刷新页面</div>
    <div class="install-migrate-bar" id="install-migrate-bar" aria-hidden="true">
        <div class="install-migrate-bar-fill" id="install-migrate-bar-fill"></div>
    </div>
    <ul class="install-progress-list" id="install-progress-list"></ul>
</div>
<div class="db-tip warn" id="db-existing-warning" style="display:none;margin-top:12px;">
    <span>⚠️</span>
    <span id="db-existing-warning-text"></span>
</div>
<div class="db-tip warn" id="overwrite-confirm-box" style="display:none;margin-top:12px;">
    <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;margin:0;">
        <input type="checkbox" name="confirm_overwrite" id="confirm-overwrite" value="1" style="margin-top:3px;">
        <span>我确认<strong>清空该库中所有带当前表前缀的数据表</strong>并重新安装（原有数据将无法恢复）</span>
    </label>
</div>
<div class="db-test-banner pending" id="install-ready-banner">
    <div class="db-test-status">
        <span class="status-dot"></span>
        <span id="install-ready-hint">正在核对安装条件…</span>
    </div>
</div>
</div>
</form>

<div class="wizard-nav" id="wizard-nav">
    <button type="button" class="pv-btn" id="btn-prev" style="visibility:hidden;">上一步</button>
    <div class="nav-right">
        <button type="button" class="pv-btn pv-btn-primary" id="btn-next">下一步</button>
        <button type="button" class="pv-btn pv-btn-primary pv-btn-block" id="btn-install" style="display:none;">开始安装</button>
    </div>
</div>

<div class="install-success-panel" id="install-success-panel" aria-live="polite">
    <div class="install-success-hero">
        <div class="success-icon" aria-hidden="true">🎉</div>
        <h3>恭喜，安装成功！</h3>
        <p id="install-success-site-line">站点已就绪。请立即登录后台修改管理员密码，并删除安装目录以降低安全风险。</p>
    </div>
    <div class="install-success-actions">
        <a class="pv-btn pv-btn-primary" id="install-link-admin" href="#" target="_self" rel="noopener">进入管理后台</a>
        <a class="pv-btn" id="install-link-home" href="#" target="_blank" rel="noopener">访问网站首页</a>
    </div>
    <div class="install-success-section">
        <h4>更多资源</h4>
        <div class="install-success-links" id="install-external-links">
            <?php foreach (app(\install\service\InstallService::class)->completionExternalLinks() as $link): ?>
            <a class="install-success-link" href="<?= htmlspecialchars((string) ($link['url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer"<?php if (!empty($link['url_cn']) && !empty($link['url_com'])): ?> data-url-cn="<?= htmlspecialchars((string) $link['url_cn']) ?>" data-url-com="<?= htmlspecialchars((string) $link['url_com']) ?>"<?php endif; ?>>
                <strong><?= htmlspecialchars((string) ($link['label'] ?? '')) ?></strong>
                <span><?= htmlspecialchars((string) ($link['hint'] ?? '')) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="install-success-section" id="install-baota-section">
        <h4>伪静态设置（需要时按服务器类型手工配置）</h4>
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px;">
            <label for="install-success-config-type" style="font-size:13px;color:#606266;margin:0;">服务器类型</label>
            <select id="install-success-config-type" class="pv-input" style="width:180px;margin:0;height:34px;">
                <option value="nginx">Nginx（宝塔伪静态）</option>
                <option value="apache">Apache（.htaccess）</option>
                <option value="iis">IIS（web.config）</option>
            </select>
        </div>
        <p id="install-baota-title" style="margin:0 0 6px;font-size:14px;font-weight:600;color:#303133;"></p>
        <p id="install-baota-hint" style="margin:0 0 8px;font-size:13px;color:#606266;line-height:1.7;"></p>
        <ol id="install-baota-steps" style="margin:0;padding-left:20px;font-size:13px;color:#303133;line-height:1.75;"></ol>
    </div>
    <div class="install-success-section install-success-emergency" id="install-emergency-section" style="display:none;">
        <h4>插件紧急恢复口令（请妥善保存）</h4>
        <p>若将来启用插件导致后台无法打开，在浏览器地址栏任意页面 URL 后追加下方参数，即可暂停全部插件并进入后台卸载。</p>
        <p><code id="install-emergency-example"></code></p>
        <p>Token：<strong id="install-emergency-token"></strong></p>
        <p class="muted">已写入 <code>data/site.env</code> 的 <code>PIVARK_PLUGIN_EMERGENCY_TOKEN</code>，请勿泄露。</p>
    </div>
    <div class="install-success-section install-success-cleanup">
        <h4>安全建议：删除安装目录</h4>
        <p>行业惯例是<strong>装完即删安装程序</strong>。本系统安装相关文件集中在 <code>install/</code>，数据库脚本在装写库后不再依赖该目录。请用 FTP / 面板删除下列路径（建议自下而上）：</p>
        <ul id="install-removable-list">
            <li><code>install/assets/packages/</code> — 增强包 zip（仅勾选插件已解压到 weapp/；zip 安装完成时已自动删除）</li>
            <li><code>install/assets/seed/</code> — 演示数据脚本（仅勾选导入时执行）</li>
            <li><code>install/assets/</code> — 安装专用资源</li>
            <li><code>install/</code> — 整个安装向导（删后无法通过 <code>/install</code> 重装）</li>
        </ul>
    </div>
</div>
</div>
<?php if (($channel['footer_html'] ?? '') !== ''): ?>
<div class="channel-footer">
<?= $channel['footer_html'] ?>
</div>
<?php endif; ?>
</div>
<div id="pv-toast" class="pv-toast"></div>
<div id="pv-loading" class="pv-loading-mask"><span>处理中…</span></div>
<script>
(function(){
  var toastEl = document.getElementById('pv-toast');
  var loadingEl = document.getElementById('pv-loading');
  var form = document.getElementById('install-form');
  var toastTimer = null;
  var currentStep = 1;
  var totalSteps = 5;
  var canInstall = <?= $canInstall ? 'true' : 'false' ?>;
  var dbTestOk = false;
  var termsAccepted = false;
  var dbExistingCount = 0;
  var dbNeedsOverwrite = false;
  var installInProgress = false;
  // Nginx 空站常无伪静态：/install/getConfig 会 404。统一走 PATH_INFO，且配置正文先内嵌。
  var INSTALL_API_BASE = '/install/index.php';
  var SERVER_CONFIGS = <?= json_encode($installServerConfigs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var SERVER_REWRITE_GUIDES = <?= json_encode($rewriteSetupGuides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  function applyRewriteGuide(type, titleEl, hintEl, stepsEl) {
    var g = (SERVER_REWRITE_GUIDES && SERVER_REWRITE_GUIDES[type]) || (SERVER_REWRITE_GUIDES && SERVER_REWRITE_GUIDES.nginx) || null;
    if (!g) return;
    if (titleEl) titleEl.textContent = g.title || '';
    if (hintEl) hintEl.textContent = g.hint || '';
    if (stepsEl) {
      stepsEl.innerHTML = '';
      (g.steps || []).forEach(function(s) {
        var li = document.createElement('li');
        li.textContent = s;
        stepsEl.appendChild(li);
      });
    }
  }
  function installApiUrl(actionAndQuery) {
    var q = String(actionAndQuery || '').replace(/^\/+/, '');
    return INSTALL_API_BASE + '/' + q;
  }
  var INSTALL_STEPS = [
    { key: 'wipe', label: '准备数据库', desc: '若检测到旧安装，将清空同前缀数据表' },
    { key: 'env', label: '写入环境配置', desc: '生成 data/site.env 等运行配置' },
    { key: 'schema', label: '创建数据表', desc: '安装内核与插件所需表结构' },
    { key: 'migrate', label: '执行数据库迁移', desc: '补齐版本升级脚本与索引' },
    { key: 'admin', label: '创建管理员账号', desc: '写入超级管理员，用于登录后台' },
    { key: 'site', label: '保存站点信息', desc: '站点名称、地址、后台入口等' },
    { key: 'plugins', label: '安装增强插件', desc: '仅安装你在上一步勾选的插件' },
    { key: 'demo', label: '导入演示数据', desc: '勾选时导入系统+插件样例；不勾选则为空站' },
    { key: 'finish', label: '完成安装', desc: '写入 install.lock，站点可正式访问' }
  ];

  function ajaxOk(res) {
    return res && typeof res === 'object' && !res.error && Object.prototype.hasOwnProperty.call(res, 'data');
  }
  function ajaxMsg(res, fallback) {
    if (res && res.error && res.error.message) return String(res.error.message);
    if (res && res.meta && res.meta.message) return String(res.meta.message);
    if (res && res.msg) return String(res.msg);
    return fallback || '';
  }
  function ajaxPayload(res) {
    if (ajaxOk(res)) {
      var d = res.data;
      if (d && typeof d === 'object' && !Array.isArray(d)) return Object.assign({}, d);
      return { data: d };
    }
    return res || {};
  }
  function toast(msg, ok) {
    toastEl.textContent = msg;
    toastEl.className = 'pv-toast show ' + (ok ? 'ok' : 'err');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function(){ toastEl.className = 'pv-toast'; }, ok ? 1800 : 3200);
  }
  function setLoading(on) {
    loadingEl.className = on ? 'pv-loading-mask show' : 'pv-loading-mask';
  }
  function field(name) {
    var el = form.querySelector('[name="' + name + '"]');
    return el ? String(el.value || '').trim() : '';
  }
  function selectedPluginIds() {
    var ids = [];
    form.querySelectorAll('.plugin-checkbox:checked').forEach(function(cb) {
      if (cb.value) ids.push(cb.value);
    });
    return ids;
  }
  function selectedPluginLabels() {
    var labels = [];
    form.querySelectorAll('.plugin-checkbox:checked').forEach(function(cb) {
      var label = cb.closest('.plugin-option');
      var strong = label ? label.querySelector('strong') : null;
      labels.push(strong ? strong.textContent : cb.value);
    });
    return labels;
  }
  function selectedPluginTableCount() {
    var total = 0;
    form.querySelectorAll('.plugin-checkbox:checked').forEach(function(cb) {
      var row = cb.closest('.plugin-option');
      if (!row) return;
      total += parseInt(row.getAttribute('data-table-count') || '0', 10) || 0;
    });
    return total;
  }
  function updateEnhancementPackTotal() {
    var el = document.getElementById('enhancement-pack-total');
    if (!el) return;
    var ids = selectedPluginIds();
    var tables = selectedPluginTableCount();
    if (!ids.length) {
      el.textContent = '当前未勾选增强包，安装时不会创建插件业务表。';
      return;
    }
    el.innerHTML = '已选 <strong>' + ids.length + '</strong> 个增强包，勾选后将额外创建约 <strong>' + tables + '</strong> 张插件表。';
  }
  function syncPluginsCsv() {
    var hidden = document.getElementById('plugins-csv');
    if (hidden) hidden.value = selectedPluginIds().join(',');
    var demoHidden = document.getElementById('import-demo-value');
    if (demoHidden) demoHidden.value = isImportDemoChecked() ? '1' : '0';
    updateEnhancementPackTotal();
    updateInstallPlan();
  }
  function isImportDemoChecked() {
    var el = document.getElementById('import-demo');
    return !!(el && el.checked);
  }
  function updateInstallPlan() {
    var pluginLine = document.getElementById('install-plan-plugins');
    var demoLine = document.getElementById('install-plan-demo');
    var ids = selectedPluginIds();
    if (pluginLine) {
      pluginLine.textContent = ids.length
        ? ('安装并启用：' + selectedPluginLabels().join('、') + '（演示样例随下方勾选决定）')
        : '跳过官方内容增强包（仅安装内核）';
    }
    if (demoLine) {
      demoLine.textContent = isImportDemoChecked()
        ? '导入系统演示 + 已选增强包的插件样例（导航、产品样板页、轮播等；产品中心后台需专业版+）'
        : '跳过全部演示数据（空站：前台无导航、无样例栏目/文档；勿当作安装失败）';
    }
    var demoStep = INSTALL_STEPS.find(function(s){ return s.key === 'demo'; });
    if (demoStep) {
      demoStep.label = isImportDemoChecked() ? '导入演示数据' : '跳过全部演示数据';
    }
    var pluginStep = INSTALL_STEPS.find(function(s){ return s.key === 'plugins'; });
    if (pluginStep) {
      pluginStep.label = ids.length ? '安装官方内容增强包' : '跳过插件安装';
    }
  }
  function serializeForm() {
    syncPluginsCsv();
    return new URLSearchParams(new FormData(form)).toString();
  }
  function isTermsChecked() {
    var terms = document.getElementById('accept-license-terms');
    return !!(terms && terms.checked);
  }
  function updateProgress() {
    document.querySelectorAll('.progress-item').forEach(function(item) {
      var step = parseInt(item.getAttribute('data-step'), 10);
      item.classList.remove('active', 'done');
      if (step < currentStep) item.classList.add('done');
      if (step === currentStep) item.classList.add('active');
    });
    document.querySelectorAll('.wizard-panel').forEach(function(panel) {
      panel.classList.toggle('active', parseInt(panel.getAttribute('data-step'), 10) === currentStep);
    });
    document.getElementById('btn-prev').style.visibility = currentStep > 1 ? 'visible' : 'hidden';
    document.getElementById('btn-next').style.display = currentStep < totalSteps ? 'inline-flex' : 'none';
    document.getElementById('btn-install').style.display = currentStep === totalSteps ? 'inline-flex' : 'none';
    if (currentStep === totalSteps) renderSummary();
  }
  function escHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  function setInstallReady(state, message) {
    var banner = document.getElementById('install-ready-banner');
    var hint = document.getElementById('install-ready-hint');
    if (banner) banner.className = 'db-test-banner ' + (state || 'pending');
    if (hint) hint.textContent = message || '正在核对安装条件…';
  }
  function updateDbExistingHints() {
    var prefix = field('db_prefix') || 'pv_';
    var text = dbExistingCount > 0
      ? '检测到数据库中已有 ' + dbExistingCount + ' 张「' + prefix + '」前缀的表。继续安装将清空这些表并重新写入数据。'
      : '';
    var step3 = document.getElementById('db-existing-hint-step3');
    var step3Text = document.getElementById('db-existing-hint-step3-text');
    var warn = document.getElementById('db-existing-warning');
    var warnText = document.getElementById('db-existing-warning-text');
    var overwriteBox = document.getElementById('overwrite-confirm-box');
    var showOverwrite = !installInProgress && dbNeedsOverwrite;
    if (step3) step3.style.display = showOverwrite ? 'flex' : 'none';
    if (step3Text) step3Text.textContent = text;
    if (warn) warn.style.display = showOverwrite ? 'flex' : 'none';
    if (warnText) warnText.textContent = text;
    if (overwriteBox) overwriteBox.style.display = showOverwrite ? 'flex' : 'none';
    if (!showOverwrite) {
      var cb = document.getElementById('confirm-overwrite');
      if (cb && !installInProgress) cb.checked = false;
    }
  }
  function clearOverwriteHintsAfterWipe() {
    dbExistingCount = 0;
    dbNeedsOverwrite = false;
    updateDbExistingHints();
  }
  function renderInstallProgress(activeIndex) {
    var list = document.getElementById('install-progress-list');
    var panel = document.getElementById('install-progress-panel');
    if (!list || !panel) return;
    panel.style.display = 'block';
    var html = '';
    INSTALL_STEPS.forEach(function(step, i) {
      var state = i < activeIndex ? 'done' : (i === activeIndex ? 'running' : '');
      html += '<li class="install-progress-item ' + state + '">' +
        '<span class="dot"></span><span class="label">' + escHtml(step.label) +
        (step.desc ? '<span class="label-sub">' + escHtml(step.desc) + '</span>' : '') +
        '</span>' +
        (i < activeIndex ? '<span class="pill pill-ok">完成</span>' : '') +
        '</li>';
    });
    list.innerHTML = html;
  }
  function markInstallStepError(index, msg) {
    var list = document.getElementById('install-progress-list');
    if (!list) return;
    var items = list.querySelectorAll('.install-progress-item');
    if (!items[index]) return;
    items[index].className = 'install-progress-item error';
    var err = document.createElement('span');
    err.className = 'pill pill-bad';
    err.textContent = msg || '失败';
    items[index].appendChild(err);
  }
  function nonJsonInstallError(status, text) {
    var raw = String(text || '').replace(/\s+/g, ' ').trim();
    var snippet = raw.length > 180 ? (raw.slice(0, 180) + '…') : raw;
    if (!snippet) {
      return '接口返回空响应（HTTP ' + status + '），请查看服务器 PHP 错误日志';
    }
    return '接口返回非 JSON（HTTP ' + status + '）：' + snippet;
  }
  /** 装向导接口统一：先 text 再 JSON，避免 r.json() 把 HTML/脏输出吞成「请求失败」 */
  function parseInstallJsonResponse(r) {
    return r.text().then(function(text) {
      var res = null;
      try { res = JSON.parse(text); } catch (e) { res = null; }
      return { ok: !!res, status: r.status, text: text, res: res };
    });
  }
  function runInstallStepRequest(stepKey, onOk, onErr, attempt) {
    attempt = attempt || 0;
    var body = serializeForm() + '&step=' + encodeURIComponent(stepKey);
    fetch(installApiUrl('runStep'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function(r){
      return parseInstallJsonResponse(r).then(function(parsed) {
        if (!parsed.ok) {
          // 面板/中间件偶发 429：迁移可幂等重试，避免整段安装作废
          if ((parsed.status === 429 || parsed.status === 503) && attempt < 5) {
            setTimeout(function() {
              runInstallStepRequest(stepKey, onOk, onErr, attempt + 1);
            }, 1200 * (attempt + 1));
            return;
          }
          onErr(nonJsonInstallError(parsed.status, parsed.text));
          return;
        }
        if (ajaxOk(parsed.res)) {
          onOk(ajaxPayload(parsed.res));
        } else {
          onErr(ajaxMsg(parsed.res, '失败'));
        }
      });
    }).catch(function(){
      if (attempt < 3) {
        setTimeout(function() {
          runInstallStepRequest(stepKey, onOk, onErr, attempt + 1);
        }, 800 * (attempt + 1));
        return;
      }
      onErr('网络错误');
    });
  }
  function refreshExternalLinks() {
    fetch(installApiUrl('resolveExternalLinks'), { cache: 'no-store' })
      .then(function(r) { return r.text(); })
      .then(function(text) {
        var res = null;
        try { res = JSON.parse(text); } catch (e) { res = null; }
        if (!ajaxOk(res)) return;
        var payload = ajaxPayload(res);
        var links = Array.isArray(payload.links) ? payload.links : [];
        var box = document.getElementById('install-external-links');
        if (!box) return;
        var anchors = box.querySelectorAll('.install-success-link');
        links.forEach(function(item, index) {
          if (!item || !item.url || !anchors[index]) return;
          anchors[index].href = item.url;
        });
      })
      .catch(function() {});
  }
  function showInstallSuccess(payload) {
    payload = payload || {};
    var panel = document.getElementById('install-success-panel');
    var formEl = document.getElementById('install-form');
    var navEl = document.getElementById('wizard-nav');
    var progressBar = document.getElementById('progress-bar');
    var progressPanel = document.getElementById('install-progress-panel');
    var sub = document.querySelector('.wrap > .sub');
    if (formEl) formEl.style.display = 'none';
    if (navEl) navEl.style.display = 'none';
    if (progressPanel) progressPanel.style.display = 'none';
    if (sub) sub.style.display = 'none';
    if (progressBar) progressBar.parentElement.classList.add('install-wizard-done');
    document.querySelectorAll('.progress-item').forEach(function(el) {
      el.classList.add('done');
      el.classList.remove('active');
    });
    var adminLink = document.getElementById('install-link-admin');
    var homeLink = document.getElementById('install-link-home');
    var siteLine = document.getElementById('install-success-site-line');
    var adminUrl = payload.url || '/admin/index.php/auth/login';
    var homeUrl = payload.site_home || '/';
    var siteName = payload.site_name || field('site_name') || '站点';
    if (adminLink) adminLink.href = adminUrl;
    if (homeLink) homeLink.href = homeUrl;
    if (siteLine) {
      siteLine.textContent = '「' + siteName + '」已安装完成。即将进入管理后台，请立即修改管理员密码。前台短链需要时，再按下方选择服务器类型并按步骤配置伪静态即可。';
    }
    var successTypeSel = document.getElementById('install-success-config-type');
    var successType = (successTypeSel && successTypeSel.value) || 'nginx';
    applyRewriteGuide(
      successType,
      document.getElementById('install-baota-title'),
      document.getElementById('install-baota-hint'),
      document.getElementById('install-baota-steps')
    );
    // 完成页仍可用 payload 覆盖 hint（兼容旧字段）；有 guide 时以类型说明为准
    if (payload.baota_conf_hint && successType === 'nginx') {
      var baotaHint = document.getElementById('install-baota-hint');
      if (baotaHint && !(SERVER_REWRITE_GUIDES && SERVER_REWRITE_GUIDES.nginx)) {
        baotaHint.textContent = payload.baota_conf_hint;
      }
    }
    var emergency = payload.plugin_emergency || {};
    var emergencySection = document.getElementById('install-emergency-section');
    if (emergencySection && emergency.token) {
      emergencySection.style.display = 'block';
      var tokenEl = document.getElementById('install-emergency-token');
      var exampleEl = document.getElementById('install-emergency-example');
      if (tokenEl) tokenEl.textContent = emergency.token;
      if (exampleEl) {
        exampleEl.textContent = emergency.example || ('?_pv_emergency=' + emergency.token);
      }
    }
    if (panel) panel.classList.add('show');
    document.querySelector('.wrap h1').textContent = '安装完成';
    installInProgress = false;
    refreshExternalLinks();
    // 装完直接进物理入口后台（/admin/index.php/…），不依赖伪静态
    if (adminUrl && adminUrl !== '#') {
      window.setTimeout(function() {
        window.location.assign(adminUrl);
      }, 1200);
    }
  }
  function ensureProgressMeta(item) {
    if (!item) return null;
    var meta = item.querySelector('.progress-meta');
    if (!meta) {
      meta = document.createElement('span');
      meta.className = 'progress-meta';
      item.appendChild(meta);
    }
    return meta;
  }
  function updateMigrateProgressBar(payload) {
    var bar = document.getElementById('install-migrate-bar');
    var fill = document.getElementById('install-migrate-bar-fill');
    if (!bar || !fill) return;
    var total = parseInt(payload.migration_total, 10) || 0;
    var applied = parseInt(payload.migration_applied, 10) || 0;
    if (total < 1) {
      bar.classList.remove('show');
      fill.style.width = '0%';
      return;
    }
    bar.classList.add('show');
    fill.style.width = Math.max(0, Math.min(100, Math.round((applied / total) * 100))) + '%';
  }
  function updateStepProgressItem(item, payload, titleText) {
    if (!item) return;
    payload = payload || {};
    var label = item.querySelector('.label');
    var meta = ensureProgressMeta(item);
    var total = parseInt(payload.migration_total, 10) || 0;
    var applied = parseInt(payload.migration_applied, 10) || 0;
    var pending = parseInt(payload.pending, 10) || 0;
    var tables = parseInt(payload.table_count, 10) || 0;
    if (label) {
      var title = document.createElement('span');
      title.textContent = titleText || '处理中';
      label.textContent = '';
      label.appendChild(title);
      if (payload.name) {
        var sub = document.createElement('span');
        sub.className = 'label-sub';
        sub.textContent = payload.name;
        label.appendChild(sub);
      }
    }
    if (meta) {
      var parts = [];
      if (total > 0) parts.push(applied + '/' + total);
      if (pending > 0) parts.push('剩 ' + pending);
      else if (total > 0 && applied >= total) parts.push('完成');
      if (tables > 0) parts.push(tables + ' 表');
      meta.textContent = parts.join(' · ');
    }
    updateMigrateProgressBar(payload);
  }
  function updateMigrateProgressItem(item, payload) {
    updateStepProgressItem(item, payload, '执行数据库迁移');
  }
  function appendInstallStepDetail(index, payload, stepKey) {
    var list = document.getElementById('install-progress-list');
    if (!list) return;
    var items = list.querySelectorAll('.install-progress-item');
    if (!items[index]) return;
    var label = items[index].querySelector('.label');
    if (!label) return;
    payload = payload || {};
    if (stepKey === 'wipe' && Object.prototype.hasOwnProperty.call(payload, 'dropped')) {
      label.textContent = '检查并清空旧数据表（' + payload.dropped + ' 张）';
    } else if (stepKey === 'schema') {
      updateStepProgressItem(items[index], payload, '创建数据表');
    } else if (stepKey === 'migrate') {
      updateStepProgressItem(items[index], payload, '执行数据库迁移');
    } else if (stepKey === 'demo') {
      updateStepProgressItem(items[index], payload, isImportDemoChecked() ? '导入演示数据' : '跳过全部演示数据');
    } else if (stepKey === 'plugins' && Array.isArray(payload.plugins)) {
      label.textContent = payload.plugins.length
        ? ('安装官方内容增强包（' + payload.plugins.length + ' 个）')
        : '跳过插件安装';
    }
  }
  function runProgressLoop(index, loginUrl, stepKey, titleText) {
    var list = document.getElementById('install-progress-list');
    var items = list ? list.querySelectorAll('.install-progress-item') : [];
    if (items[index]) items[index].className = 'install-progress-item running';
    runInstallStepRequest(stepKey, function(payload) {
      if (payload.url) loginUrl = payload.url;
      updateStepProgressItem(items[index], payload, titleText);
      if (payload.done) {
        if (items[index]) {
          items[index].className = 'install-progress-item done';
          updateStepProgressItem(items[index], payload, titleText);
        }
        var bar = document.getElementById('install-migrate-bar');
        if (bar) bar.classList.remove('show');
        runInstallSteps(index + 1, loginUrl);
      } else {
        runProgressLoop(index, loginUrl, stepKey, titleText);
      }
    }, function(msg) {
      installInProgress = false;
      updateDbExistingHints();
      markInstallStepError(index, msg);
      var btn = document.getElementById('btn-install');
      if (btn) btn.disabled = false;
      toast(msg === '网络错误' ? '安装请求失败' : msg, false);
    });
  }
  function runMigrateLoop(index, loginUrl) {
    runProgressLoop(index, loginUrl, 'migrate', '执行数据库迁移');
  }
  function runInstallSteps(index, loginUrl) {
    if (index >= INSTALL_STEPS.length) {
      var completion = { url: loginUrl || '/admin/index.php/auth/login', site_home: '/', site_name: field('site_name') };
      showInstallSuccess(completion);
      toast('安装成功', true);
      return;
    }
    var step = INSTALL_STEPS[index];
    renderInstallProgress(index);
    if (step.key === 'schema') {
      runProgressLoop(index, loginUrl, 'schema', '创建数据表');
      return;
    }
    if (step.key === 'migrate') {
      runProgressLoop(index, loginUrl, 'migrate', '执行数据库迁移');
      return;
    }
    if (step.key === 'demo') {
      runProgressLoop(index, loginUrl, 'demo', isImportDemoChecked() ? '导入演示数据' : '跳过全部演示数据');
      return;
    }
    runInstallStepRequest(step.key, function(payload) {
      if (payload.url) loginUrl = payload.url;
      if (step.key === 'wipe') {
        clearOverwriteHintsAfterWipe();
      }
      if (step.key === 'finish') {
        var list = document.getElementById('install-progress-list');
        var items = list ? list.querySelectorAll('.install-progress-item') : [];
        if (items[index]) items[index].className = 'install-progress-item done';
        installInProgress = false;
        showInstallSuccess(payload);
        toast('安装成功', true);
        return;
      }
      var list = document.getElementById('install-progress-list');
      var items = list ? list.querySelectorAll('.install-progress-item') : [];
      if (items[index]) {
        items[index].className = 'install-progress-item done';
        appendInstallStepDetail(index, payload, step.key);
      }
      runInstallSteps(index + 1, loginUrl);
    }, function(msg) {
      installInProgress = false;
      updateDbExistingHints();
      markInstallStepError(index, msg);
      var btn = document.getElementById('btn-install');
      if (btn) btn.disabled = false;
      toast(msg === '网络错误' ? '安装请求失败' : msg, false);
    });
  }
  function updateSitePreview() {
    var name = field('site_name') || '元舟 PivArk';
    var theme = field('site_theme') || 'default';
    var logo = document.getElementById('site-preview-logo');
    var nameEl = document.getElementById('site-preview-name');
    var themeEl = document.getElementById('site-preview-theme');
    if (nameEl) nameEl.textContent = name;
    if (themeEl) themeEl.textContent = '前台主题：' + theme;
    if (logo) logo.textContent = name.charAt(0) || '站';
  }
  function renderSummary() {
    var box = document.getElementById('install-summary');
    if (!box) return;
    var items = [
      {
        icon: '📄',
        label: '许可声明',
        value: '已同意 Community 开源许可与使用声明',
        ok: isTermsChecked()
      },
      {
        icon: '⚙️',
        label: '运行环境',
        value: canInstall ? '必选项已全部通过' : '存在未通过的必选项',
        ok: canInstall
      },
      {
        icon: '🗄️',
        label: '数据库连接',
        value: field('db_name') + ' · ' + field('db_user') + '@' + field('db_host') + ':' + field('db_port'),
        ok: dbTestOk
      },
      {
        icon: '👤',
        label: '管理员账号',
        value: field('admin_user') + '（' + (field('admin_name') || '超级管理员') + '）',
        ok: !!field('admin_user') && field('admin_pass').length >= 6 && field('admin_pass') === field('admin_pass_confirm')
      },
      {
        icon: '🌐',
        label: '站点信息',
        value: field('site_name') + ' · 主题 ' + field('site_theme'),
        ok: !!field('site_name')
      },
      {
        icon: '🔗',
        label: '站点地址',
        value: field('site_url') || '未填写',
        ok: !!field('site_url')
      },
      {
        icon: '🔐',
        label: '后台目录',
        value: '/' + (field('admin_entry') || 'admin') + ' → ' + (field('site_url') ? (field('site_url').replace(/\/$/, '') + '/' + (field('admin_entry') || 'admin') + '/auth/login') : ''),
        ok: !!field('admin_entry')
      },
      {
        icon: '🧩',
        label: '内容增强包',
        value: selectedPluginIds().length ? selectedPluginLabels().join('、') : '不安装任何插件',
        ok: true
      },
      {
        icon: '📦',
        label: '演示数据',
        value: isImportDemoChecked() ? '导入系统+插件演示' : '空站（不导入任何演示）',
        ok: true
      }
    ];
    var html = '';
    items.forEach(function(item) {
      html += '<div class="review-item">' +
        '<div class="review-icon">' + item.icon + '</div>' +
        '<div class="review-body">' +
          '<div class="review-label">' + item.label + '</div>' +
          '<div class="review-value">' + escHtml(item.value) + '</div>' +
        '</div>' +
        '<span class="pill ' + (item.ok ? 'pill-ok' : 'pill-bad') + '">' + (item.ok ? '就绪' : '待确认') + '</span>' +
      '</div>';
    });
    box.innerHTML = html;
    updateSitePreview();
    var overwriteOk = !dbNeedsOverwrite || !!(document.getElementById('confirm-overwrite') && document.getElementById('confirm-overwrite').checked);
    var allOk = items.every(function(item) { return item.ok; }) && overwriteOk;
    if (allOk) {
      setInstallReady('ok', '全部就绪，可点击右下角「开始安装」');
    } else {
      var pending = items.filter(function(item) { return !item.ok; }).map(function(item) { return item.label; });
      if (!overwriteOk && dbNeedsOverwrite) pending.push('覆盖确认');
      setInstallReady('bad', '以下项未就绪：' + pending.join('、') + '（可返回对应步骤修改）');
    }
    updateDbExistingHints();
  }
  function validateStep(step) {
    if (step === 1) {
      if (!isTermsChecked()) {
        toast('请先勾选同意开源许可与使用声明', false);
        return false;
      }
      return true;
    }
    if (step === 2) {
      if (!canInstall) {
        toast('环境必选项未通过，请先处理后再继续', false);
        return false;
      }
      return true;
    }
    if (step === 3) {
      if (!field('db_name') || !field('db_user')) {
        toast('请填写数据库名和用户名', false);
        return false;
      }
      if (!dbTestOk) {
        toast('请先点击「测试连接」并确保成功', false);
        return false;
      }
      return true;
    }
    if (step === 4) {
      if (!field('admin_user')) {
        toast('请填写管理员用户名', false);
        return false;
      }
      if (field('admin_pass').length < 6) {
        toast('管理员密码至少 6 位', false);
        return false;
      }
      if (field('admin_pass') !== field('admin_pass_confirm')) {
        toast('两次输入的密码不一致', false);
        return false;
      }
      return true;
    }
    if (step === 5) {
      if (!field('site_url')) {
        toast('请填写站点地址', false);
        return false;
      }
      if (!field('admin_entry')) {
        toast('请填写后台目录名', false);
        return false;
      }
      if (!/^[a-z][-a-z0-9_]{0,31}$/.test(field('admin_entry'))) {
        toast('后台目录名须以小写字母开头，仅含字母、数字、_、-', false);
        return false;
      }
      if (dbNeedsOverwrite) {
        var overwriteCb = document.getElementById('confirm-overwrite');
        if (!overwriteCb || !overwriteCb.checked) {
          toast('数据库中已有表，请勾选「确认清空并覆盖」后再安装', false);
          return false;
        }
      }
      return true;
    }
    return true;
  }
  function suggestInstallUrls() {
    var origin = window.location.origin || '';
    if (!origin) return;
    var siteUrlEl = document.getElementById('site-url');
    var adminEntryEl = document.getElementById('admin-entry');
    if (siteUrlEl && !field('site_url')) {
      siteUrlEl.value = origin.replace(/\/$/, '') + '/';
    }
    if (adminEntryEl && !field('admin_entry')) {
      adminEntryEl.value = 'admin';
    }
    renderSummary();
  }
  function goToStep(step) {
    if (step < 1 || step > totalSteps) return;
    currentStep = step;
    updateProgress();
    if (step === totalSteps) suggestInstallUrls();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function countEnvStats(checks) {
    var reqTotal = 0, reqOk = 0, recTotal = 0, recOk = 0;
    (checks || []).forEach(function(c) {
      var level = c.level || 'required';
      var ok = !!c.ok;
      if (level === 'recommended') {
        recTotal++;
        if (ok) recOk++;
      } else {
        reqTotal++;
        if (ok) reqOk++;
      }
    });
    return { reqTotal: reqTotal, reqOk: reqOk, recTotal: recTotal, recOk: recOk };
  }
  function renderEnvHelpTip(fixText) {
    if (!fixText) return '';
    return '<span class="env-help" tabindex="0" aria-label="怎么处理">' +
      '<span class="env-help-mark">?</span>' +
      '<span class="env-help-pop" role="tooltip"><strong>怎么处理</strong><br>' + escHtml(fixText) + '</span>' +
      '</span>';
  }
  function renderEnvRow(c) {
    var level = c.level || 'required';
    var ok = !!c.ok;
    var rowCls = ok ? 'row-pass' : (level === 'required' ? 'row-fail' : 'row-warn');
    var pillCls = ok ? 'pill-ok' : (level === 'required' ? 'pill-bad' : 'pill-warn');
    var status = ok ? '通过' : (level === 'required' ? '未通过' : '建议处理');
    var levelLabel = level === 'recommended' ? '建议' : '必选';
    var levelPill = level === 'recommended' ? 'pill-level-rec' : 'pill-level';
    var fixText = (c.fix || c.fix_baota || '').trim();
    var labelHtml = '<span class="env-label">' + escHtml(c.label || '') +
      (!ok ? renderEnvHelpTip(fixText) : '') + '</span>';
    return '<tr class="' + rowCls + '">' +
      '<td>' + labelHtml + '</td>' +
      '<td class="col-level"><span class="pill ' + levelPill + '">' + levelLabel + '</span></td>' +
      '<td class="col-status"><span class="pill ' + pillCls + '">' + status + '</span></td>' +
      '<td class="detail-cell">' + escHtml(c.detail || '') + '</td>' +
      '</tr>';
  }
  function renderEnvReport(data) {
    var meta = data.meta || {};
    canInstall = !!meta.can_install;
    var tbody = document.getElementById('env-check-body');
    var html = '';
    (data.checks || []).forEach(function(c) { html += renderEnvRow(c); });
    if (tbody) tbody.innerHTML = html;
    var stats = countEnvStats(data.checks || []);
    var reqEl = document.getElementById('stat-required');
    var recEl = document.getElementById('stat-recommended');
    if (reqEl) reqEl.textContent = stats.reqOk + '/' + stats.reqTotal;
    if (recEl) recEl.textContent = stats.recOk + '/' + stats.recTotal;
    var banner = document.getElementById('env-status-banner');
    var hint = document.getElementById('env-hint');
    if (banner) banner.className = 'env-status-banner ' + (canInstall ? 'ok' : 'bad');
    if (hint) hint.textContent = canInstall ? '环境检测通过，可以进入下一步' : '存在未通过的必选项，请先按下方指引处理';
    var guide = document.getElementById('bt-guide');
    if (guide && data.baota_steps && data.baota_steps.length) {
      var panelLabel = (meta.panel_label || '').trim();
      var ol = '<strong>' + (panelLabel ? (panelLabel + ' · 修复指引') : '环境修复指引') + '</strong><ol>';
      data.baota_steps.forEach(function(s) { ol += '<li>' + s + '</li>'; });
      ol += '</ol>';
      guide.innerHTML = ol;
    }
  }
  function loadConfig(type) {
    var box = document.getElementById('config-content');
    if (SERVER_CONFIGS && SERVER_CONFIGS[type]) {
      box.value = SERVER_CONFIGS[type];
    } else {
      fetch(installApiUrl('getConfig?type=' + encodeURIComponent(type)))
        .then(function(r){ return r.json(); })
        .then(function(res){
          if (ajaxOk(res)) box.value = res.data;
        })
        .catch(function(){ /* 内嵌已兜底 */ });
    }
    applyRewriteGuide(
      type,
      document.getElementById('config-rewrite-title'),
      document.getElementById('config-rewrite-hint'),
      document.getElementById('config-rewrite-steps')
    );
  }
  loadConfig(document.getElementById('config-type').value);
  document.getElementById('config-type').addEventListener('change', function(){ loadConfig(this.value); });
  var successConfigType = document.getElementById('install-success-config-type');
  if (successConfigType) {
    successConfigType.addEventListener('change', function(){
      applyRewriteGuide(
        this.value,
        document.getElementById('install-baota-title'),
        document.getElementById('install-baota-hint'),
        document.getElementById('install-baota-steps')
      );
    });
  }
  document.getElementById('btn-copy-config').addEventListener('click', function(){
    var text = document.getElementById('config-content').value;
    navigator.clipboard.writeText(text).then(function(){ toast('已复制到剪贴板', true); })
      .catch(function(){ toast('复制失败', false); });
  });
  document.getElementById('btn-download-config').addEventListener('click', function(){
    var type = document.getElementById('config-type').value;
    var filename = type === 'nginx' ? '_pv_baota_rewrite.conf' : (type === 'apache' ? '.htaccess' : 'web.config');
    var text = document.getElementById('config-content').value;
    var blob = new Blob([text], {type:'text/plain'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    toast('配置文件已下载', true);
  });
  document.getElementById('btn-recheck-env').addEventListener('click', function(){
    setLoading(true);
    fetch(installApiUrl('check'))
      .then(function(r){ return r.json(); })
      .then(function(res){
        setLoading(false);
        if (ajaxOk(res) && res.data) {
          renderEnvReport(res.data);
          toast(res.data.meta && res.data.meta.can_install ? '环境已通过' : '仍有必选项未通过', !!(res.data.meta && res.data.meta.can_install));
        } else {
          toast('检测失败', false);
        }
      })
      .catch(function(){ setLoading(false); toast('请求失败', false); });
  });
  function setDbTestStatus(state, message) {
    var banner = document.getElementById('db-test-banner');
    var hint = document.getElementById('db-test-hint');
    if (banner) banner.className = 'db-test-banner ' + (state || 'pending');
    if (hint) hint.textContent = message || '进入下一步前请先测试连接';
  }
  form.querySelectorAll('input[name^="db_"]').forEach(function(input) {
    input.addEventListener('input', function(){
      dbTestOk = false;
      dbExistingCount = 0;
      dbNeedsOverwrite = false;
      updateDbExistingHints();
      setDbTestStatus('pending', '配置已变更，请重新测试连接');
    });
  });
  document.getElementById('btn-test-db').addEventListener('click', function(){
    if (!field('db_name') || !field('db_user')) {
      toast('请先填写数据库名和用户名', false);
      return;
    }
    setDbTestStatus('pending', '正在测试连接…');
    setLoading(true);
    fetch(installApiUrl('testDb'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: serializeForm()
    }).then(function(r){
      return parseInstallJsonResponse(r);
    }).then(function(parsed){
      setLoading(false);
      if (!parsed.ok) {
        dbTestOk = false;
        dbExistingCount = 0;
        dbNeedsOverwrite = false;
        updateDbExistingHints();
        var bad = nonJsonInstallError(parsed.status, parsed.text);
        setDbTestStatus('bad', bad);
        toast(bad, false);
        return;
      }
      var res = parsed.res;
      var ok = ajaxOk(res);
      dbTestOk = ok;
      var payload = ajaxPayload(res);
      if (ok) {
        dbExistingCount = parseInt(payload.table_count, 10) || 0;
        dbNeedsOverwrite = !!(payload.needs_overwrite || dbExistingCount > 0);
        updateDbExistingHints();
        if (dbNeedsOverwrite) {
          setDbTestStatus('ok', '连接成功；检测到 ' + dbExistingCount + ' 张已有表，重装须在最后一步确认覆盖');
        } else {
          setDbTestStatus('ok', '连接成功，数据库为空，可进入下一步');
        }
      } else {
        dbExistingCount = 0;
        dbNeedsOverwrite = false;
        updateDbExistingHints();
        setDbTestStatus('bad', ajaxMsg(res, '连接失败，请检查配置'));
      }
      toast(ajaxMsg(res, ok ? '连接成功' : '连接失败'), ok);
      if (currentStep === totalSteps) renderSummary();
    }).catch(function(){
      setLoading(false);
      setDbTestStatus('bad', '网络错误：无法到达安装接口，请检查站点域名与 HTTPS');
      toast('网络错误', false);
    });
  });
  function setAdminStatus(state, message) {
    var banner = document.getElementById('admin-status-banner');
    var hint = document.getElementById('admin-hint');
    if (banner) banner.className = 'db-test-banner ' + (state || 'pending');
    if (hint) hint.textContent = message || '请填写用户名与密码（至少 6 位）';
  }
  function updatePasswordMeter() {
    var pass = field('admin_pass');
    var bar = document.getElementById('password-meter-bar');
    if (!bar) return;
    bar.className = 'password-meter-bar';
    if (pass.length >= 10) bar.classList.add('good');
    else if (pass.length >= 6) bar.classList.add('mid');
    else if (pass.length > 0) bar.classList.add('weak');
  }
  function updateAdminPreview() {
    var name = field('admin_name') || '超级管理员';
    var user = field('admin_user') || 'admin';
    var avatar = document.getElementById('admin-avatar');
    var nameEl = document.getElementById('admin-preview-name');
    var userEl = document.getElementById('admin-preview-user');
    if (nameEl) nameEl.textContent = name;
    if (userEl) userEl.textContent = user;
    if (avatar) avatar.textContent = name.charAt(0) || '管';
  }
  function updateConfirmPassHint() {
    var hint = document.getElementById('admin-pass-confirm-hint');
    if (!hint) return;
    var pass = field('admin_pass');
    var confirm = field('admin_pass_confirm');
    hint.classList.remove('bad');
    if (!confirm) {
      hint.textContent = '须与上方密码一致';
      return;
    }
    if (pass !== confirm) {
      hint.classList.add('bad');
      hint.textContent = '两次输入的密码不一致';
      return;
    }
    hint.textContent = '两次密码一致';
  }
  function refreshAdminStep() {
    updatePasswordMeter();
    updateAdminPreview();
    updateConfirmPassHint();
    var user = field('admin_user');
    var pass = field('admin_pass');
    var confirm = field('admin_pass_confirm');
    if (!user) {
      setAdminStatus('pending', '请填写管理员用户名');
      return;
    }
    if (pass.length < 6) {
      setAdminStatus('bad', pass.length ? ('密码至少 6 位，当前 ' + pass.length + ' 位') : '请设置管理员密码（至少 6 位）');
      return;
    }
    if (!confirm) {
      setAdminStatus('pending', '请再输入一次密码以确认');
      return;
    }
    if (pass !== confirm) {
      setAdminStatus('bad', '两次输入的密码不一致');
      return;
    }
    setAdminStatus('ok', '账号信息已就绪，可进入下一步');
  }
  document.querySelectorAll('[data-pass-toggle]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var id = btn.getAttribute('data-pass-toggle');
      var input = id ? document.getElementById(id) : null;
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? '隐藏' : '显示';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? '隐藏密码' : '显示密码');
    });
  });
  ['admin_user', 'admin_pass', 'admin_pass_confirm', 'admin_name'].forEach(function(name) {
    var el = form.querySelector('[name="' + name + '"]');
    if (el) el.addEventListener('input', refreshAdminStep);
  });
  refreshAdminStep();
  ['site_name', 'site_theme', 'site_url', 'admin_entry'].forEach(function(name) {
    var el = form.querySelector('[name="' + name + '"]');
    if (el) el.addEventListener('input', renderSummary);
    if (el && el.tagName === 'SELECT') el.addEventListener('change', renderSummary);
  });
  var overwriteCb = document.getElementById('confirm-overwrite');
  if (overwriteCb) overwriteCb.addEventListener('change', renderSummary);
  form.querySelectorAll('.plugin-checkbox').forEach(function(cb) {
    cb.addEventListener('change', function() {
      syncPluginsCsv();
      renderSummary();
    });
  });
  var importDemoEl = document.getElementById('import-demo');
  if (importDemoEl) {
    importDemoEl.addEventListener('change', function() {
      updateInstallPlan();
      renderSummary();
    });
  }
  syncPluginsCsv();
  document.getElementById('btn-next').addEventListener('click', function(){
    if (!validateStep(currentStep)) return;
    goToStep(currentStep + 1);
  });
  document.getElementById('btn-prev').addEventListener('click', function(){
    goToStep(currentStep - 1);
  });
  document.getElementById('btn-install').addEventListener('click', function(){
    if (!isTermsChecked()) {
      toast('请先返回第 1 步勾选许可声明', false);
      return;
    }
    if (!canInstall) {
      toast('环境检测未通过，请返回第 2 步处理', false);
      return;
    }
    if (!validateStep(3) || !validateStep(4) || !validateStep(5)) return;
    var btn = document.getElementById('btn-install');
    if (btn) btn.disabled = true;
    installInProgress = true;
    updateDbExistingHints();
    setInstallReady('ok', '正在安装，请勿关闭或刷新页面');
    renderInstallProgress(0);
    runInstallSteps(0, null);
  });
  updateProgress();
})();
</script>
</body>
</html>
