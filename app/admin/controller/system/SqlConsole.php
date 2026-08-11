<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\admin\controller\system;

use app\common\service\auth\CsrfService;
use app\common\service\infra\AdminSqlConsoleService;
use app\common\support\AdminApiResponse;
use think\facade\Request;

/** 运维工具 · SQL 控制台 */
class SqlConsole extends \app\admin\controller\Base
{
    public function __construct(
        CsrfService $csrf,
        private readonly AdminSqlConsoleService $sqlConsole,
    ) {
        parent::__construct($csrf);
    }

    public function meta()
    {
        return AdminApiResponse::admin(
            \app\common\support\ServiceResult::ok($this->sqlConsole->meta())
        );
    }

    public function execute()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $sql = (string) Request::post('sql', '');
        $allowWrite = (int) Request::post('allow_write', 0) === 1;
        $writeConfirm = (string) Request::post('write_confirm', '');

        return AdminApiResponse::admin(
            $this->sqlConsole->execute($sql, $allowWrite, $writeConfirm)
        );
    }

    public function import()
    {
        if (!Request::isPost()) {
            return AdminApiResponse::fail('请求方式错误');
        }
        $allowWrite = (int) Request::post('allow_write', 0) === 1;
        $writeConfirm = (string) Request::post('write_confirm', '');
        $sql = trim((string) Request::post('sql', ''));
        $file = Request::file('file');
        if ($file !== null) {
            $tmp = method_exists($file, 'getRealPath') ? (string) $file->getRealPath() : '';
            if ($tmp === '' || !is_file($tmp)) {
                return AdminApiResponse::fail('无法读取上传文件');
            }
            $size = (int) filesize($tmp);
            if ($size <= 0) {
                return AdminApiResponse::fail('上传文件为空');
            }
            if ($size > AdminSqlConsoleService::MAX_IMPORT_BYTES) {
                return AdminApiResponse::fail('导入文件过大，请改用「数据备份」恢复或拆分 SQL');
            }
            $sql = (string) file_get_contents($tmp);
        }
        if ($sql === '') {
            return AdminApiResponse::fail('请粘贴 SQL 或上传 .sql 文件');
        }

        return AdminApiResponse::admin(
            $this->sqlConsole->import($sql, $allowWrite, $writeConfirm)
        );
    }
}
