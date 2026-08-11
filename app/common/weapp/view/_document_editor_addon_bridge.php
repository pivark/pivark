<?php
/**
 * 文档发布页 — L2 插件桥接块（条目在插件后台维护）
 *
 * @var string $addonBridgeId      插件 identifier
 * @var string $addonBridgeLabel   块标题
 * @var string $addonBridgeListUrl 条目管理 URL（可含 document_id 查询）
 * @var int    $articleId
 * @var int    $addonBridgeCount   当前文档条目数
 */
$addonBridgeId = strtolower(trim((string) ($addonBridgeId ?? '')));
$addonBridgeLabel = trim((string) ($addonBridgeLabel ?? ''));
$addonBridgeListUrl = trim((string) ($addonBridgeListUrl ?? ''));
$articleId = (int) ($articleId ?? 0);
$addonBridgeCount = (int) ($addonBridgeCount ?? 0);
if ($addonBridgeId === '' || $addonBridgeLabel === '') {
    return;
}
?>
<div class="pv-plugin-inline-block pv-plugin-inline-<?= htmlspecialchars($addonBridgeId) ?>">
    <div class="pv-plugin-inline-hd">
        <span class="pv-plugin-inline-title"><?= htmlspecialchars($addonBridgeLabel) ?></span>
        <span class="pv-plugin-inline-hint">插件扩展区 · 条目在插件后台维护</span>
    </div>
    <div class="pv-plugin-inline-bd">
        <?php if ($articleId < 1): ?>
        <p class="pv-attr-hint">请先<strong>保存文档</strong>获得文档 ID 后，再在条目管理中为本文档添加内容。</p>
        <?php else: ?>
        <p class="pv-attr-hint">
            当前文档已关联 <strong><?= $addonBridgeCount ?></strong> 条启用条目。
            <?php if ($addonBridgeListUrl !== ''): ?>
            <a href="<?= htmlspecialchars($addonBridgeListUrl) ?>" target="_blank" class="pv-link-btn">打开条目管理</a>
            <?php endif; ?>
        </p>
        <?php endif; ?>
    </div>
</div>
