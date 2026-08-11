<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\auth;

use app\common\support\ServiceResult;


use app\common\service\config\ConfigService;
use think\facade\Config;

/** 多场景验证码开关与渲染参数 */
class CaptchaConfigService
{

    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    /** @return list<array{id:string,label:string,group?:string,wired?:bool,hint?:string}> */
    public function scenes(bool $adminForm = false): array
    {
        $builtin = Config::get('captcha.scenes', []);
        $out     = [];
        if (is_array($builtin)) {
            foreach ($builtin as $id => $meta) {
                if (!is_string($id) || $id === '') {
                    continue;
                }
                if (!is_array($meta)) {
                    $meta = [];
                }
                if ($adminForm && ($meta['show_in_admin'] ?? true) === false) {
                    continue;
                }
                $row = [
                    'id'    => $id,
                    'label' => (string) ($meta['label'] ?? $id),
                    'group' => (string) ($meta['group'] ?? 'login'),
                    'wired' => (bool) ($meta['wired'] ?? false),
                ];
                $hint = trim((string) ($meta['hint'] ?? ''));
                if ($hint !== '') {
                    $row['hint'] = $hint;
                }
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @return list<array{key:string,title:string,desc:string,scenes:list<array<string,mixed>>}> */
    public function sceneGroupsForAdmin(): array
    {
        $catalog = [
            'login'  => ['title' => '登录', 'desc' => '后台与前台会员登录'],
            'member' => ['title' => '会员', 'desc' => '注册、找回密码等流程'],
            'form'   => ['title' => '表单', 'desc' => '留言、咨询等公开提交'],
        ];
        $bucket = [];
        foreach ($this->scenes(true) as $scene) {
            $g = (string) ($scene['group'] ?? 'login');
            if (!isset($catalog[$g])) {
                continue;
            }
            $bucket[$g][] = $scene;
        }
        $out = [];
        foreach ($catalog as $key => $meta) {
            if (empty($bucket[$key])) {
                continue;
            }
            $out[] = [
                'key'    => $key,
                'title'  => $meta['title'],
                'desc'   => $meta['desc'],
                'scenes' => $bucket[$key],
            ];
        }

        return $out;
    }

    public function sceneEnabledKey(string $scene): string
    {
        return 'captcha_scene_' . preg_replace('/[^a-z0-9_]/', '', strtolower($scene)) . '_on';
    }

    public function isSceneEnabled(string $scene): ?bool
    {
        $key = $this->sceneEnabledKey($scene);
        $val = $this->configService->get($key, '');
        if ($val === '' || $val === null) {
            return null;
        }

        return (string) $val === '1';
    }

    /** @return list<string> */
    public function renderKeys(): array
    {
        return [
            'captcha_charset_pool',
            'captcha_font_size',
            'captcha_use_curve',
            'captcha_use_noise',
            'captcha_length',
        ];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $out = [];
        foreach ($this->scenes(false) as $scene) {
            $id  = (string) ($scene['id'] ?? '');
            $key = $this->sceneEnabledKey($id);
            $out[$key] = (string) $this->configService->get($key, '');
        }
        foreach ($this->renderKeys() as $key) {
            $out[$key] = (string) $this->configService->get($key, $this->defaultFor($key));
        }
        $out['captcha_on'] = (string) $this->configService->get('captcha_on', '1');

        return $out;
    }

    public function defaultFor(string $key): string
    {
        return match ($key) {
            'captcha_charset_pool' => '2345678abcdefhijkmnpqrstuvwxyz',
            'captcha_font_size'    => '18',
            'captcha_use_curve'    => '1',
            'captcha_use_noise'    => '1',
            'captcha_length'       => '4',
            default                => '',
        };
    }

    /** @return array{width?:int,height?:int,font_size?:int,use_curve?:bool,use_noise?:bool} */
    public function renderOptions(): array
    {
        return [
            'font_size'  => max(12, (int) $this->configService->get('captcha_font_size', '18')),
            'use_curve'  => (string) $this->configService->get('captcha_use_curve', '1') === '1',
            'use_noise'  => (string) $this->configService->get('captcha_use_noise', '1') === '1',
        ];
    }

    public function codeLength(): int
    {
        return max(4, min(8, (int) $this->configService->get('captcha_length', '4')));
    }

    public function charsetPool(): string
    {
        $pool = (string) $this->configService->get('captcha_charset_pool', $this->defaultFor('captcha_charset_pool'));
        $pool = preg_replace('/[^a-zA-Z0-9]/', '', $pool) ?? '';

        return $pool !== '' ? $pool : '23456789abcdefghjkmnpqrstuvwxyz';
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        foreach ($this->scenes(false) as $scene) {
            $id  = (string) ($scene['id'] ?? '');
            $key = $this->sceneEnabledKey($id);
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $raw = $data[$key];
            if ($raw === '' || $raw === null || $raw === 'inherit') {
                $this->configService->set($key, '');
            } else {
                $this->configService->set($key, !empty($raw) ? '1' : '0');
            }
        }

        if (array_key_exists('captcha_on', $data)) {
            $this->configService->set('captcha_on', !empty($data['captcha_on']) ? '1' : '0');
        }

        $pool = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($data['captcha_charset_pool'] ?? '')) ?? '';
        if ($pool === '') {
            $pool = $this->defaultFor('captcha_charset_pool');
        }

        $payload = [
            'captcha_charset_pool' => $pool,
            'captcha_font_size'    => (string) max(12, min(48, (int) ($data['captcha_font_size'] ?? 18))),
            'captcha_use_curve'    => !empty($data['captcha_use_curve']) ? '1' : '0',
            'captcha_use_noise'    => !empty($data['captcha_use_noise']) ? '1' : '0',
            'captcha_length'       => (string) max(4, min(8, (int) ($data['captcha_length'] ?? 4))),
        ];
        foreach ($payload as $key => $value) {
            $this->configService->set($key, $value);
        }
        $this->configService->forgetRequestCache();

        return ServiceResult::ok(null, '验证码配置已保存');
    }
}
