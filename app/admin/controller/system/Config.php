<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\support\ServiceResult;
use app\common\support\AdminApiResponse;
use app\common\service\config\ConfigService;
use app\common\service\content\EditorSpecialCharsService;
use app\common\service\infra\CacheConfigService;
use app\common\service\site\SiteModeService;
use app\common\support\JsonBody;
use app\common\support\SiteUrl;
use think\facade\Request;

class Config extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly ConfigService $config,
        private readonly SiteModeService $siteMode,
        private readonly CacheConfigService $cacheConfig,
        private readonly EditorSpecialCharsService $specialChars,
    ) {
        parent::__construct($csrf);
    }

    public function index()
    {
        // 直链跳转 Vue 主后台 /system/config
        return redirect(SiteUrl::adminSpa('/system/config'));
    }

    public function save()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }
        try {
            $side = $this->config->save(Request::post());
        } catch (\InvalidArgumentException $e) {
            return AdminApiResponse::fail($e->getMessage());
        }
        $mode = $this->siteMode->current();
        $payload = [
            'site_mode'                   => $mode,
            'site_mode_label'             => $mode === SiteModeService::OPS ? '运营' : '开发',
            'media_rewrite_recommended'   => (int) ($side['media_rewrite_recommended'] ?? 0),
            'media_rewrite_started'       => (int) ($side['media_rewrite_started'] ?? 0),
            'media_rewrite_finished'      => (int) ($side['media_rewrite_finished'] ?? 0),
            'media_rewrite_job_id'        => (string) ($side['media_rewrite_job_id'] ?? ''),
            'sitemap_rebuilt'             => (int) ($side['sitemap_rebuilt'] ?? 0),
            'public_scheme'               => (string) ($side['public_scheme'] ?? ''),
        ];
        $msg = '保存成功';
        if ($payload['media_rewrite_finished'] === 1) {
            $msg .= '。库内站内地址已按当前公网站址/协议重写完成';
        } elseif ($payload['media_rewrite_started'] === 1) {
            $msg .= '。已启动库内站内地址重写（未全部跑完时可到「附件与水印」续跑进度）';
        } elseif ($payload['media_rewrite_recommended'] === 1) {
            $msg .= '。公网站址/协议已变更，自动重写未完成，请到「附件与水印」手动执行「按当前模式重写库内站内地址」';
        }

        return AdminApiResponse::fromResult(ServiceResult::ok($payload, $msg));
    }

    public function testRedisCache()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $overrides = [];
        foreach (['redis_cache_host', 'redis_cache_port', 'redis_cache_password', 'redis_cache_select', 'redis_cache_prefix'] as $key) {
            if (Request::has($key, 'post')) {
                $overrides[$key] = Request::post($key);
            }
        }

        return AdminApiResponse::admin($this->cacheConfig->testRedisConnection($overrides !== [] ? $overrides : null));
    }

    /** 缓存驱动实际状态（配置 / 生效 / Redis 探测）— 供后台状态条 */
    public function cacheStatus()
    {
        return AdminApiResponse::fromResult(ServiceResult::ok($this->cacheConfig->adminStatus()));
    }

    /** 特殊字符开关状态（是否允许表情等四字节字符） */
    public function specialCharsStatus()
    {
        return AdminApiResponse::fromResult(ServiceResult::ok($this->specialChars->status()));
    }

    /**
     * 一键开启：确保内容表 utf8mb4，并写入 editor_special_chars=1。
     * 不再连带改远程图本地化 / 清外链（那是独立开关）。
     */
    public function enableSpecialChars()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $result = $this->specialChars->enable();

        return AdminApiResponse::fromResult(ServiceResult::ok($result, $result['message']));
    }

    /** POST 启动：按当前 media_url_mode 重写库内站内地址（进度任务） */
    public function rewriteMediaUrls()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $result = app(\app\common\service\media\MediaUrlService::class)->startRewriteJob();

        return AdminApiResponse::fromResult($result);
    }

    /** POST 推进一拍 */
    public function rewriteMediaUrlsTick()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId  = trim((string) Request::post('job_id', ''));
        $cursor = (int) Request::post('cursor', -1);
        if ($cursor < 0) {
            return AdminApiResponse::fail('缺少进度游标 cursor');
        }
        $result = app(\app\common\service\media\MediaUrlService::class)->tickRewriteJob($jobId, $cursor);

        return AdminApiResponse::fromResult($result);
    }

    /** GET 查询进度 */
    public function rewriteMediaUrlsJob()
    {
        $jobId  = trim((string) Request::get('job_id', ''));
        $result = app(\app\common\service\media\MediaUrlService::class)->rewriteJobStatus($jobId);

        return AdminApiResponse::fromResult($result);
    }

    /** POST 取消 */
    public function rewriteMediaUrlsCancel()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $jobId  = trim((string) Request::post('job_id', ''));
        $result = app(\app\common\service\media\MediaUrlService::class)->cancelRewriteJob($jobId);

        return AdminApiResponse::fromResult($result);
    }

    /** 关闭特殊字符（保存时剥离四字节字符；不回退库表字符集） */
    public function disableSpecialChars()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }

        $result = $this->specialChars->disable();

        return AdminApiResponse::fromResult(ServiceResult::ok($result, $result['message']));
    }

    public function saveCustomVar()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $data = JsonBody::decode();
        if ($data === null) {
            return AdminApiResponse::fail('请使用 application/json 提交数据');;
        }

        if (empty($data['name']) || empty($data['title']) || empty($data['type'])) {
            return AdminApiResponse::fail('参数不完整');;
        }

        return AdminApiResponse::admin($this->config->saveCustomVar(
            (string) $data['name'],
            (string) $data['title'],
            (string) $data['type'],
            (string) ($data['default_value'] ?? '')
        ));
    }

    public function deleteCustomVar()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');;
        }

        $data = JsonBody::decode();
        if ($data === null || empty($data['name'])) {
            return AdminApiResponse::fail('请使用 application/json 提交有效的变量名');;
        }

        return AdminApiResponse::admin($this->config->deleteCustomVar((string) $data['name']));
    }
}
