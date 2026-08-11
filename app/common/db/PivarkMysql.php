<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\db;

use app\common\support\DbAfterCommit;
use think\db\connector\Mysql;

/**
 * 最外层事务 commit/rollback 时驱动 DbAfterCommit flush/discard。
 *
 * @property int $transTimes
 * @property \PDO|null $linkID
 * @method void initConnect(bool $master = true)
 * @method bool supportSavepoint()
 * @method string parseSavepointRollBack(string $name)
 */
class PivarkMysql extends Mysql
{
    public function commit(): void
    {
        $this->initConnect(true);
        $this->transTimes = max(0, $this->transTimes - 1);

        if (0 == $this->transTimes && $this->linkID->inTransaction()) {
            $this->linkID->commit();
            DbAfterCommit::flush();
        }
    }

    public function rollback(): void
    {
        $this->initConnect(true);
        $this->transTimes = max(0, $this->transTimes - 1);

        if ($this->linkID->inTransaction()) {
            if (0 == $this->transTimes) {
                $this->linkID->rollBack();
                DbAfterCommit::discard();
            } elseif ($this->transTimes > 0 && $this->supportSavepoint()) {
                $this->linkID->exec(
                    $this->parseSavepointRollBack('trans' . ($this->transTimes + 1))
                );
            }
        }
    }
}
