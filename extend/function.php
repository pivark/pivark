<?php
/**
 * 站点用户扩展函数（对标 EyouCMS extend/function.php）
 *
 * - 本文件由内核 ExtendBootstrap 在 HTTP/CLI 启动时 include_once
 * - 函数名必须以 diy_ 开头，避免与内核/插件冲突
 * - 供 ThemePageDataContract provider「extend::diy_xxx」或内核 function_exists 挂钩调用
 * - 升级/同步内核时请勿覆盖本文件（发行包仅带空壳）
 *
 * 示例：
 *   function diy_pricing_template_vars(array $context = []): array {
 *       return ['pricing_rows' => [...]];
 *   }
 */
declare(strict_types=1);
