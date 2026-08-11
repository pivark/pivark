<?php
/**
 * 后台 REST 内容域路由（/api/v1/admin/documents|tags|tag-groups|items）
 */
declare(strict_types=1);

use app\admin\controller\content\Document;
use app\admin\controller\content\Item;
use app\admin\controller\content\Tag;
use app\admin\controller\content\TagGroup;

/**
 * @param array{0:class-string,1:string} $handler
 * @return array{method:string,path:string,handler:array{0:class-string,1:string},options:array<string,mixed>}
 */
$cr = static function (
    string $method,
    string $path,
    array $handler,
    ?string $permissionController = null,
    ?string $permissionAction = null,
): array {
    $controller = $permissionController ?? strtolower(
        preg_replace('/^.*\\\\/', '', $handler[0]) ?: 'gateway',
    );

    return [
        'method'  => $method,
        'path'    => $path,
        'handler' => $handler,
        'options' => [
            'permission_controller' => $controller,
            'permission_action'     => $permissionAction ?? strtolower((string) $handler[1]),
        ],
    ];
};

return [
    // documents
    $cr('GET', 'documents', [Document::class, 'index'], 'document', 'index'),
    $cr('POST', 'documents', [Document::class, 'save'], 'document', 'save'),
    $cr('POST', 'documents/derive-seo', [Document::class, 'deriveSeo']),
    $cr('POST', 'documents/editor-surface-order', [Document::class, 'saveEditorSurfaceOrder']),
    $cr('POST', 'documents/status', [Document::class, 'status']),
    $cr('POST', 'documents/delete', [Document::class, 'delete']),
    $cr('POST', 'documents/batch-delete', [Document::class, 'batchDelete']),
    $cr('POST', 'documents/batch-status', [Document::class, 'batchStatus']),
    $cr('POST', 'documents/batch-tags', [Document::class, 'batchTags']),
    $cr('POST', 'documents/batch-attr', [Document::class, 'batchAttr']),
    $cr('POST', 'documents/batch-seo', [Document::class, 'batchSeo']),
    $cr('POST', 'documents/restore', [Document::class, 'restore']),
    $cr('POST', 'documents/purge-recycle', [Document::class, 'purgeRecycle']),
    $cr('POST', 'documents/empty-recycle', [Document::class, 'emptyRecycle']),
    $cr('GET', 'documents/export', [Document::class, 'export']),
    $cr('GET', 'documents/export-json', [Document::class, 'exportJson']),
    $cr('POST', 'documents/import', [Document::class, 'import']),
    $cr('POST', 'documents/import-json', [Document::class, 'importJson']),
    $cr('POST', 'documents/upload', [Document::class, 'upload']),
    $cr('GET', 'documents/tags', [Document::class, 'getTags']),
    $cr('GET', 'documents/qrcode', [Document::class, 'qrcode']),
    $cr('GET', 'documents/search-text-preview', [Document::class, 'searchTextPreview']),
    $cr('POST', 'documents/bulk-replace-preview', [Document::class, 'bulkReplacePreview']),
    $cr('POST', 'documents/bulk-replace', [Document::class, 'bulkReplace']),
    $cr('GET', 'documents/block-meta', [Document::class, 'blockMeta']),
    $cr('POST', 'documents/block-preview', [Document::class, 'blockPreview']),
    $cr('GET', 'clipboard/url-insight', [Document::class, 'clipboardUrlInsight'], 'document', 'clipboardurlinsight'),

    // tags
    $cr('GET', 'tags', [Tag::class, 'index'], 'tag', 'index'),
    $cr('POST', 'tags', [Tag::class, 'save'], 'tag', 'save'),
    $cr('POST', 'tags/sort', [Tag::class, 'sort']),
    $cr('POST', 'tags/status', [Tag::class, 'status']),
    $cr('POST', 'tags/delete', [Tag::class, 'delete']),
    $cr('POST', 'tags/batch-delete', [Tag::class, 'batchDelete']),
    $cr('POST', 'tags/batch-status', [Tag::class, 'batchStatus']),
    $cr('POST', 'tags/batch-reconcile', [Tag::class, 'batchReconcile']),
    $cr('POST', 'tags/merge', [Tag::class, 'merge']),
    $cr('POST', 'tags/reconcile', [Tag::class, 'reconcile']),
    $cr('GET', 'tags/options', [Tag::class, 'options']),
    $cr('GET', 'tags/parent-options', [Tag::class, 'parentOptions']),
    $cr('GET', 'tags/detail', [Tag::class, 'detail'], 'tag', 'detail'),

    // tag-groups
    $cr('GET', 'tag-groups', [TagGroup::class, 'index'], 'taggroup', 'index'),
    $cr('GET', 'meta/tag-groups', [TagGroup::class, 'meta'], 'taggroup', 'meta'),
    $cr('POST', 'tag-groups', [TagGroup::class, 'save'], 'taggroup', 'save'),
    $cr('POST', 'tag-groups/sort', [TagGroup::class, 'sort']),
    $cr('POST', 'tag-groups/status', [TagGroup::class, 'status']),
    $cr('POST', 'tag-groups/delete', [TagGroup::class, 'delete']),

    // items（原 product/items）
    $cr('GET', 'items', [Item::class, 'index'], 'item', 'index'),
    $cr('GET', 'meta/items', [Item::class, 'meta'], 'item', 'meta'),
    $cr('GET', 'items/export', [Item::class, 'export']),
    $cr('POST', 'items', [Item::class, 'save'], 'item', 'save'),
    $cr('POST', 'items/delete', [Item::class, 'delete']),
    $cr('POST', 'items/duplicate', [Item::class, 'duplicate']),
    $cr('POST', 'items/import', [Item::class, 'import']),
    $cr('POST', 'items/bulk-status', [Item::class, 'bulkStatus']),
    $cr('POST', 'items/bulk-delete', [Item::class, 'bulkDelete']),
    $cr('POST', 'items/import-preview', [Item::class, 'importPreview']),
    $cr('GET', 'items/by-document', [Item::class, 'itemsByDocument']),
    $cr('GET', 'items/documents-by-item', [Item::class, 'documentsByItem']),
    $cr('GET', 'items/variants', [Item::class, 'variantsByItem']),
    $cr('POST', 'items/variants', [Item::class, 'variantSave']),
    $cr('POST', 'items/variants/delete', [Item::class, 'variantDelete']),
    $cr('POST', 'items/ensure-detail-document', [Item::class, 'ensureDetailDocument']),
    $cr('POST', 'items/reload-plugin-docs', [Item::class, 'reloadPluginDocs']),
    $cr('POST', 'items/patch-list-visibility', [Item::class, 'patchListVisibility']),
    $cr('POST', 'items/patch-list-sort', [Item::class, 'patchListSort']),
    $cr('POST', 'items/patch-marketplace-listing-price', [Item::class, 'patchMarketplaceListingPrice']),
    $cr('POST', 'items/patch-variant-sort', [Item::class, 'patchVariantSort']),
    $cr('GET', 'items/accessories', [Item::class, 'accessoriesByItem']),
    $cr('POST', 'items/sync-accessories', [Item::class, 'syncAccessories']),
    $cr('GET', 'items/export-async/start', [Item::class, 'exportAsyncStart']),
    $cr('GET', 'items/export-async/step', [Item::class, 'exportAsyncStep']),
    $cr('GET', 'items/export-async/download', [Item::class, 'exportAsyncDownload']),
];
