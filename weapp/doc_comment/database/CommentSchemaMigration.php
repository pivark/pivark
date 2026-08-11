<?php
declare(strict_types=1);

namespace weapp\doc_comment\database;

use app\common\contract\WeappSchemaMigration;

/** doc_comment schema 占位（SSOT：database/install.sql） */
final class CommentSchemaMigration extends WeappSchemaMigration
{
    public static function identifier(): string
    {
        return 'doc_comment';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function up(): void
    {
    }
}
