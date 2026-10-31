<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive index for pruning / monitoring failed_jobs by failed_at.
 * Low lock risk on typically small table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }

        Schema::table('failed_jobs', function (Blueprint $table) {
            if (! $this->indexExists('failed_jobs', 'failed_jobs_failed_at_index')) {
                $table->index('failed_at', 'failed_jobs_failed_at_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }

        Schema::table('failed_jobs', function (Blueprint $table) {
            if ($this->indexExists('failed_jobs', 'failed_jobs_failed_at_index')) {
                $table->dropIndex('failed_jobs_failed_at_index');
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
