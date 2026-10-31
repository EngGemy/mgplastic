<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive index for queue worker polling (queue + reserved_at).
 * Expected lock: short online DDL on MySQL 8 / MariaDB. Run off-peak if jobs backlog is large.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }

        Schema::table('jobs', function (Blueprint $table) {
            if (! $this->indexExists('jobs', 'jobs_queue_reserved_idx')) {
                $table->index(['queue', 'reserved_at'], 'jobs_queue_reserved_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }

        Schema::table('jobs', function (Blueprint $table) {
            if ($this->indexExists('jobs', 'jobs_queue_reserved_idx')) {
                $table->dropIndex('jobs_queue_reserved_idx');
            }
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            return collect(Schema::getIndexes($table))->contains(fn ($idx) => ($idx['name'] ?? '') === $name);
        } catch (\Throwable) {
            return false;
        }
    }
};
