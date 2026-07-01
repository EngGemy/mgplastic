<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawal_requests', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'paid_at')) {
                $table->timestamp('paid_at')->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'receipt_number')) {
                $table->string('receipt_number', 100)->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'transfer_number')) {
                $table->string('transfer_number', 100)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('withdrawal_requests', 'receipt_number') ? 'receipt_number' : null,
                Schema::hasColumn('withdrawal_requests', 'transfer_number') ? 'transfer_number' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn(array_values($columns));
            }
        });
    }
};
