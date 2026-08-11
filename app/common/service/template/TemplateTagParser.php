<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\template;

use app\common\service\site\SiteModeService;
use app\common\support\OpsLog;
use app\common\support\SiteDomainContext;
use app\common\support\SiteUrl;

/**
 * 前台 {pv:*} 标签模板引擎（纯标签解析，不使用 eval）
 */

/** {pv:*} 标签解析（foreach/if/data） */
class TemplateTagParser
{

public function parseTags(string $html, array $pageVars = [], ?string $theme = null, bool $precompiled = false): string
    {
        try {
            if ($theme !== null) {
                TemplateEngineState::$activeTheme = $theme;
            }

            if (!$precompiled) {
                $html = app(TemplateSiteVars::class)->stripHtmlComments($html);
                $html = $this->normalizeLegacyTagNames($html);
            } elseif (!app(TemplateTagTokenizer::class)->hasPvTags($html)) {
                return $this->stripRemainingPvTags($html);
            }

            $html = $this->resolveVarsInPvOpenTags($html, $pageVars);

            $depth   = 0;
            $engine  = app(TemplateEngineState::class);
            while ($depth < TemplateEngineState::MAX_PARSE_DEPTH) {
                $pageVars = array_merge(app(TemplateAssignStateService::class)->all(), $pageVars);
                if (!$engine->hasDetectablePvTags($html)) {
                    break;
                }
                $html = $this->parseStructuralTags($html, $pageVars);
                $html = $this->parseDataTags($html, $pageVars);
                $html = $this->parseIfTags($html, $pageVars);
                $depth++;
            }

            $pageVars = array_merge(app(TemplateAssignStateService::class)->all(), $pageVars);

            return $this->stripRemainingPvTags($this->applyPageVars($html, $pageVars));
        } catch (\Throwable $e) {
            $this->logTagFailure('template_parse_tags_failed', [
                'msg' => $e->getMessage(),
            ]);

            $pageVars = array_merge(app(TemplateAssignStateService::class)->all(), $pageVars);

            return $this->stripRemainingPvTags($this->applyPageVars($html, $pageVars));
        }
    }

private function resolveVarsInPvOpenTags(string $html, array $pageVars): string
    {
        return preg_replace_callback(
            '/\{pv:([a-z][a-z0-9_]*)\b((?:[^{}]|\{\$[a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)*\})*)\}/i',
            function (array $m) use ($pageVars): string {
                $attrs = preg_replace_callback(
                    '/\{\$([a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)*)\}/',
                    function (array $vm) use ($pageVars): string {
                        $val = $this->resolvePath($pageVars, $vm[1]);
                        if (is_array($val) || $val === null) {
                            return '';
                        }

                        return is_scalar($val) ? (string) $val : '';
                    },
                    $m[2]
                );

                return '{pv:' . $m[1] . $attrs . '}';
            },
            $html
        ) ?? $html;
    }

    /** 旧模板别名 → 规范短标签名（nav / list / arclist / page / tagnav） */
    public function normalizeLegacyTagNames(string $html): string
    {
        return $this->normalizeTagAliases($html);
    }

    /**
     * 模板标签规范短名（易记）；旧名自动归一，解析器只注册短名。
     *
     * @var array<string, string> 旧名 => 短名
     */
    private const TAG_ALIAS_MAP = [
        'navigation'   => 'nav',
        'documentlist' => 'list',
        'tagdocuments' => 'arclist',
        'documents'    => 'arclist',
        'pagelist'     => 'page',
        'pagination'   => 'page',
        'tagsnav'      => 'tagnav',
        'volist'       => 'foreach',
    ];

    public function normalizeTagAliases(string $html): string
    {
        foreach (self::TAG_ALIAS_MAP as $from => $to) {
            $html = preg_replace(
                '/\{pv:' . preg_quote($from, '/') . '\b/i',
                '{pv:' . $to,
                $html
            ) ?? $html;
            $html = preg_replace(
                '/\{\/pv:' . preg_quote($from, '/') . '\}/i',
                '{/pv:' . $to . '}',
                $html
            ) ?? $html;
        }

        return $html;
    }

