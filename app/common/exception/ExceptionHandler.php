<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare (strict_types = 1);

namespace app\common\exception;

use app\common\enum\ApiErrorCode;
use app\common\service\infra\SentryReportService;
use app\common\support\ApiRequestDetect;
use app\common\support\ApiResponse;
use app\common\support\ApiUserMessage;
use app\common\support\FrontErrorPageResponse;
use think\exception\ClassNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\RouteNotFoundException;
use think\App;
use think\Request;
use think\Response;
use Throwable;

/**
 * 自定义异常处理器
 * 绕过 TP8 有 Bug 的异常模板（PHP 8.3 下 htmlentities(null) 导致空白页）
 * API 路由返回 JSON；其余仍输出 HTML
 */
class ExceptionHandler extends Handle
{
    public function __construct(
        App $app,
        private readonly SentryReportService $sentry,
    ) {
        parent::__construct($app);
    }

    public function report(Throwable $exception): void
    {
        if (!$this->isIgnoreReport($exception)) {
            $this->sentry->captureException($exception);
        }

        parent::report($exception);
    }

    /**
     * @param Request $request
     */
    public function render($request, Throwable $e): Response
    {
        if ($request instanceof Request && ApiRequestDetect::wantsJsonFromRequest($request)) {
            return $this->renderJsonException($e);
        }

        $httpStatus = $this->resolveHttpStatus($e);

        if ($httpStatus === 404) {
            return FrontErrorPageResponse::create(404, $this->notFoundMessage($e));
        }

        return Response::create(
            $this->renderExceptionContent($e),
            'html',
            $httpStatus
        );
    }

    private function resolveHttpStatus(Throwable $e): int
    {
        if ($e instanceof RouteNotFoundException || $e instanceof ClassNotFoundException) {
            return 404;
        }

        return $e instanceof HttpException ? $e->getStatusCode() : 500;
    }

    private function notFoundMessage(Throwable $e): string
    {
        if ($this->app->isDebug() && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        return '页面不存在';
    }

    protected function renderJsonException(Throwable $e): Response
    {
        $httpStatus = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $errCode    = ApiErrorCode::UNKNOWN;

        if ($e instanceof RouteNotFoundException || $e instanceof ClassNotFoundException) {
            $httpStatus = 404;
            $errCode    = ApiErrorCode::NOT_FOUND;
        } elseif ($httpStatus === 404) {
            $errCode = ApiErrorCode::NOT_FOUND;
        }

        if ($httpStatus === 404) {
            $msg = ApiUserMessage::defaultFor(ApiErrorCode::NOT_FOUND);
        } elseif ($this->app->isDebug() && $e->getMessage() !== '') {
            $msg = (string) $e->getMessage();
        } else {
            // 运营态：走 ApiUserMessage SSOT，避免「系统出了点问题」吓退用户
            $msg = ApiUserMessage::defaultFor($errCode);
        }

        return ApiResponse::httpFailCode($httpStatus, $errCode, $msg, null);
    }

    protected function renderExceptionContent(Throwable $exception): string
    {
        // 直接输出错误信息，不经过 think_exception.tpl 模板
        $msg  = $exception->getMessage();
        $file = $exception->getFile();
        $line = $exception->getLine();

        // 开发模式：显示详细错误
        if ($this->app->isDebug()) {
            $trace = $exception->getTraceAsString();
            return sprintf(
                "<h1>%s</h1><p><b>Message:</b> %s</p><p><b>File:</b> %s (%d)</p><pre>%s</pre>",
                get_class($exception),
                htmlspecialchars((string)$msg),
                htmlspecialchars($file),
                $line,
                htmlspecialchars($trace)
            );
        }

        // 运营模式：显示友好提示（与 API UNKNOWN 同文案）
        $msg = htmlspecialchars(ApiUserMessage::defaultFor(ApiErrorCode::UNKNOWN), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<h1>操作未能完成</h1><p>' . $msg . '</p>';
    }
}
