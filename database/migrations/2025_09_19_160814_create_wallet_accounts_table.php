<?php
// database/migrations/2025_09_19_000001_create_wallet_accounts_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('wallet_accounts')) {
            Schema::create('wallet_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
                $table->string('currency',3)->default('LYD');
                $table->unsignedBigInteger('balance_cents')->default(0);
                $table->bigInteger('balance_points')->default(0);
                $table->timestamps();

                $table->unique(['owner_id','currency']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_accounts');
    }
};