    /** @return list<string> 规范短标签名（引擎注册用） */
    public static function canonicalTagNames(): array
    {
        return ['nav', 'list', 'arclist', 'page', 'tagnav'];
    }

public function stripRemainingPvTags(string $html): string
    {
        if (app(SiteModeService::class)->isDev()) {
            $html = preg_replace_callback(
                '/\{pv:([a-z][a-z0-9_]*)\b[^}]*\}.*?\{\/pv:\1\}/is',
                static fn (array $m): string => '<!-- pv:unparsed block ' . htmlspecialchars($m[1], ENT_QUOTES) . ' -->',
                $html
            ) ?? $html;
            $html = preg_replace_callback(
                '/\{pv:([a-z][a-z0-9_]*)\b[^}]*\}/i',
                static fn (array $m): string => '<!-- pv:unparsed ' . htmlspecialchars($m[1], ENT_QUOTES) . ' -->',
                $html
            ) ?? $html;

            return $html;
        }

        $html = preg_replace('/\{pv:([a-z][a-z0-9_]*)\b[^}]*\}.*?\{\/pv:\1\}/is', '', $html) ?? $html;
        $html = preg_replace('/\{pv:([a-z][a-z0-9_]*)\b[^}]*\}/i', '', $html) ?? $html;

        return $html;
    }

public function applyPageVars(string $html, array $vars): string
    {
        $filters = app(TemplateVarFilterService::class);
        $html    = preg_replace_callback(
            '/\{\$((?:[a-zA-Z_][\w]*)(?:\.[a-zA-Z_][\w]*)*)\|default(?::([^}|]+))?\}/',
            function (array $m) use ($vars, $filters): string {
                $val      = $this->resolvePath($vars, $m[1]);
                $fallback = isset($m[2]) ? trim($m[2], " \t\"'") : '';

                return htmlspecialchars($filters->withDefault($val, $fallback), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $html
        ) ?? $html;
        $html    = preg_replace_callback(
            '/\{\$((?:[a-zA-Z_][\w]*)(?:\.[a-zA-Z_][\w]*)*)\|truncate(?::(\d+))?(?::([^}|]*))?\}/',
            function (array $m) use ($vars, $filters): string {
                $val = $this->resolvePath($vars, $m[1]);
                $len = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 80;
                $suffix = isset($m[3]) && $m[3] !== '' ? $m[3] : '...';

                return htmlspecialchars($filters->truncate((string) $val, $len, $suffix), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $html
        ) ?? $html;
        $html = preg_replace_callback(
            '/\{\$([a-zA-Z_][\w]*)\|raw\}/',
            static function (array $m) use ($vars) {
                $val = $vars[$m[1]] ?? '';
                return is_scalar($val) ? (string) $val : '';
            },
            $html
        );
        $html = preg_replace_callback(
            '/\{\$([a-zA-Z_][\w]*)\.([a-zA-Z_][\w]*)\|raw\}/',
            function (array $m) use ($vars) {
                $val = $this->resolvePath($vars, $m[1] . '.' . $m[2]);
                return is_scalar($val) ? (string) $val : '';
            },
            $html
        );
        $html = preg_replace_callback(
            '/\{\$([a-zA-Z_][\w]*)\.([a-zA-Z_][\w]*)\}/',
            function (array $m) use ($vars) {
                $val = $this->resolvePath($vars, $m[1] . '.' . $m[2]);
                // extends 为 <a> 属性片段，不可转义（见 SiteNavService::buildNavLinkExtends）
                if ($m[2] === 'extends' && is_scalar($val)) {
                    return (string) $val;
                }

                return htmlspecialchars((string) $val);
            },
            $html
        );
        return preg_replace_callback(
            '/\{\$([a-zA-Z_][\w]*)\}/',
            static function (array $m) use ($vars) {
                $val = $vars[$m[1]] ?? '';
                if (is_array($val)) {
                    return '';
                }
                return htmlspecialchars((string) $val);
            },
            $html
        );
    }

private function parseStructuralTags(string $html, array $pageVars): string
    {
        $html = preg_replace_callback(
            '/\{pv:assign\b([^}]*)\}/i',
            $this->tagCallback('assign', fn (array $m) => $this->renderAssignTag($m, $pageVars)),
            $html
        );
        $html = $this->parseCacheTags($html, $pageVars);
        // 先展开循环/块，再 include：否则 foreach 体内的 include 会在无 item 时被提前渲染成空
        $html = $this->parsePairedLoopTags($html, $pageVars, 'foreach', fn (array $m): string => $this->renderForeach($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'breadcrumb', fn (array $m): string => $this->renderBreadcrumbBlock($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'position', fn (array $m): string => $this->renderBreadcrumbBlock($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'nav', fn (array $m): string => $this->renderNavigation($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'tagnav', fn (array $m): string => $this->renderTagsNav($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'page', fn (array $m): string => $this->renderPagelist($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'tagpage', fn (array $m): string => $this->renderTagPage($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'list', fn (array $m): string => $this->renderDocumentlistBlock($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'seo', fn (array $m): string => $this->renderSeoBlock($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'tagindex', fn (array $m): string => $this->renderTagIndexBlock($m, $pageVars));
        $html = $this->parsePairedLoopTags($html, $pageVars, 'searchform', fn (array $m): string => $this->renderSearchformBlock($m, $pageVars));
        // 有内容才保留整块（标题可在壳内；判空区用 {pv:content}）
        $html = $this->parsePairedLoopTags($html, $pageVars, 'section', fn (array $m): string => $this->renderSection($m, $pageVars));

        $html = preg_replace_callback(
            '/\{pv:include\b([^}]*)\}/i',
            $this->tagCallback('include', static fn (array $m) => app(TemplateBlockRenderer::class)->renderInclude($m, $pageVars)),
            $html
        );

        return $html;
    }

    /**
     * @param callable(array{0:string,1:string,2:string}):string $render
     */
    private function parsePairedLoopTags(string $html, array $pageVars, string $tagName, callable $render): string
    {
        $guard = 0;
        $openTag = '{pv:' . $tagName;
        while ($guard < TemplateEngineState::MAX_PARSE_DEPTH && stripos($html, $openTag) !== false) {
            $next = $this->replaceOutermostPairedLoop($html, $tagName, $render);
            if ($next === null) {
                break;
            }
            $html = $next;
            $guard++;
        }

        return $html;
    }

    /**
     * @param callable(array{0:string,1:string,2:string}):string $render
     */
    private function replaceOutermostPairedLoop(string $html, string $tagName, callable $render): ?string
    {
        $openTag = '{pv:' . $tagName;
        if (!preg_match('/' . preg_quote($openTag, '/') . '\b/i', $html, $openMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = (int) $openMatch[0][1];
        $openEnd = strpos($html, '}', $start);
        if ($openEnd === false) {
            return null;
        }
        if (!preg_match('/' . preg_quote($openTag, '/') . '\b([^}]*)\}/is', substr($html, $start, $openEnd - $start + 1), $tagMatch)) {
            return null;
        }
        $attrs = $tagMatch[1];

        $depth    = 1;
        $cursor   = $openEnd + 1;
        $length   = strlen($html);
        $openLen  = strlen($openTag);
        $close    = '{/pv:' . $tagName . '}';
        $closeLen = strlen($close);

        while ($cursor < $length) {
            $nextOpen  = stripos($html, $openTag, $cursor);
            $nextClose = stripos($html, $close, $cursor);
            if ($nextClose === false) {
                return null;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $cursor = $nextOpen + $openLen;
                continue;
            }
            $depth--;
            if ($depth === 0) {
                $body     = substr($html, $openEnd + 1, $nextClose - $openEnd - 1);
                $rendered = $this->safeTagRender($tagName, fn (): string => $render(['', $attrs, $body]));

                return substr($html, 0, $start) . $rendered . substr($html, $nextClose + $closeLen);
            }
            $cursor = $nextClose + $closeLen;
        }

        return null;
    }

private function parseCacheTags(string $html, array $pageVars): string
    {
        $guard = 0;
        while ($guard < TemplateEngineState::MAX_PARSE_DEPTH && stripos($html, '{pv:cache') !== false) {
            $next = $this->replaceOutermostCache($html, $pageVars);
            if ($next === null) {
                break;
            }
            $html = $next;
            $guard++;
        }

        return $html;
    }

private function replaceOutermostCache(string $html, array $pageVars): ?string
    {
        if (!preg_match('/\{pv:cache\b/i', $html, $openMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = (int) $openMatch[0][1];
        $openEnd = strpos($html, '}', $start);
        if ($openEnd === false) {
            return null;
        }
        if (!preg_match('/\{pv:cache\b([^}]*)\}/is', substr($html, $start, $openEnd - $start + 1), $tagMatch)) {
            return null;
        }
        $attrs = $tagMatch[1];

        $depth    = 1;
        $cursor   = $openEnd + 1;
        $length   = strlen($html);
        $openLen  = strlen('{pv:cache');
        $close    = '{/pv:cache}';
        $closeLen = strlen($close);

        while ($cursor < $length) {
            $nextOpen  = stripos($html, '{pv:cache', $cursor);
            $nextClose = stripos($html, $close, $cursor);
            if ($nextClose === false) {
                return null;
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $cursor = $nextOpen + $openLen;
                continue;
            }
            $depth--;
            if ($depth === 0) {
                $body     = substr($html, $openEnd + 1, $nextClose - $openEnd - 1);
                $rendered = $this->safeTagRender('cache', fn (): string => $this->renderCache(['', $attrs, $body], $pageVars));

                return substr($html, 0, $start) . $rendered . substr($html, $nextClose + $closeLen);
            }
            $cursor = $nextClose + $closeLen;
        }

        return null;
    }

private function renderCache(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');
        $name  = trim((string) ($attrs['name'] ?? ''));
        $ttl   = (int) ($attrs['ttl'] ?? app(TemplateFragmentCacheService::class)->defaultTtl());
        $body  = (string) ($m[2] ?? '');
        $theme = TemplateEngineState::$activeTheme ?? 'default';

        if ($name === '' || $ttl <= 0) {
            return $this->parseTags($body, $pageVars, $theme, true);
        }

        return app(TemplateFragmentCacheService::class)->remember($name, $theme, $ttl, function () use ($body, $pageVars, $theme): string {
            return $this->parseTags($body, $pageVars, $theme, true);
        });
    }

private function parseIfTags(string $html, array $pageVars): string
    {
        $guard = 0;
        while ($guard < TemplateEngineState::MAX_PARSE_DEPTH && stripos($html, '{pv:if') !== false) {
            $next = $this->replaceInnermostIf($html, $pageVars);
            if ($next === null) {
                break;
            }
            $html = $next;
            $guard++;
        }

        return $html;
    }

    private function replaceInnermostIf(string $html, array $pageVars): ?string
    {
        $length   = strlen($html);
        $cursor   = 0;
        $openTag  = '{pv:if';
        $openLen  = strlen($openTag);
        $closeTag = '{/pv:if}';
        $closeLen = strlen($closeTag);
        $elseTag  = '{pv:else}';
        $elseLen  = strlen($elseTag);

        while ($cursor < $length) {
            $start = stripos($html, $openTag, $cursor);
            if ($start === false) {
                return null;
            }
            $openEnd = strpos($html, '}', $start);
            if ($openEnd === false) {
                return null;
            }
            if (!preg_match('/\{pv:if\b([^}]*)\}/is', substr($html, $start, $openEnd - $start + 1), $tagMatch)) {
                $cursor = $start + $openLen;
                continue;
            }
            $attrs = $tagMatch[1];

            $depth    = 1;
            $scan     = $openEnd + 1;
            $elsePos  = null;
            $closePos = null;

            while ($scan < $length && $depth > 0) {
                $nextIf    = stripos($html, $openTag, $scan);
                $nextElse  = stripos($html, $elseTag, $scan);
                $nextClose = stripos($html, $closeTag, $scan);
                if ($nextClose === false) {
                    return null;
                }

                $next = $nextClose;
                $kind = 'close';
                if ($nextIf !== false && $nextIf < $next) {
                    $next = $nextIf;
                    $kind = 'if';
                }
                if ($nextElse !== false && $nextElse < $next) {
                    $next = $nextElse;
                    $kind = 'else';
                }

                if ($kind === 'if') {
                    $depth++;
                    $scan = $nextIf + $openLen;
                } elseif ($kind === 'else' && $depth === 1) {
                    $elsePos = $nextElse;
                    $scan    = $nextElse + $elseLen;
                } elseif ($kind === 'close') {
                    $depth--;
                    if ($depth === 0) {
                        $closePos = $nextClose;
                        break;
                    }
                    $scan = $nextClose + $closeLen;
                } else {
                    $scan = $next + 1;
                }
            }

            if ($closePos === null) {
                $cursor = $start + $openLen;
                continue;
            }

            $bodyStart = $openEnd + 1;
            $bodyEnd   = $closePos;
            $fullBody  = substr($html, $bodyStart, $bodyEnd - $bodyStart);
            if (stripos($fullBody, $openTag) !== false) {
                $cursor = $start + $openLen;
                continue;
            }

            if ($elsePos !== null && $elsePos >= $bodyStart && $elsePos < $bodyEnd) {
                $thenTpl = substr($html, $bodyStart, $elsePos - $bodyStart);
                $elseTpl = substr($html, $elsePos + $elseLen, $bodyEnd - $elsePos - $elseLen);
            } else {
                $thenTpl = $fullBody;
                $elseTpl = '';
            }

            $rendered = $this->safeTagRender('if', fn (): string => $this->renderIf(['', $attrs, $thenTpl, $elseTpl], $pageVars));

            return substr($html, 0, $start) . $rendered . substr($html, $closePos + $closeLen);
        }

        return null;
    }

    /**
     * 逐层解析 {pv:tag}...{/pv:tag}，避免 (.*?) 在大 HTML 上灾难性回溯
     *
     * @param callable(array{0:string,1:string,2:string}):string $render
     */
    private function parsePairedBlockTags(string $html, string $tagName, callable $render): string
    {
        $safeRender = fn (array $m): string => $this->safeTagRender($tagName, $render, $m);
        $openTag = '{pv:' . $tagName;
        $guard   = 0;
        while ($guard < TemplateEngineState::MAX_PARSE_DEPTH && stripos($html, $openTag) !== false) {
            $next = $this->replaceInnermostPairedBlock($html, $tagName, $safeRender);
            if ($next === null) {
                break;
            }
            $html = $next;
            $guard++;
        }

        return $html;
    }

    /**
     * @param callable(array{0:string,1:string,2:string}):string $render
     */
    private function replaceInnermostPairedBlock(string $html, string $tagName, callable $render): ?string
    {
        $openTag  = '{pv:' . $tagName;
        $openLen  = strlen($openTag);
        $closeTag = '{/pv:' . $tagName . '}';
        $closeLen = strlen($closeTag);
        $length   = strlen($html);
        $cursor   = 0;

        while ($cursor < $length) {
            $start = stripos($html, $openTag, $cursor);
            if ($start === false) {
                return null;
            }
            $openEnd = strpos($html, '}', $start);
            if ($openEnd === false) {
                return null;
            }
            $openSlice = substr($html, $start, $openEnd - $start + 1);
            if (!preg_match('/\{pv:' . preg_quote($tagName, '/') . '\b([^}]*)\}/is', $openSlice, $tagMatch)) {
                $cursor = $start + $openLen;
                continue;
            }
            $attrs = $tagMatch[1];

            $depth    = 1;
            $scan     = $openEnd + 1;
            $closePos = null;

            while ($scan < $length && $depth > 0) {
                $nextOpen  = stripos($html, $openTag, $scan);
                $nextClose = stripos($html, $closeTag, $scan);
                if ($nextClose === false) {
                    return null;
                }
                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $depth++;
                    $scan = $nextOpen + $openLen;
                    continue;
                }
                $depth--;
                if ($depth === 0) {
                    $closePos = $nextClose;
                    break;
                }
                $scan = $nextClose + $closeLen;
            }

            if ($closePos === null) {
                $cursor = $start + $openLen;
                continue;
            }

            $bodyStart = $openEnd + 1;
            $body      = substr($html, $bodyStart, $closePos - $bodyStart);
            if (stripos($body, $openTag) !== false) {
                $cursor = $start + $openLen;
                continue;
            }

            $rendered = $render([$openSlice, $attrs, $body]);

            return substr($html, 0, $start) . $rendered . substr($html, $closePos + $closeLen);
        }

        return null;
    }

private function parseDataTags(string $html, array $pageVars): string
    {
        $html = preg_replace_callback(
            '/\{pv:config\b([^}]*)\}/i',
            $this->tagCallback('config', static fn (array $m) => app(TemplateBlockRenderer::class)->renderConfig($m)),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:var\b([^}]*)\}/i',
            $this->tagCallback('var', static fn (array $m) => app(TemplateBlockRenderer::class)->renderCustomVar($m)),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:breadcrumb\b([^}]*)\}/i',
            $this->tagCallback('breadcrumb', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'breadcrumb',
                '块标签 item=bc，例：<span>{$bc.title}</span>；或 {pv:foreach} breadcrumbs'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:position\b([^}]*)\}/i',
            $this->tagCallback('position', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'position',
                '与 breadcrumb 相同；块标签 item=bc 或 foreach breadcrumbs'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:page\b([^}]*)\}/i',
            $this->tagCallback('page', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'page',
                '块标签 item=pg，须 list_page=1 或 tag_catalog_page=1'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:nav\b([^}]*)\}/i',
            $this->tagCallback('nav', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'nav',
                '块标签 item=n，例：<a href="{$n.url}">{$n.title}</a>'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:seo\b([^}]*)\}/i',
            $this->tagCallback('seo', fn (array $m): string => $this->renderSeoSelfClosing($m, $pageVars)),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:tagnav\b([^}]*)\}/i',
            $this->tagCallback('tagnav', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'tagnav',
                '块标签 item=t，数据源 tags_nav / tag_nav_groups；全站 Tag 目录用 {pv:tagcloud}'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:searchform\b([^}]*)\}/i',
            $this->tagCallback('searchform', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'searchform',
                '块内写 input/button；action 默认 {$search_url}，参数名默认 q'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:tagindex\b([^}]*)\}/i',
            $this->tagCallback('tagindex', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'tagindex',
                '块内 item=tag，须 tag_catalog_page=1；侧栏请用 {pv:tagcloud}'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:list\b([^}]*)\}/i',
            $this->tagCallback('list', static fn (array $m) => app(TemplateBlockRenderer::class)->renderDocumentList($m, $pageVars)),
            $html
        );
        $html = $this->parsePairedBlockTags(
            $html,
            'arclist',
            static fn (array $m) => app(TemplateBlockRenderer::class)->renderTagArticles($m, $pageVars)
        );
        $html = $this->parsePairedBlockTags(
            $html,
            'tag',
            fn (array $m): string => $this->renderTagBlock($m, $pageVars)
        );
        $html = preg_replace_callback(
            '/\{pv:tag\b([^}]*)\}/i',
            $this->tagCallback('tag', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'tag',
                '块内 item=t，例：<a href="{$t.url}">{$t.name}</a>；多条请用 {pv:tagcloud}'
            )),
            $html
        );
        $html = $this->parsePairedBlockTags(
            $html,
            'tagcloud',
            static fn (array $m) => app(TemplateBlockRenderer::class)->renderTagCloud($m, $pageVars)
        );
        $html = $this->parsePairedBlockTags(
            $html,
            'taglist',
            static fn (array $m) => app(TemplateBlockRenderer::class)->renderTagList($m, $pageVars)
        );
        $html = $this->parsePairedBlockTags(
            $html,
            'arcview',
            static fn (array $m) => app(TemplateBlockRenderer::class)->renderArcViewBlock($m, $pageVars)
        );
        $html = $this->parsePairedBlockTags(
            $html,
            'related',
            static fn (array $m) => app(TemplateBlockRenderer::class)->renderRelatedBlock($m, $pageVars)
        );
        $html = preg_replace_callback(
            '/\{pv:arcview\b([^}]*)\}/i',
            $this->tagCallback('arcview', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'arcview',
                '块标签 arcview 须带 id 与模板体'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:tagpage\b([^}]*)\}/i',
            $this->tagCallback('tagpage', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'tagpage',
                '块标签 item=pg，须 tag_catalog_page=1'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:related\b([^}]*)\}/i',
            $this->tagCallback('related', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'related',
                '块标签 related 须带 id 与模板体'
            )),
            $html
        );
        $html = preg_replace_callback(
            '/\{pv:tagurl\b([^}]*)\}/i',
            $this->tagCallback('tagurl', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'tagurl',
                '<a href="{$tag_url_*}">…</a> 或手写链接'
            )),
            $html
        );
        $html = $this->parsePairedBlockTags(
            $html,
            'friendlinks',
            static fn (array $m) => app(TemplateBlockRenderer::class)->renderFriendLinks($m, $pageVars)
        );
        $html = $this->parsePairedBlockTags(
            $html,
            'siteads',
            static fn (array $m) => app(TemplateBlockRenderer::class)->renderSiteAds($m, $pageVars)
        );
        $html = preg_replace_callback(
            '/\{pv:siteads\b([^}]*)\}/i',
            $this->tagCallback('siteads', static fn (array $m) => app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'siteads',
                '块标签 siteads 须带 slot 与模板体'
            )),
            $html
        );

        try {
            foreach (app(TemplateEngineState::class)->registeredExtensionTagNames() as $tagName) {
                $html = $this->parsePairedBlockTags(
                    $html,
                    $tagName,
                    fn (array $m) => $this->renderExtensionTag($tagName, $m, $pageVars)
                );
                $quoted = preg_quote($tagName, '/');
                $html   = preg_replace_callback(
                    '/\{pv:' . $quoted . '\b([^}]*)\}/i',
                    fn (array $m) => $this->renderExtensionTag($tagName, [$m[0], $m[1], ''], $pageVars),
                    $html
                );
            }
        } catch (\Throwable $e) {
            $this->logTagFailure('template_registered_tags_failed', [
                'msg' => $e->getMessage(),
            ]);
        }

        return $html;
    }

