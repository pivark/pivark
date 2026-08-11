<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\auth;



use app\common\service\site\SiteModeService;
use app\common\service\config\ConfigService;
use app\common\exception\CaptchaException;
use app\common\support\CaptchaGate;
use app\common\support\CaptchaImageRenderer;
use app\common\support\ServiceResult;
use think\facade\Config;
use think\facade\Request;
use think\facade\Session;
use think\Response;

/** 多场景图形验证码（GD + Session） */
class CaptchaService
{

    /** @deprecated 兼容旧 Session 键，校验通过后会清除 */
    private const LEGACY_ADMIN_KEY = 'admin_captcha';

    /** @var array<string, array<string, mixed>> */
    private static array $runtimeScenes = [];

    private readonly string $scene;

    public function __construct(
        private readonly CaptchaConfigService $captchaConfig,
        private readonly ConfigService $config,
        private readonly SiteModeService $siteMode,
        string $scene = 'admin',
    ) {
        $this->scene = $this->normalizeScene($scene);
        $this->assertSceneAllowed($this->scene);
    }

    /**
     * @param string $scene 场景标识 admin / home / plugin.*
     * @return self
     */
    public function forScene(string $scene): self
    {
        return new self($this->captchaConfig, $this->config, $this->siteMode, $scene);
    }

    /**
     * 插件 / OA 子模块在启动时登记场景（可选元数据）
     *
     * @param string                                   $scene 场景标识
     * @param array{label?:string,enabled?:bool|null,bind_client_ip?:bool} $meta
     * @return void
     */
    public function registerScene(string $scene, array $meta = []): void
    {
        $scene = $this->normalizeScene($scene);
        $this->assertSceneAllowed($scene, true);
        self::$runtimeScenes[$scene] = array_merge(['label' => $scene], $meta);
    }

    public function getScene(): string
    {
        return $this->scene;
    }

    public function sessionKey(): string
    {
        $prefix = (string) Config::get('captcha.session_prefix', 'pv_captcha.');
        return $prefix . $this->scene;
    }

    /** 统一图片地址，各端登录页引用 */
    public function imageUrl(): string
    {
        return '/captcha/' . rawurlencode($this->scene);
    }

    /** 场景状态 JSON 地址 */
    public function statusUrl(): string
    {
        return '/captcha/' . rawurlencode($this->scene) . '/status';
    }

    public function isEnabled(): bool
    {
        $sceneOverride = $this->captchaConfig->isSceneEnabled($this->scene);
        if ($sceneOverride !== null) {
            return $sceneOverride;
        }

        $cfg = $this->sceneConfig();
        if (array_key_exists('enabled', $cfg) && $cfg['enabled'] !== null) {
            return (bool) $cfg['enabled'];
        }
        $key    = (string) Config::get('captcha.global_enabled_key', 'captcha_on');
        $global = $this->config->get($key, '1');
        return $global !== '0' && $global !== '' && $global !== false;
    }

    /**
     * 生成新码并写入 Session
     *
     * @return string 明文（仅单测或 dev 调试使用，勿向前端输出）
     */
    public function generate(): string
    {
        $code = $this->randomCode();
        Session::set($this->sessionKey(), [
            'code'       => $code,
            'expire_at'  => time() + $this->expireSeconds(),
            'scene'      => $this->scene,
            'attempts'   => 0,
            'created_at' => time(),
            'client_ip'  => (string) Request::ip(),
        ]);
        if ($this->scene === 'admin') {
            Session::delete(self::LEGACY_ADMIN_KEY);
        }
        return $code;
    }

