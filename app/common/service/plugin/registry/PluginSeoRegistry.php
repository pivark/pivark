<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\registry;

/** 门户首页隐藏 SEO 块 · 插件 boot 注册（www 消费；类文件全 lane 同步供 boot reset） */
final class PluginSeoRegistry
{
    /** @var list<array{identifier:string, id:string, title:string, keywords:string, paragraphs:list<string>}> */
    private static array $entries = [];

    public function reset(): void
    {
        self::$entries = [];
    }

    /**
     * @param array{id:string,title:string,keywords:string,paragraphs:list<string>} $entry
     */
    public function register(string $identifier, array $entry): void
    {
        $identifier = strtolower(trim($identifier));
        $id = trim((string) ($entry['id'] ?? ''));
        $title = trim((string) ($entry['title'] ?? ''));
        if ($identifier === '' || $id === '' || $title === '') {
            return;
        }
        $paragraphs = [];
        foreach ($entry['paragraphs'] ?? [] as $para) {
            $para = trim((string) $para);
            if ($para !== '') {
                $paragraphs[] = $para;
            }
        }
        self::$entries[] = [
            'identifier' => $identifier,
            'id'         => $id,
            'title'      => $title,
            'keywords'   => trim((string) ($entry['keywords'] ?? '')),
            'paragraphs' => $paragraphs,
        ];
    }

    /**
     * @return list<array{id:string,title:string,keywords:string,paragraphs:list<string>}>
     */
    public function entries(): array
    {
        $out = [];
        foreach (self::$entries as $row) {
            $out[] = [
                'id'         => (string) $row['id'],
                'title'      => (string) $row['title'],
                'keywords'   => (string) $row['keywords'],
                'paragraphs' => $row['paragraphs'],
            ];
        }

        return $out;
    }

    public function removeForIdentifier(string $identifier): void
    {
        $identifier = strtolower(trim($identifier));
        if ($identifier === '') {
            return;
        }
        self::$entries = array_values(array_filter(
            self::$entries,
            static fn (array $row): bool => ($row['identifier'] ?? '') !== $identifier,
        ));
    }
}