private function renderExtensionTag(string $tagName, array $m, array $pageVars): string
    {
        return $this->safeTagRender($tagName, function () use ($tagName, $m, $pageVars): string {
            $handler = TemplateEngineState::resolveTagHandler($tagName);
            if ($handler === null) {
                return '';
            }
            $attrs = $this->parseAttrs($m[1]);
            foreach ($attrs as $key => $value) {
                $attrs[$key] = $this->resolveAttrValue($value, $pageVars);
            }
            $tpl = $m[2] ?? '';

            return (string) $handler($attrs, $pageVars, $tpl);
        });
    }

private function renderIf(array $m, array $pageVars): string
    {
        $attrs   = $this->parseAttrs($m[1]);
        $thenTpl = $m[2];
        $elseTpl = $m[3] ?? '';
        $ok      = false;

        if (isset($attrs['empty'])) {
            $val = $this->resolvePath($pageVars, (string) $attrs['empty']);
            $ok  = $val === [] || $val === '' || $val === null;
        } elseif (isset($attrs['mod'], $attrs['name'])) {
            $val = $this->resolvePath($pageVars, (string) $attrs['name']);
            $mod = max(1, (int) $attrs['mod']);
            if (!is_numeric($val)) {
                $ok = false;
            } else {
                $remainder = ((int) $val) % $mod;
                if ($remainder < 0) {
                    $remainder += $mod;
                }
                if (isset($attrs['value'])) {
                    $expect = (int) $attrs['value'];
                    $ok     = $remainder === $expect;
                } else {
                    $ok = $remainder === 0;
                }
            }
        } elseif (isset($attrs['value'], $attrs['name'])) {
            $val    = $this->resolvePath($pageVars, (string) $attrs['name']);
            $expect = (string) $attrs['value'];
            if (is_bool($val)) {
                $actual = $val ? '1' : '0';
            } elseif (is_int($val) || is_float($val)) {
                $actual = (string) (int) $val;
            } elseif (is_scalar($val)) {
                $actual = (string) $val;
            } else {
                $actual = '';
            }
            $ok = $actual === $expect;
        } elseif (isset($attrs['name'])) {
            $val = $this->resolvePath($pageVars, (string) $attrs['name']);
            $ok  = $this->isTruthyTemplateVar($val);
        }

        $chunk = $ok ? $thenTpl : $elseTpl;
        return $this->parseTags($chunk, $pageVars);
    }

