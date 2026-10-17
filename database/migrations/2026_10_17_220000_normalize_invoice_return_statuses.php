<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_returns')) {
            return;
        }

        // Legacy rows were auto-confirmed; map to approved workflow.
        DB::table('invoice_returns')
            ->where('status', 'confirmed')
            ->update(['status' => 'approved']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_returns')) {
            return;
        }

        DB::table('invoice_returns')
            ->where('status', 'approved')
            ->update(['status' => 'confirmed']);
    }
};
