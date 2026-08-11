<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\model\FloatContactItem;

use app\common\service\config\ConfigService;
/**
 * 前台 URL 模式与伪静态规则配置
 *
 * 模式：dynamic 动态（/index.php PATHINFO）| rewrite 伪静态（须服务器规则）| static 静态（生成物理 HTML）
 */
class SiteUrlModeService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public const MODE_DYNAMIC = 'dynamic';
    public const MODE_REWRITE = 'rewrite';
    public const MODE_STATIC  = 'static';

    /** 单页 / 频道首页：flat=/{path}.html  dir_index=/{path}/index.html */
    public const CHANNEL_FLAT      = 'flat';
    public const CHANNEL_DIR_INDEX = 'dir_index';

    /** 频道列表分页：query=?page=  path=/{path}/{n}.html  list=/{path}/list_{n}.html */
    public const TAG_PAGE_QUERY = 'query';
    public const TAG_PAGE_PATH  = 'path';
    public const TAG_PAGE_LIST  = 'list';

    /** 文档（无 url_path）：under_documents=/articles/{key}  root=/{key}  tag_dir=/{tag}/{key} */
    public const ARTICLE_UNDER      = 'under_documents';
    public const ARTICLE_ROOT       = 'root';
    public const ARTICLE_TAG_DIR    = 'tag_dir';

    public const SUFFIX_NONE = '';
    public const SUFFIX_HTML = '.html';

    /**
     * @return mixed
     */
    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->configService->get('site_url_mode', self::MODE_DYNAMIC)));
        return in_array($mode, [self::MODE_DYNAMIC, self::MODE_REWRITE, self::MODE_STATIC], true)
            ? $mode
            : self::MODE_DYNAMIC;
    }

    /**
     * @return mixed
     */
    public function isRewrite(): bool
    {
        return $this->mode() === self::MODE_REWRITE;
    }

    /**
     * @return mixed
     */
    public function isStatic(): bool
    {
        return $this->mode() === self::MODE_STATIC;
    }

    /** 伪静态或静态：需加后缀、走规则引擎 */
    public function usesPrettyUrl(): bool
    {
        return $this->isRewrite() || $this->isStatic();
    }

    /**
     * @return mixed
     */
    public function suffix(): string
    {
        if (!$this->usesPrettyUrl()) {
            return self::SUFFIX_NONE;
        }
        $suffix = (string) $this->configService->get('site_url_suffix', self::SUFFIX_HTML);
        return $suffix === self::SUFFIX_HTML ? self::SUFFIX_HTML : self::SUFFIX_NONE;
    }

    /**
     * @return mixed
     */
    public function channelRule(): string
    {
        if (!$this->usesPrettyUrl()) {
            return self::CHANNEL_FLAT;
        }
        $rule = strtolower(trim((string) $this->configService->get('site_url_channel_rule', self::CHANNEL_FLAT)));
        return in_array($rule, [self::CHANNEL_FLAT, self::CHANNEL_DIR_INDEX], true)
            ? $rule
            : self::CHANNEL_FLAT;
    }

    /**
     * @return mixed
     */
    public function tagPageRule(): string
    {
        $rule = strtolower(trim((string) $this->configService->get(
            'site_url_tag_page_rule',
            $this->configService->get('site_list_page_style', self::TAG_PAGE_QUERY)
        )));
        if (!in_array($rule, [self::TAG_PAGE_QUERY, self::TAG_PAGE_PATH, self::TAG_PAGE_LIST], true)) {
            $rule = self::TAG_PAGE_QUERY;
        }
        // 静态须落物理文件：?page= 无法映射 → 强制路径式
        if ($this->isStatic() && $rule === self::TAG_PAGE_QUERY) {
            return self::TAG_PAGE_PATH;
        }

        return $rule;
    }

    /**
     * @return mixed
     */
    public function articleRule(): string
    {
        if (!$this->usesPrettyUrl()) {
            return self::ARTICLE_UNDER;
        }
        $rule = strtolower(trim((string) $this->configService->get('site_url_document_rule', self::ARTICLE_UNDER)));
        return in_array($rule, [self::ARTICLE_UNDER, self::ARTICLE_ROOT, self::ARTICLE_TAG_DIR], true)
            ? $rule
            : self::ARTICLE_UNDER;
    }

    /** @deprecated 使用 tagPageRule() */
    public function listPageStyle(): string
    {
        $rule = $this->tagPageRule();
        return $rule === self::TAG_PAGE_PATH ? 'path' : 'query';
    }

    /**
     * @return mixed
     * @param mixed $mode
     */
    public function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, [self::MODE_DYNAMIC, self::MODE_REWRITE, self::MODE_STATIC], true)
            ? $mode
            : self::MODE_DYNAMIC;
    }

    /**
     * @return mixed
     * @param mixed $rule
     */
    public function normalizeChannelRule(string $rule): string
    {
        $rule = strtolower(trim($rule));
        return in_array($rule, [self::CHANNEL_FLAT, self::CHANNEL_DIR_INDEX], true)
            ? $rule
            : self::CHANNEL_FLAT;
    }

    /**
     * @return mixed
     * @param mixed $rule
     */
    public function normalizeTagPageRule(string $rule, ?string $mode = null): string
    {
        $rule = strtolower(trim($rule));
        if (!in_array($rule, [self::TAG_PAGE_QUERY, self::TAG_PAGE_PATH, self::TAG_PAGE_LIST], true)) {
            $rule = self::TAG_PAGE_QUERY;
        }
        $mode = $mode !== null ? $this->normalizeMode($mode) : $this->mode();
        if ($mode === self::MODE_STATIC && $rule === self::TAG_PAGE_QUERY) {
            return self::TAG_PAGE_PATH;
        }

        return $rule;
    }

    /**
     * @return mixed
     * @param mixed $rule
     */
    public function normalizeArticleRule(string $rule): string
    {
        $rule = strtolower(trim($rule));
        // 历史别名 documents → under_documents（禁双轨并存；仅入口归一）
        if ($rule === 'documents' || $rule === 'under_document') {
            $rule = self::ARTICLE_UNDER;
        }
        return in_array($rule, [self::ARTICLE_UNDER, self::ARTICLE_ROOT, self::ARTICLE_TAG_DIR], true)
            ? $rule
            : self::ARTICLE_UNDER;
    }

    /**
     * @return mixed
     * @param mixed $suffix
     */
    public function normalizeSuffix(string $suffix): string
    {
        return trim($suffix) === self::SUFFIX_HTML ? self::SUFFIX_HTML : self::SUFFIX_NONE;
    }

    /** 入站：始终识别 .html 后缀 */
    public function stripSuffix(string $segment): string
    {
        $segment = trim($segment, '/');
        if (str_ends_with(strtolower($segment), self::SUFFIX_HTML)) {
            return substr($segment, 0, -strlen(self::SUFFIX_HTML));
        }
        $suffix = $this->suffix();
        if ($suffix !== '' && $suffix !== self::SUFFIX_HTML && str_ends_with($segment, $suffix)) {
            return substr($segment, 0, -strlen($suffix));
        }

        return $segment;
    }

    /**
     * @return list<string>
     */
    public function configKeys(): array
    {
        return [
            'site_url_mode',
            'site_url_suffix',
            'site_url_channel_rule',
            'site_url_tag_page_rule',
            'site_url_document_rule',
            'site_list_page_style',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function channelRuleLabels(): array
    {
        return [
            self::CHANNEL_FLAT      => '扁平 /{path}.html',
            self::CHANNEL_DIR_INDEX => '目录 /{path}/index.html',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function tagPageRuleLabels(): array
    {
        return [
            self::TAG_PAGE_QUERY => '参数 ?page=2',
            self::TAG_PAGE_PATH  => '路径 /{path}/2.html',
            self::TAG_PAGE_LIST  => '列表 /{path}/list_2.html',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function articleRuleLabels(): array
    {
        return [
            self::ARTICLE_UNDER   => '目录 /documents/{html_name}.html',
            self::ARTICLE_ROOT    => '根路径 /{html_name}.html',
            self::ARTICLE_TAG_DIR => '频道 /{栏目}/{html_name}.html',
        ];
    }
}