public function renderForeach(array $m, array $pageVars): string
    {
        $attrs    = $this->parseAttrs($m[1]);
        $listName = (string) ($attrs['name'] ?? 'list');
        $itemName = (string) ($attrs['item'] ?? 'item');
        $tpl      = $m[2];
        $list     = $this->resolvePath($pageVars, $listName);

        if (!is_array($list)) {
            return '';
        }

        $listSvc    = app(\app\common\service\document\satellite\DocumentListTemplateService::class);
        $startIndex = $listSvc->resolveLoopStartFromAttrs($attrs);
        $out        = '';
        foreach ($list as $pos => $row) {
            if (!is_array($row)) {
                $childVars            = $pageVars;
                $childVars[$itemName] = $row;
                $chunk                = preg_replace(
                    '/\{\$' . preg_quote($itemName, '/') . '\}/',
                    htmlspecialchars((string) $row, ENT_QUOTES, 'UTF-8'),
                    $tpl,
                ) ?? $tpl;
                $out .= $this->parseTags($chunk, $childVars);
                continue;
            }
            $row                  = $listSvc->applyLoopIndexFields($row, (int) $pos, $startIndex, $attrs);
            $row                  = $listSvc->mapFieldAliases($row);
            $childVars            = $pageVars;
            $childVars[$itemName] = $row;
            $childVars['field']   = $row;
            $chunk                = $this->applyItemVars($tpl, $itemName, $row);
            $out                 .= $this->parseTags($chunk, $childVars);
        }

        return $out;
    }

    /** 顶栏导航块标签：前台导航专用，见 NavigationTemplateTagService */
    public function renderNavigation(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(NavigationTemplateTagService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars);
    }

    /** 标签侧栏块标签，见 TagNavTemplateService */
    public function renderTagsNav(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(\app\common\service\tag\TagNavTemplateService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars);
    }

    /** 分页块标签，见 PaginationTemplateTagService */
    public function renderPagelist(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(PaginationTemplateTagService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars, 'page');
    }

    /** 标签索引分页块（tag_catalog_page=1） */
    public function renderTagPage(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(PaginationTemplateTagService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars, 'tagpage');
    }

    /** 面包屑块标签 */
    public function renderBreadcrumbBlock(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(BreadcrumbTemplateTagService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars);
    }

    /** SEO meta 块标签 */
    public function renderSeoBlock(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(SeoTemplateTagService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars);
    }

    /** @param array{0:string,1?:string} $m */
    private function renderSeoSelfClosing(array $m, array $pageVars): string
    {
        $attrs = trim((string) ($m[1] ?? ''));
        if ($attrs !== '' && !str_contains($attrs, '/')) {
            return app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'seo',
                '默认 {pv:seo /}；自定义须 {pv:seo}…{/pv:seo} 块体'
            );
        }

        return app(SeoTemplateTagService::class)->renderAuto($pageVars);
    }

    /** 标签索引页块标签 */
    public function renderTagIndexBlock(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(TagIndexTemplateTagService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars);
    }

    /** 单 Tag 元信息块标签 */
    public function renderTagBlock(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(TagTemplateTagService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars);
    }

    /** 搜索 form 块标签 */
    public function renderSearchformBlock(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(SearchTemplateTagService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars);
    }

    /** `{pv:assign name="x" value="y" /}` 模板内赋值 */
    public function renderAssignTag(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');
        $name  = trim((string) ($attrs['name'] ?? $attrs['var'] ?? ''));
        if ($name === '') {
            return app(TemplateTagBlockOnlyService::class)->rejectSelfClosing(
                'assign',
                '须 name/var 与 value，例：{pv:assign name="foo" value="bar" /}'
            );
        }
        $value = $this->resolveAttrValue((string) ($attrs['value'] ?? ''), $pageVars);
        app(TemplateAssignStateService::class)->set($name, $value);

        return '';
    }

    /** 文档列表块标签，见 DocumentListTemplateTagService */
    public function renderDocumentlistBlock(array $m, array $pageVars): string
    {
        $attrs = $this->parseAttrs($m[1] ?? '');

        return app(DocumentListTemplateTagService::class)->renderBlock($attrs, (string) ($m[2] ?? ''), $pageVars);
    }

    /**
     * `{pv:section}`：渲染后为空则整块丢弃。
     * 可选内嵌 `{pv:content}…{/pv:content}`（仅 section 体内有效）：只对内容区判空，壳（标题等）随内容有无一起显隐。
     * 与 `{pv:list}` 的 `{pv:empty}` 对称——empty=没货要提示；content+section=没货整块不露。
     *
     * @param array{0?:string,1?:string,2?:string} $m
     * @param array<string, mixed> $pageVars
     */
    public function renderSection(array $m, array $pageVars): string
    {
        $body  = (string) ($m[2] ?? '');
        $theme = TemplateEngineState::$activeTheme;

        if (preg_match('/\{pv:content\}(.*)\{\/pv:content\}/is', $body, $cm, PREG_OFFSET_CAPTURE)) {
            $fullMatch = $cm[0][0];
            $offset    = (int) $cm[0][1];
            $contentTpl = $cm[1][0];
            $before     = substr($body, 0, $offset);
            $after      = substr($body, $offset + strlen($fullMatch));

            $contentOut = $this->parseTags($contentTpl, $pageVars, $theme, true);
            if ($this->isSectionOutputBlank($contentOut)) {
                return '';
            }

            return $this->parseTags($before, $pageVars, $theme, true)
                . $contentOut
                . $this->parseTags($after, $pageVars, $theme, true);
        }

        $out = $this->parseTags($body, $pageVars, $theme, true);

        return $this->isSectionOutputBlank($out) ? '' : $out;
    }

    private function isSectionOutputBlank(string $html): bool
    {
        $t = trim($html);
        if ($t === '') {
            return true;
        }
        $t = preg_replace('/<!--.*?-->/s', '', $t) ?? $t;

        return trim($t) === '';
    }

    public function applyItemFieldVars(string $tpl, string $itemName, array $row): string
    {
        return $this->applyItemVars($tpl, $itemName, $row);
    }

    /**
     * 插件/列表行循环：field 占位 → parseTags → applyPageVars
     *
     * @param list<array<string, mixed>> $items
     */
    public function renderItemLoop(string $tpl, array $items, array $pageVars, string $fieldName = 'field', array $loopAttrs = []): string
    {
        return app(DocumentListTemplateTagService::class)->renderLoop($loopAttrs, $tpl, $items, $pageVars, $fieldName, false);
    }

