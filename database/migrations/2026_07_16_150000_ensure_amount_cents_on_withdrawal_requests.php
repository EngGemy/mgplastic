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

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawal_requests', 'amount_cents')) {
                $table->unsignedBigInteger('amount_cents')->default(0)->after('wallet_account_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('withdrawal_requests')) {
            return;
        }

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            if (Schema::hasColumn('withdrawal_requests', 'amount_cents')) {
                $table->dropColumn('amount_cents');
            }
        });
    }
};
