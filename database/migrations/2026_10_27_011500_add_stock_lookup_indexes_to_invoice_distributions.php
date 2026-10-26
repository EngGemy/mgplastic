<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_distributions')) {
            return;
        }

        Schema::table('invoice_distributions', function (Blueprint $table) {
            if (! $this->indexExists('invoice_distributions', 'inv_dist_to_tier_status_idx')) {
                $table->index(['to_user_id', 'tier', 'status'], 'inv_dist_to_tier_status_idx');
            }
            if (! $this->indexExists('invoice_distributions', 'inv_dist_parent_tier_status_idx')) {
                $table->index(['parent_distribution_id', 'tier', 'status'], 'inv_dist_parent_tier_status_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_distributions')) {
            return;
        }

        Schema::table('invoice_distributions', function (Blueprint $table) {
            if ($this->indexExists('invoice_distributions', 'inv_dist_to_tier_status_idx')) {
                $table->dropIndex('inv_dist_to_tier_status_idx');
            }
            if ($this->indexExists('invoice_distributions', 'inv_dist_parent_tier_status_idx')) {
                $table->dropIndex('inv_dist_parent_tier_status_idx');
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