private function applyItemVars(string $tpl, string $itemName, array $row): string
    {
        $filters = app(TemplateVarFilterService::class);
        $prefix  = preg_quote($itemName, '/');
        $tpl     = preg_replace_callback(
            '/\{\$' . $prefix . '\.(\w+)\|default(?::([^}|]+))?\}/',
            static function (array $m) use ($row, $filters): string {
                $val      = $row[$m[1]] ?? '';
                $fallback = isset($m[2]) ? trim($m[2], " \t\"'") : '';

                return htmlspecialchars($filters->withDefault($val, $fallback), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $tpl
        ) ?? $tpl;
        $tpl     = preg_replace_callback(
            '/\{\$' . $prefix . '\.(\w+)\|truncate(?::(\d+))?(?::([^}|]*))?\}/',
            static function (array $m) use ($row, $filters): string {
                $val = $row[$m[1]] ?? '';
                $len = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 80;
                $suffix = isset($m[3]) && $m[3] !== '' ? $m[3] : '...';

                return htmlspecialchars($filters->truncate((string) $val, $len, $suffix), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $tpl
        ) ?? $tpl;
        $tpl = preg_replace_callback(
            '/\{\$' . preg_quote($itemName, '/') . '\.(\w+)\|raw\}/',
            static function (array $m) use ($row) {
                $val = $row[$m[1]] ?? '';
                return is_scalar($val) ? (string) $val : '';
            },
            $tpl
        );
        return preg_replace_callback(
            '/\{\$' . preg_quote($itemName, '/') . '\.(\w+)\}/',
            static function (array $m) use ($row) {
                $key = $m[1];
                $val = $row[$key] ?? '';
                // extends = 可写进 <a> 的属性片段（target/rel/aria-current），禁止二次转义
                if (in_array($key, ['content', 'content_html', 'embed_html', 'extends'], true)) {
                    return is_scalar($val) ? (string) $val : '';
                }
                if (is_array($val)) {
                    return htmlspecialchars(json_encode($val, JSON_UNESCAPED_UNICODE));
                }
                return htmlspecialchars((string) $val);
            },
            $tpl
        );
    }

public function resolvePath(array $vars, string $path)
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if (!str_contains($path, '.')) {
            return $vars[$path] ?? null;
        }
        $cur = $vars;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return null;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }

public function isTruthyTemplateVar($val): bool
    {
        if ($val === [] || $val === '' || $val === null || $val === false) {
            return false;
        }
        if ($val === 0 || $val === '0') {
            return false;
        }

        return true;
    }

public function parseAttrs(string $raw): array
    {
        $attrs = [];
        if (preg_match_all('/(\w+)="([^"]*)"/', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $attrs[$match[1]] = $match[2];
            }
        }
        return $attrs;
    }

public function resolveAttrValue(string $value, array $pageVars): string
    {
        if (preg_match('/^\{\$([a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)*)\}$/', $value, $m)) {
            $val = $this->resolvePath($pageVars, $m[1]);
            return is_scalar($val) ? (string) $val : '';
        }
        if (preg_match('/^\$([a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)*)$/', $value, $m)) {
            $val = $this->resolvePath($pageVars, $m[1]);
            return is_scalar($val) ? (string) $val : '';
        }
        if (str_contains($value, '{$')) {
            $resolved = preg_replace_callback(
                '/\{\$([a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)*)\}/',
                function (array $m) use ($pageVars): string {
                    $val = $this->resolvePath($pageVars, $m[1]);
                    return is_scalar($val) ? (string) $val : '';
                },
                $value
            );
            return $resolved ?? $value;
        }

        return $value;
    }

    /**
     * 单标签渲染失败：记日志并降级为空（dev 留 HTML 注释），禁止拖垮整页。
     *
     * @param callable(): string $fn
     */
    private function safeTagRender(string $tagName, callable $fn, ...$args): string
    {
        try {
            return $args === [] ? (string) $fn() : (string) $fn(...$args);
        } catch (\Throwable $e) {
            return $this->tagRenderFailure($tagName, $e);
        }
    }

    /**
     * @param callable(...): string $fn
     */
    private function tagCallback(string $tagName, callable $fn): callable
    {
        return function (...$args) use ($tagName, $fn): string {
            return $this->safeTagRender($tagName, static fn (): string => $fn(...$args));
        };
    }

    private function tagRenderFailure(string $tagName, \Throwable $e): string
    {
        $this->logTagFailure('template_tag_failed', [
            'tag' => $tagName,
            'msg' => $e->getMessage(),
        ]);

        if (app(SiteModeService::class)->isDev()) {
            return '<!-- pv:' . htmlspecialchars($tagName, ENT_QUOTES) . ' error -->';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logTagFailure(string $event, array $context): void
    {
        try {
            OpsLog::businessWarning($event, $context);
        } catch (\Throwable) {
            // 记日志失败不得二次拖垮整页
        }
    }
}
