<?php
/** @var string $title @var string $navKey @var string $pluginViewDir */
$title = $title ?? '使用说明';
$navKey = 'usage';
include $pluginViewDir . '/../layout/_iframe_header.php';
include $pluginViewDir . '/_app_shell_start.php';
?>
<style>
.pv-usage-doc { font-size: 13px; line-height: 1.85; color: #444; }
.pv-usage-doc h3 { font-size: 15px; margin: 22px 0 10px; color: #333; font-weight: 600; }
.pv-usage-doc h3:first-child { margin-top: 0; }
.pv-usage-doc p { margin: 0 0 12px; }
.pv-usage-doc ul, .pv-usage-doc ol { margin: 0 0 14px; padding-left: 22px; }
.pv-usage-doc li { margin-bottom: 6px; }
.pv-usage-doc .pv-weapp-code { margin: 10px 0 16px; white-space: pre-wrap; word-break: break-all; }
.pv-usage-doc .pv-tip { color: #999; font-size: 12px; margin-top: 8px; }
.pv-usage-doc table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; font-size: 12px; }
.pv-usage-doc th, .pv-usage-doc td { border: 1px solid #eef0f3; padding: 8px 10px; text-align: left; }
.pv-usage-doc th { background: #f8f9fb; font-weight: 600; }
</style>
<div class="pv-weapp-card">
    <div class="pv-weapp-card-hd">使用说明</div>
    <div class="pv-weapp-card-bd pv-usage-doc">
        <?php include $pluginViewDir . '/_usage_body.php'; ?>
    </div>
</div>
<?php include $pluginViewDir . '/_app_shell_end.php'; ?>
