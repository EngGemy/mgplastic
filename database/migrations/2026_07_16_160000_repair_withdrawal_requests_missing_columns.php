<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production repair: older withdrawal_requests tables may lack
 * wallet_account_id / amount_cents even when prior migrations are marked ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('withdrawal_requests')) {
            Schema::create('withdrawal_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plumber_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('wallet_account_id')->constrained('wallet_accounts')->cascadeOnDelete();
                $table->unsignedBigInteger('amount_cents')->default(0);
                $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
                $table->enum('method', ['bank_transfer', 'mobile_wallet'])->default('bank_transfer');
                $table->json('details')->nullable();
                $table->json('rejection_reason')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('receipt_number', 100)->nullable();
                $table->string('transfer_number', 100)->nullable();
                $table->timestamps();
                $table->index(['plumber_id', 'status']);
            });

            return;
        }

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawal_requests', 'wallet_account_id')) {
                $table->unsignedBigInteger('wallet_account_id')->nullable()->index();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'amount_cents')) {
                $table->unsignedBigInteger('amount_cents')->default(0);
            }

            if (! Schema::hasColumn('withdrawal_requests', 'plumber_id')) {
                $table->unsignedBigInteger('plumber_id')->nullable()->index();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'receipt_number')) {
                $table->string('receipt_number', 100)->nullable();
            }

            if (! Schema::hasColumn('withdrawal_requests', 'transfer_number')) {
                $table->string('transfer_number', 100)->nullable();
            }
        });

        // Backfill wallet_account_id from plumber wallets (portable across MySQL/SQLite)
        if (
            Schema::hasColumn('withdrawal_requests', 'wallet_account_id')
            && Schema::hasColumn('withdrawal_requests', 'plumber_id')
            && Schema::hasTable('wallet_accounts')
        ) {
            DB::table('withdrawal_requests')
                ->whereNull('wallet_account_id')
                ->whereNotNull('plumber_id')
                ->orderBy('id')
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $row) {
                        $walletId = DB::table('wallet_accounts')
                            ->where('owner_id', $row->plumber_id)
                            ->where('currency', 'LYD')
                            ->value('id');

                        if ($walletId) {
                            DB::table('withdrawal_requests')
                                ->where('id', $row->id)
                                ->update(['wallet_account_id' => $walletId]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Repair migration — keep columns.
    }
};
