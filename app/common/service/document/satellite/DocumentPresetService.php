<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\document\satellite;


use app\common\service\config\ConfigService;
/** 文档发布页：作者 / 来源预设（configs 可配） */
class DocumentPresetService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public const CONFIG_DEFAULT_AUTHOR = 'doc_default_author';
    public const CONFIG_AUTHOR_PRESETS = 'doc_author_presets';
    public const CONFIG_DEFAULT_SOURCE = 'doc_default_source';
    public const CONFIG_SOURCE_PRESETS = 'doc_source_presets';

    private const CUSTOM_VALUE = '__custom__';

    /** 多名作者前台展示分隔符 */
    public const AUTHOR_SEPARATOR = '、';

    /** @var list<string> */
    private static array $defaultSourcePresets = [
        '本站原创',
        '网络转载',
        '合作供稿',
    ];

    /**
     * 新建文档时的默认作者（配置优先，否则当前后台账号）
     *
     * @param array<string, mixed> $adminSession admin_user 会话
     */
    public function defaultAuthorName(array $adminSession = []): string
    {
        $fromCfg = trim((string) $this->configService->get(self::CONFIG_DEFAULT_AUTHOR, ''));
        if ($fromCfg !== '') {
            return mb_substr($fromCfg, 0, 50);
        }

        $name = trim((string) ($adminSession['realname'] ?? $adminSession['username'] ?? ''));

        return $name !== '' ? mb_substr($name, 0, 50) : '小编';
    }

    public function defaultSource(): string
    {
        $raw = trim((string) $this->configService->get(self::CONFIG_DEFAULT_SOURCE, '本站原创'));

        return $raw !== '' ? mb_substr($raw, 0, 100) : '本站原创';
    }

    /**
     * @return list<string>
     */
    public function authorPresets(array $adminSession = []): array
    {
        $items = $this->parseLines((string) $this->configService->get(self::CONFIG_AUTHOR_PRESETS, ''));
        $adminName = trim((string) ($adminSession['realname'] ?? $adminSession['username'] ?? ''));
        if ($adminName !== '') {
            array_unshift($items, mb_substr($adminName, 0, 50));
        }

        return $this->uniqueNonEmpty($items);
    }

    /**
     * @return list<string>
     */
    public function sourcePresets(): array
    {
        $raw = (string) $this->configService->get(self::CONFIG_SOURCE_PRESETS, '');
        $items = $raw !== '' ? $this->parseLines($raw) : self::$defaultSourcePresets;

        return $this->uniqueNonEmpty($items);
    }

    /**
     * 下拉选中值：命中预设则返回该文案，否则为自定义
     */
    public function resolveAuthorSelect(string $authorName, array $adminSession = []): string
    {
        $authorName = trim($authorName);
        foreach ($this->authorPresets($adminSession) as $preset) {
            if ($preset === $authorName) {
                return $preset;
            }
        }

        return self::CUSTOM_VALUE;
    }

    public function resolveSourceSelect(string $source): string
    {
        $source = trim($source);
        foreach ($this->sourcePresets() as $preset) {
            if ($preset === $source) {
                return $preset;
            }
        }

        return self::CUSTOM_VALUE;
    }

    public function isCustomSelect(string $selectValue): bool
    {
        return $selectValue === self::CUSTOM_VALUE;
    }

    /**
     * 解析已保存的署名字符串（支持顿号、逗号等分隔的多作者）
     *
     * @return list<string>
     */
    public function parseAuthorNames(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[、,，\/\|]+/u', $raw) ?: [];
        $out   = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = mb_substr($part, 0, 50);
            }
        }

        return $this->uniqueNonEmpty($out);
    }

    /**
     * @param list<string> $names
     */
    public function formatAuthorNames(array $names): string
    {
        $names = $this->uniqueNonEmpty($names);

        return mb_substr(implode(self::AUTHOR_SEPARATOR, $names), 0, 50);
    }

    public function normalizeAuthorName(string $raw): string
    {
        return $this->formatAuthorNames($this->parseAuthorNames($raw));
    }

    /**
     * 作者表单：预设勾选 + 自定义栏文案
     *
     * @return array{checked:list<string>,custom:string,custom_enabled:bool}
     */
    public function authorFormState(string $authorName, array $adminSession = []): array
    {
        $parts   = $this->parseAuthorNames($authorName);
        $presets = $this->authorPresets($adminSession);
        $presetSet = array_flip($presets);
        $checked = [];
        $custom  = [];
        foreach ($parts as $part) {
            if (isset($presetSet[$part])) {
                $checked[] = $part;
            } else {
                $custom[] = $part;
            }
        }

        return [
            'checked'         => $this->uniqueNonEmpty($checked),
            'custom'          => implode(self::AUTHOR_SEPARATOR, $custom),
            'custom_enabled'  => $custom !== [],
        ];
    }

    /**
     * 来源单选：命中预设则返回该值；历史自定义来源原样保留一项
     */
    public function resolveSourceRadio(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return $this->defaultSource();
        }
        foreach ($this->sourcePresets() as $preset) {
            if ($preset === $source) {
                return $preset;
            }
        }

        return mb_substr($source, 0, 100);
    }

    /** 来源不在预设列表内时需额外展示的单选项文案 */
    public function sourceOrphanOption(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return '';
        }
        foreach ($this->sourcePresets() as $preset) {
            if ($preset === $source) {
                return '';
            }
        }

        return mb_substr($source, 0, 100);
    }

    /**
     * @return list<string>
     */
    private function parseLines(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $raw);
        $out   = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function uniqueNonEmpty(array $items): array
    {
        $seen = [];
        $out  = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '' || isset($seen[$item])) {
                continue;
            }
            $seen[$item] = true;
            $out[]       = $item;
        }

        return $out;
    }
}
