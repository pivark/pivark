<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;

use app\common\support\AppTime;

/** 文档列表默认 HTML（模板一行 {pv:documentlist /}） */
class DocumentListTemplateService
{

    /**
     * @deprecated 模板须用 `{pv:list}` 块标签；仅遗留 PHP 调用保留
     * @param array<string, mixed> $vars
     */
    public function renderHtml(array $vars, string $layout = 'bootstrap-card'): string
    {
        $list = $vars['list'] ?? [];
        if (!is_array($list) || $list === []) {
            return $this->emptyHtml();
        }

        return match ($layout) {
            'bootstrap-compact' => $this->renderCompactCards($list),
            'demo-news'         => $this->renderDemoNews($list),
            'demo-media-grid'   => $this->renderDemoMediaGrid($list),
            'demo-download'     => $this->renderDemoDownload($list),
            default             => $this->renderBootstrapCards($list),
        };
    }

    private function emptyHtml(): string
    {
        return '<div class="empty-state"><i class="bi bi-inbox"></i><p>暂无内容</p></div>';
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    private function renderBootstrapCards(array $list): string
    {
        $h   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<div class="articles-grid">';

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row   = $this->mapFieldAliases($row);
            $url   = $h((string) ($row['url'] ?? ''));
            $title = $h((string) ($row['title'] ?? ''));
            $litpic = trim((string) ($row['litpic'] ?? ''));

            $out .= '<article class="article-card fade-in">';
            if ($litpic !== '') {
                $out .= '<div class="article-image"><a href="' . $url . '">';
                $out .= '<img src="' . $h($litpic) . '" alt="' . $title . '" loading="lazy"></a></div>';
            }
            $out .= '<div class="article-body"><div class="article-meta">';
            $out .= '<span class="article-date"><i class="bi bi-calendar"></i> ' . $h((string) ($row['create_date'] ?? '')) . '</span>';
            $attrLabel = trim((string) ($row['attr_label_text'] ?? ''));
            if ($attrLabel !== '') {
                $out .= '<span class="article-tag">' . $h($attrLabel) . '</span>';
            }
            $out .= '<span class="article-views"><i class="bi bi-eye"></i> ' . $h((string) ($row['click'] ?? '0')) . '</span>';
            $out .= '</div>';
            $titleClass = $h((string) ($row['title_class'] ?? ''));
            $out .= '<h2 class="article-title"><a href="' . $url . '" class="' . $titleClass . '">' . $title . '</a></h2>';
            $out .= '<p class="article-excerpt">' . $h((string) ($row['excerpt_short'] ?? '')) . '</p>';
            $out .= '<div class="article-footer"><div class="article-tags">';
            foreach ((array) ($row['tags'] ?? []) as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                $out .= '<a href="' . $h((string) ($tag['url'] ?? '')) . '" class="tag-badge">' . $h((string) ($tag['name'] ?? '')) . '</a>';
            }
            $out .= '</div><a href="' . $url . '" class="read-more">阅读全文 <i class="bi bi-arrow-right"></i></a>';
            $out .= '</div></div></article>';
        }

        return $out . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    private function renderCompactCards(array $list): string
    {
        $h   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<div class="articles-grid">';

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row   = $this->mapFieldAliases($row);
            $url   = $h((string) ($row['url'] ?? ''));
            $title = $h((string) ($row['title'] ?? ''));

            $out .= '<article class="article-card"><div class="article-content">';
            $out .= '<span class="article-meta">' . $h((string) ($row['create_date'] ?? '')) . '</span>';
            $out .= '<h3 class="article-title"><a href="' . $url . '">' . $title . '</a></h3>';
            $out .= '<p class="article-excerpt">' . $h((string) ($row['excerpt_short'] ?? '')) . '</p>';
            $out .= '</div></article>';
        }

        return $out . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    private function renderDemoNews(array $list): string
    {
        $h   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<div class="pv-list-news">';

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row   = $this->mapFieldAliases($row);
            $url   = $h((string) ($row['url'] ?? ''));
            $title = $h((string) ($row['title'] ?? ''));
            $date  = $h($this->publishedDate($row));
            $litpic = trim((string) ($row['litpic'] ?? ''));
            $fallback = trim((string) ($row['thumb_fallback_url'] ?? ''));
            $titleClass = $h((string) ($row['title_class'] ?? ''));
            $attrLabel = trim((string) ($row['attr_label_text'] ?? ''));

            $out .= '<article class="pv-list-news-item fade-in">';
            $out .= '<a class="pv-list-news-thumb" href="' . $url . '">';
            if ($litpic !== '') {
                $onerror = $fallback !== ''
                    ? ' onerror="this.onerror=null;this.src=\'' . $h($fallback) . '\'"'
                    : '';
                $out .= '<img src="' . $h($litpic) . '" alt="' . $title . '" loading="lazy"' . $onerror . '>';
            } else {
                $out .= '<span class="pv-list-news-ph" aria-hidden="true"><i class="bi bi-newspaper"></i></span>';
            }
            $out .= '</a>';
            $out .= '<div class="pv-list-news-body">';
            $out .= '<time datetime="' . $date . '">' . $date . '</time>';
            if ($attrLabel !== '') {
                $out .= ' <span class="badge bg-light text-dark border">' . $h($attrLabel) . '</span>';
            }
            $out .= '<h2><a href="' . $url . '" class="' . $titleClass . '">' . $title . '</a></h2>';
            $out .= '<p class="text-muted mb-0">' . $h((string) ($row['excerpt_short'] ?? '')) . '</p>';
            $out .= '</div></article>';
        }

        return $out . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    private function renderDemoMediaGrid(array $list): string
    {
        $h   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<div class="row g-4 pv-media-grid">';

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row   = $this->mapFieldAliases($row);
            $url   = $h((string) ($row['url'] ?? ''));
            $title = $h((string) ($row['title'] ?? ''));
            $litpic = trim((string) ($row['litpic'] ?? ''));
            $fallback = trim((string) ($row['thumb_fallback_url'] ?? ''));

            $out .= '<div class="col-md-4 col-sm-6"><a class="pv-media-card fade-in" href="' . $url . '">';
            $out .= '<div class="pv-media-card-thumb">';
            if ($litpic !== '') {
                $onerror = $fallback !== ''
                    ? ' onerror="this.onerror=null;this.src=\'' . $h($fallback) . '\'"'
                    : '';
                $out .= '<img src="' . $h($litpic) . '" alt="' . $title . '" loading="lazy"' . $onerror . '>';
            } else {
                $out .= '<span class="pv-list-news-ph" aria-hidden="true"><i class="bi bi-image"></i></span>';
            }
            $out .= '</div><h3>' . $title . '</h3>';
            $out .= '<p>' . $h((string) ($row['excerpt_short'] ?? '')) . '</p></a></div>';
        }

        return $out . '</div>';
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    private function renderDemoDownload(array $list): string
    {
        $h   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<div class="pv-list-download">';

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row   = $this->mapFieldAliases($row);
            $url   = $h((string) ($row['url'] ?? ''));
            $title = $h((string) ($row['title'] ?? ''));
            $meta  = $h((string) ($row['excerpt_short'] ?? ''));
            if ($meta === '') {
                $meta = (int) ($row['click'] ?? 0) . ' 次浏览';
            }

            $out .= '<a class="pv-list-download-item fade-in" href="' . $url . '">';
            $out .= '<span class="pv-list-download-icon" aria-hidden="true"><i class="bi bi-file-earmark-arrow-down"></i></span>';
            $out .= '<span class="pv-list-download-text"><strong>' . $title . '</strong><small>' . $meta . '</small></span>';
            $out .= '<i class="bi bi-chevron-right pv-list-download-go" aria-hidden="true"></i></a>';
        }

        return $out . '</div>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function publishedDate(array $row): string
    {
        if (!empty($row['published_at'])) {
            return AppTime::format('Y-m-d', strtotime((string) $row['published_at']));
        }

        return (string) ($row['create_date'] ?? '');
    }

    /**
     * 遗留 CMS 列表字段别名（{$field.arcurl} 等）
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    /**
     * 循环序号起点（arclist / list 块 / foreach 共用）。
     * 显式 `index` / `autoindex` 优先；否则有 `offset` 时从 offset+1 起算。
     *
     * @param array<string, string> $attrs
     */
    public function resolveLoopStartFromAttrs(array $attrs): int
    {
        if (isset($attrs['index']) && trim((string) $attrs['index']) !== '') {
            return max(1, (int) $attrs['index']);
        }
        if (isset($attrs['autoindex']) && trim((string) $attrs['autoindex']) !== '') {
            return max(1, (int) $attrs['autoindex']);
        }

        return max(1, $this->resolveSliceOffset($attrs) + 1);
    }

    /**
     * @param array<string, string> $attrs
     */
    public function resolveSliceOffset(array $attrs): int
    {
        if (isset($attrs['limit']) && preg_match('/^(\d+)\s*,\s*(\d+)$/', trim((string) $attrs['limit']), $lm)) {
            return max(0, (int) $lm[1]);
        }

        return max(0, (int) ($attrs['offset'] ?? 0));
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, string>     $attrs 可选 `indexpad="2"` → `index_pad` 为 01、02…
     * @return array<string, mixed>
     */
    public function applyLoopIndexFields(array $row, int $position, int $startIndex, array $attrs = []): array
    {
        $row['loop']       = $position;
        $row['index']      = $startIndex + $position;
        $row['autoindex']  = $row['index'];
        $pad               = (int) ($attrs['indexpad'] ?? $attrs['index_pad'] ?? 0);
        if ($pad > 0) {
            $row['index_pad'] = str_pad((string) $row['index'], min($pad, 6), '0', STR_PAD_LEFT);
        }

        return $row;
    }

    public function mapFieldAliases(array $row): array
    {
        if (isset($row['url']) && !isset($row['arcurl'])) {
            $row['arcurl'] = $row['url'];
        }
        if (isset($row['url']) && !isset($row['titleurl'])) {
            $row['titleurl'] = $row['url'];
        }
        if (isset($row['create_date']) && !isset($row['add_time'])) {
            $row['add_time'] = $row['create_date'];
        }
        if (!isset($row['add_time']) && !empty($row['published_at'])) {
            $row['add_time'] = AppTime::format('Y-m-d', strtotime((string) $row['published_at']));
        }
        if (isset($row['excerpt_short']) && !isset($row['seo_description'])) {
            $row['seo_description'] = $row['excerpt_short'];
        }
        if (!isset($row['seo_description']) && isset($row['summary'])) {
            $row['seo_description'] = $row['summary'];
        }
        if (!isset($row['summary']) && isset($row['excerpt_short'])) {
            $row['summary'] = $row['excerpt_short'];
        } elseif (!isset($row['summary']) && isset($row['excerpt'])) {
            $row['summary'] = $row['excerpt'];
        }
        if (isset($row['excerpt']) && !isset($row['info'])) {
            $row['info'] = $row['excerpt'];
        } elseif (isset($row['summary']) && !isset($row['info'])) {
            $row['info'] = $row['summary'];
        }
        if (isset($row['subtitle']) && !isset($row['sub_title'])) {
            $row['sub_title'] = $row['subtitle'];
        }
        if (isset($row['author_name']) && !isset($row['author'])) {
            $row['author'] = $row['author_name'];
        }

        $pub = (string) ($row['published_date'] ?? '');
        if ($pub === '' && !empty($row['published_at'])) {
            $pub = AppTime::format('Y-m-d', strtotime((string) $row['published_at']));
        }
        if ($pub === '' && isset($row['create_date'])) {
            $pub = (string) $row['create_date'];
        }
        if ($pub !== '') {
            $row['published_date'] ??= $pub;
            $row['pubdate'] ??= $pub;
            $row['senddate'] ??= $pub;
            $row['date'] ??= $pub;
            $ts = 0;
            if (!empty($row['published_at'])) {
                $ts = (int) strtotime((string) $row['published_at']);
            } elseif ($pub !== '') {
                $ts = (int) strtotime($pub);
            }
            if ($ts > 0) {
                $row['published_year'] ??= AppTime::format('Y', $ts);
                $row['published_md'] ??= AppTime::format('m-d', $ts);
            }
        }
        if (!empty($row['updated_at']) && !isset($row['update_time'])) {
            $row['update_time'] = AppTime::format('Y-m-d', strtotime((string) $row['updated_at']));
        }

        $tags = $row['tags'] ?? null;
        if (is_array($tags) && $tags !== []) {
            $first = $tags[0];
            if (is_array($first)) {
                if (!isset($row['typename']) && isset($first['name'])) {
                    $row['typename'] = $first['name'];
                }
                if (!isset($row['typeurl']) && isset($first['url'])) {
                    $row['typeurl'] = $first['url'];
                }
            }
        }

        return $row;
    }
}
