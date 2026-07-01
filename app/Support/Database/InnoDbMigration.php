<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InnoDbMigration
{
    public static function isMysql(): bool
    {
        return DB::getDriverName() === 'mysql';
    }

    /**
     * Convert specific tables to InnoDB (required before foreign keys).
     *
     * @param  list<string>  $tables
     */
    public static function ensureTablesEngine(array $tables): void
    {
        if (! self::isMysql()) {
            return;
        }

        foreach ($tables as $table) {
            self::convertTableToInnoDb($table);
        }
    }

    public static function convertTableToInnoDb(string $table): void
    {
        if (! self::isMysql() || ! Schema::hasTable($table)) {
            return;
        }

        try {
            DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
        } catch (\Throwable) {
            // Some hosts restrict ENGINE changes.
        }
    }

    /**
     * Convert every MyISAM table in the current database to InnoDB.
     */
    public static function convertAllMyIsamTablesToInnoDb(): void
    {
        if (! self::isMysql()) {
            return;
        }

        $tables = DB::select("
            SELECT TABLE_NAME AS table_name
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND ENGINE = 'MyISAM'
        ");

        foreach ($tables as $row) {
            self::convertTableToInnoDb($row->table_name);
        }
    }

    /**
     * Match a referenced primary/foreign key column width (INT vs BIGINT).
     *
     * @return 'unsignedInteger'|'unsignedBigInteger'
     */
    public static function referenceIdColumnDefinition(string $table, string $column = 'id'): string
    {
        if (! self::isMysql() || ! Schema::hasTable($table)) {
            return 'unsignedBigInteger';
        }

        $row = DB::selectOne('
            SELECT COLUMN_TYPE AS column_type
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ', [$table, $column]);

        if (! $row || ! isset($row->column_type)) {
            return 'unsignedBigInteger';
        }

        $type = strtolower((string) $row->column_type);

        if (str_contains($type, 'bigint')) {
            return 'unsignedBigInteger';
        }

        if (str_contains($type, 'int')) {
            return 'unsignedInteger';
        }

        return 'unsignedBigInteger';
    }

    public static function usersIdColumnDefinition(): string
    {
        return self::referenceIdColumnDefinition('users', 'id');
    }
}