    public function create(): Response
    {
        $ip = (string) Request::ip();
        $rateMsg = CaptchaGate::guardImageFetch($this->scene, $ip);
        if ($rateMsg !== null) {
            return Response::create($rateMsg, 'html', 429)
                ->header(['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $code = $this->generate();

        if (!extension_loaded('gd')) {
            return $this->responseWithoutGd();
        }

        return $this->createImageResponse($code);
    }

    /**
     * @param bool $consumeOnSuccess 校验成功后是否销毁（防重放）
     */
    public function verify(string $input, bool $consumeOnSuccess = true): bool
    {
        $input = trim($input);
        if ($input === '') {
            return false;
        }

        $captcha = $this->readPayload();
        if ($captcha === null) {
            return false;
        }

        if ($this->isAttemptsExceeded($captcha)) {
            $this->clear();
            return false;
        }

        if (time() > (int) ($captcha['expire_at'] ?? 0)) {
            $this->clear();
            return false;
        }

        if (!$this->clientIpMatches($captcha)) {
            $this->clear();
            return false;
        }

        $stored = (string) ($captcha['code'] ?? '');
        if (!$this->codesEqual($stored, $input)) {
            $this->recordFailedAttempt($captcha);
            return false;
        }

        if ($consumeOnSuccess) {
            $this->clear();
        }

        return true;
    }

    /**
     * 登录前校验：未开启验证码时直接通过；失败抛 CaptchaException
     *
     * @throws CaptchaException
     */
    public function assertValid(string $input): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if (trim($input) === '') {
            throw new CaptchaException('请输入验证码', CaptchaException::EMPTY);
        }

        $payload = $this->readPayload();
        if ($payload === null) {
            throw new CaptchaException('验证码错误或已过期', CaptchaException::INVALID);
        }
        if ($this->isAttemptsExceeded($payload)) {
            $this->clear();
            throw new CaptchaException('验证码尝试次数过多，请刷新后重试', CaptchaException::TOO_MANY);
        }
        if (time() > (int) ($payload['expire_at'] ?? 0)) {
            $this->clear();
            throw new CaptchaException('验证码已过期，请刷新后重试', CaptchaException::EXPIRED);
        }
        if (!$this->clientIpMatches($payload)) {
            $this->clear();
            throw new CaptchaException('验证码已失效，请刷新后重试', CaptchaException::INVALID);
        }

        $stored = (string) ($payload['code'] ?? '');
        if (!$this->codesEqual($stored, $input)) {
            $this->recordFailedAttempt($payload);
            $left = $this->readPayload();
            if ($left === null) {
                throw new CaptchaException('验证码尝试次数过多，请刷新后重试', CaptchaException::TOO_MANY);
            }
            throw new CaptchaException('验证码错误', CaptchaException::INVALID);
        }

        $this->clear();
    }

    /**
     * @return ServiceResult|null
     */
    public function guardLogin(string $input): ?ServiceResult
    {
        try {
            $this->assertValid($input);
            return null;
        } catch (CaptchaException $e) {
            return $e->toJson();
        }
    }

    /** 仅开发模式供自动化测试读取 */
    public function peekCodeForDebug(): string
    {
        if (!$this->siteMode->allowsDebugCaptchaPeek()) {
            return '';
        }
        $data = $this->readPayload();
        return (string) ($data['code'] ?? '');
    }

    public function clear(): void
    {
        Session::delete($this->sessionKey());
        if ($this->scene === 'admin') {
            Session::delete(self::LEGACY_ADMIN_KEY);
        }
    }

    /**
     * 恒定时间比较（大小写不敏感）
     *
     * @param string $expected Session 中存储的验证码
     * @param string $input    用户输入
     * @return bool
     */
    public function codesEqual(string $expected, string $input): bool
    {
        $expected = strtoupper(trim($expected));
        $input    = strtoupper(trim($input));
        if ($expected === '' || $input === '') {
            return false;
        }
        if (strlen($expected) !== strlen($input)) {
            return false;
        }
        return hash_equals($expected, $input);
    }

    // —— 内部 ——

    private function readPayload(): ?array
    {
        $data = Session::get($this->sessionKey());
        if (is_array($data) && ($data['code'] ?? '') !== '') {
            return $data;
        }
        if ($this->scene === 'admin') {
            $legacy = Session::get(self::LEGACY_ADMIN_KEY);
            if (is_array($legacy) && ($legacy['code'] ?? '') !== '') {
                return $legacy;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordFailedAttempt(array $payload): void
    {
        $payload['attempts'] = (int) ($payload['attempts'] ?? 0) + 1;
        if ($this->isAttemptsExceeded($payload)) {
            $this->clear();
            return;
        }
        Session::set($this->sessionKey(), $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isAttemptsExceeded(array $payload): bool
    {
        $max = max(1, (int) Config::get('captcha.max_verify_attempts', 5));
        return (int) ($payload['attempts'] ?? 0) >= $max;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function clientIpMatches(array $payload): bool
    {
        if (!$this->bindClientIp()) {
            return true;
        }
        $stored = (string) ($payload['client_ip'] ?? '');
        if ($stored === '') {
            return true;
        }
        return hash_equals($stored, (string) Request::ip());
    }

    private function bindClientIp(): bool
    {
        $cfg = $this->sceneConfig();
        return !empty($cfg['bind_client_ip']);
    }

    private function sceneConfig(): array
    {
        $builtin = Config::get('captcha.scenes.' . $this->scene, []);
        $runtime = self::$runtimeScenes[$this->scene] ?? [];
        return is_array($builtin) ? array_merge($builtin, $runtime) : $runtime;
    }

    private function expireSeconds(): int
    {
        return max(60, (int) Config::get('captcha.expire', 180));
    }

    private function randomCode(): string
    {
        $len  = $this->captchaConfig->codeLength();
        $pool = $this->captchaConfig->charsetPool();
        $max  = strlen($pool) - 1;
        if ($max < 0) {
            return (string) random_int(1000, 9999);
        }
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $pool[random_int(0, $max)];
        }

        return strtoupper($code);
    }

    private function responseWithoutGd(): Response
    {
        if ($this->siteMode->isDev()) {
            $payload = $this->readPayload();
            $hint    = $payload !== null ? (string) ($payload['code'] ?? '') : '';
            return Response::create(
                'GD 未安装（开发模式）验证码: ' . $hint,
                'html',
                503
            )->header(['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return Response::create('验证码服务不可用', 'html', 503)
            ->header(['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function createImageResponse(string $code): Response
    {
        $width  = max(80, (int) Config::get('captcha.width', 132));
        $height = max(30, (int) Config::get('captcha.height', 36));
        $render = Config::get('captcha.render', []);
        $opts   = is_array($render) ? $render : [];
        $opts   = array_merge($opts, $this->captchaConfig->renderOptions());
        $opts['width']  = $width;
        $opts['height'] = $height;

        $data = CaptchaImageRenderer::png($code, $opts);
        if ($data === '') {
            return Response::create('验证码生成失败', 'html', 503)
                ->header(['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return Response::create($data, 'html', 200)->header([
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }

    /**
     * @param string $scene 原始场景名
     * @return string
     */
    public function normalizeScene(string $scene): string
    {
        $scene = strtolower(trim($scene));
        if ($scene === '' || !preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $scene)) {
            throw new \InvalidArgumentException('无效的验证码场景标识');
        }
        return $scene;
    }

    private function assertSceneAllowed(string $scene, bool $registering = false): void
    {
        $scenes = Config::get('captcha.scenes', []);
        if (is_array($scenes) && array_key_exists($scene, $scenes)) {
            return;
        }
        if (isset(self::$runtimeScenes[$scene])) {
            return;
        }
        foreach (Config::get('captcha.scene_patterns', []) as $pattern) {
            if (is_string($pattern) && preg_match($pattern, $scene) === 1) {
                return;
            }
        }
        if ($registering) {
            throw new \InvalidArgumentException("不允许注册验证码场景: {$scene}");
        }
        throw new \InvalidArgumentException("未登记的验证码场景: {$scene}");
    }
}
