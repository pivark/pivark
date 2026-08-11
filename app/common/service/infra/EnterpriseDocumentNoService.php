<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\infra;

use app\common\support\AppTime;
use app\common\support\DbTable;
use app\common\support\ServiceResult;
use think\facade\Db;

/** Enterprise 统一单据编号（ADR-7 · Batch 0） */
final class EnterpriseDocumentNoService
{

    public function next(string $prefix): ServiceResult
    {
        $prefix = strtoupper(trim($prefix));
        if ($prefix === '' || !preg_match('/^[A-Z0-9-]+$/', $prefix)) {
            return ServiceResult::fail('单据前缀无效');
        }
        if (!DbTable::exists('enterprise_doc_sequences')) {
            return ServiceResult::fail('序列表未安装');
        }
        $date = date('Ymd');
        $seq  = $this->allocateSequence($prefix, $date);
        if ($seq < 1) {
            return ServiceResult::fail('序号分配失败');
        }

        return ServiceResult::ok(sprintf('%s-%s-%04d', $prefix, $date, $seq));
    }

    private function allocateSequence(string $prefix, string $date): int
    {
        Db::startTrans();
        try {
            $row = Db::name('enterprise_doc_sequences')
                ->where('prefix', $prefix)
                ->where('seq_date', $date)
                ->lock(true)
                ->find();
            if ($row === null) {
                Db::name('enterprise_doc_sequences')->insert([
                    'prefix'     => $prefix,
                    'seq_date'   => $date,
                    'last_seq'   => 1,
                    'created_at' => AppTime::now(),
                    'updated_at' => AppTime::now(),
                ]);
                Db::commit();

                return 1;
            }
            $next = (int) ($row['last_seq'] ?? 0) + 1;
            Db::name('enterprise_doc_sequences')
                ->where('id', (int) ($row['id'] ?? 0))
                ->update([
                    'last_seq'   => $next,
                    'updated_at' => AppTime::now(),
                ]);
            Db::commit();

            return $next;
        } catch (\Throwable $e) {
            Db::rollback();

            return 0;
        }
    }
}
