<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\plugin\package;

use app\common\support\ProjectPaths;

/** plugin.json requires.pivark_core / requires.php 约束解析与安装预检 */
final class PluginCoreVersionRequirementService
{
    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function syntaxErrors(array $manifest): array
    {
        $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : null;
        if ($requires === null || $requires === []) {
            return [];
        }
        if (!is_array($requires)) {
            return ['requires 须为对象'];
        }

        $errors = [];
        foreach (['pivark_core', 'php'] as $key) {
            if (!array_key_exists($key, $requires)) {
                continue;
            }
            $constraint = trim((string) $requires[$key]);
            if ($constraint === '') {
                $errors[] = 'requires.' . $key . ' 不能为空';
                continue;
            }
            if (!$this->isValidConstraintSyntax($constraint)) {
                $errors[] = 'requires.' . $key . ' 约束语法无效：' . $constraint;
            }
        }

        $extensions = $requires['extensions'] ?? null;
        if ($extensions !== null && !is_array($extensions)) {
            $errors[] = 'requires.extensions 须为对象';
        } elseif (is_array($extensions)) {
            foreach ($extensions as $extId => $constraint) {
                $extId = trim((string) $extId);
                $constraint = trim((string) $constraint);
                if ($extId === '' || $constraint === '') {
                    $errors[] = 'requires.extensions 项须为非空键值';
                    continue;
                }
                if (!$this->isValidConstraintSyntax($constraint)) {
                    $errors[] = 'requires.extensions.' . $extId . ' 约束语法无效：' . $constraint;
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param string|null $againstCoreVersion 非空时按该内核版本校验 pivark_core（用于「升到目标版后是否仍兼容」）
     * @return list<string>
     */
    public function runtimeErrors(array $manifest, ?string $againstCoreVersion = null): array
    {
        $errors = $this->syntaxErrors($manifest);
        if ($errors !== []) {
            return $errors;
        }

        $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
        if ($requires === []) {
            return [];
        }

        $errors = array_merge($errors, $this->pivarkCoreRuntimeErrors($manifest, $againstCoreVersion));

        $phpConstraint = trim((string) ($requires['php'] ?? ''));
        if ($phpConstraint !== '' && !$this->satisfies($phpConstraint, PHP_VERSION)) {
            $errors[] = '需要 PHP ' . $phpConstraint . '（当前 ' . PHP_VERSION . '）';
        }

        return $errors;
    }

    /**
     * 仅 pivark_core 运行时错误（整站联检用，不含 PHP / 语法以外的同伴约束）。
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function pivarkCoreRuntimeErrors(array $manifest, ?string $againstCoreVersion = null): array
    {
        $errors = $this->syntaxErrors($manifest);
        if ($errors !== []) {
            return $errors;
        }

        $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];
        $coreConstraint = trim((string) ($requires['pivark_core'] ?? ''));
        if ($coreConstraint === '') {
            return [];
        }

        $coreVersion = trim((string) ($againstCoreVersion ?? ''));
        if ($coreVersion === '') {
            $coreVersion = $this->currentCoreVersion();
        }
        if ($this->satisfies($coreConstraint, $coreVersion)) {
            return [];
        }

        $label = $againstCoreVersion !== null && trim($againstCoreVersion) !== ''
            ? '目标内核 ' . $coreVersion
            : '当前 ' . $coreVersion;

        return ['需要 PivArk 内核 ' . $coreConstraint . '（' . $label . '）'];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function pivarkCoreConstraint(array $manifest): string
    {
        $requires = is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [];

        return trim((string) ($requires['pivark_core'] ?? ''));
    }

    public function currentCoreVersion(): string
    {
        return ProjectPaths::productVersion();
    }

    public function isValidConstraintSyntax(string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return false;
        }
        foreach (preg_split('/\s+/', $constraint) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                return false;
            }
            if (!preg_match('/^(>=|<=|>|<|!=|=)?(\d+(?:\.\d+){0,2}(?:-[a-zA-Z0-9.]+)?)$/', $part)) {
                return false;
            }
        }

        return true;
    }

    public function satisfies(string $constraint, string $version): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return true;
        }
        $version = $this->normalizeVersion($version);
        foreach (preg_split('/\s+/', $constraint) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^(>=|<=|>|<|!=|=)?(\d+(?:\.\d+){0,2}(?:-[a-zA-Z0-9.]+)?)$/', $part, $m)) {
                return false;
            }
            $op     = $m[1] !== '' ? $m[1] : '=';
            $target = $this->normalizeVersion($m[2]);
            if (!$this->compareVersion($version, $target, $op)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if (preg_match('/^(\d+(?:\.\d+){0,2})/', $version, $m)) {
            $parts = explode('.', $m[1]);
            while (count($parts) < 3) {
                $parts[] = '0';
            }

            return implode('.', array_slice($parts, 0, 3));
        }

        return $version;
    }

    private function compareVersion(string $version, string $target, string $op): bool
    {
        return match ($op) {
            '>='    => version_compare($version, $target, '>='),
            '<='    => version_compare($version, $target, '<='),
            '>'     => version_compare($version, $target, '>'),
            '<'     => version_compare($version, $target, '<'),
            '!='    => version_compare($version, $target, '!='),
            '='     => version_compare($version, $target, '=='),
            default => false,
        };
    }
}
