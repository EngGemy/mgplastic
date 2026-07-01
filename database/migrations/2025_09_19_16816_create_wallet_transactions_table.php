<?php
// database/migrations/2025_09_19_000002_create_wallet_transactions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('wallet_account_id')->constrained('wallet_accounts')->cascadeOnDelete();
                $table->enum('type', ['credit','debit','adjustment'])->default('credit');
                $table->bigInteger('amount_cents')->default(0);   // حركة مالية (لو احتجناها)
                $table->bigInteger('points_delta')->default(0);   // حركة نقاط (+/-)
                $table->string('description')->nullable();
                $table->json('meta')->nullable();

                // علاقة مورف لأي سجل مرتبط (مثال: Invoice)
                $table->nullableMorphs('related');

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();
                $table->index(['wallet_account_id','created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
