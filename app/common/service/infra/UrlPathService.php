<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\model\Document;
use app\common\model\SiteNav;
use app\common\model\SitePage;
use app\common\model\Tag;

/** 全站 URL 路径：单页 / 标签 / 文档 / 栏目 统一校验与解析 */
class UrlPathService
{
    /** @var list<string> */
    public const RESERVED = [
        'admin', 'api', 'documents', 'document', 'tags', 'tag', 'page', 'pages',
        'captcha', 'index', 'home', 'install', 'static', 'uploads', 'weapp', 'search', 'inquiry',
    ];

    /**
     * 规范化门牌：小写、去首尾 /；段内仅 a-z0-9-；**保留多级 /**（如 xinwen/guoji）。
     */
    public function normalize(string $path): string
    {
        $path = strtolower(trim(str_replace('\\', '/', $path), '/'));
        if ($path === '') {
            return '';
        }
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            $seg = preg_replace('/[^a-z0-9\-]+/', '-', $seg) ?? '';
            $seg = trim($seg, '-');
            if ($seg !== '') {
                $parts[] = $seg;
            }
        }

        return implode('/', $parts);
    }

    public function isReserved(string $path): bool
    {
        $path = $this->normalize($path);
        if ($path === '') {
            return true;
        }
        if (in_array($path, self::RESERVED, true)) {
            return true;
        }
        $first = explode('/', $path, 2)[0];

        return in_array($first, self::RESERVED, true);
    }

    public function toUrl(string $path): string
    {
        $path = $this->normalize($path);

        return $path === '' ? '/' : '/' . $path;
    }

    /**
     * @return string 空表示可用
     */
    public function validateAvailable(string $path, string $ownerType, int $ownerId = 0): string
    {
        $path = $this->normalize($path);
        if ($path === '') {
            return '访问路径不能为空';
        }
        if ($this->isReserved($path)) {
            return '路径与系统保留地址冲突';
        }

        $pageQ = SitePage::where('path', $path)->where('status', 1);
        if ($ownerType === 'page' && $ownerId > 0) {
            $pageQ->where('id', '<>', $ownerId);
        }
        if ($pageQ->count() > 0) {
            return $ownerType === 'tag'
                ? '路径已被启用中的单页占用（列表池请用主题 Tag，单页仅固定文例外）'
                : '路径已被其他单页占用';
        }

        $tagQ = Tag::where('url_path', $path)->where('status', 1);
        if ($ownerType === 'tag' && $ownerId > 0) {
            $tagQ->where('id', '<>', $ownerId);
        }
        if ($tagQ->count() > 0) {
            return $ownerType === 'page'
                ? '路径已被主题标签占用（公开门牌以 Tag 为准，请改单页路径或改用标签）'
                : '路径已被其他标签占用';
        }

        $navQ = SiteNav::where('url_path', $path)->where('status', 1);
        if ($ownerType === 'nav' && $ownerId > 0) {
            $navQ->where('id', '<>', $ownerId);
        }
        if ($navQ->count() > 0) {
            return '路径已被网站栏目占用，请换一个路径';
        }

        $artQ = Document::where('url_path', $path)->whereNull('deleted_at');
        if ($ownerType === 'document' && $ownerId > 0) {
            $artQ->where('id', '<>', $ownerId);
        }
        if ($artQ->count() > 0) {
            return '路径已被其他文章占用';
        }

        return '';
    }
}
