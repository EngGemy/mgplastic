<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('withdrawal_requests')) {
            return;
        }

        if (Schema::hasColumn('withdrawal_requests', 'amount_cents')) {
            return;
        }

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            // بدون after() حتى لا تفشل على مخطط إنتاج مختلف
            $table->unsignedBigInteger('amount_cents')->default(0);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('withdrawal_requests')) {
            return;
        }

        if (! Schema::hasColumn('withdrawal_requests', 'amount_cents')) {
            return;
        }

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('amount_cents');
        });
    }
};
