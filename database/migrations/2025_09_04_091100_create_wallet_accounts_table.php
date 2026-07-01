<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wallet_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $t->string('currency', 3)->default('SAR');
            $t->bigInteger('balance_cents')->default(0);
            $t->integer('balance_points')->default(0);
            $t->timestamps();

            $t->unique(['owner_id','currency']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('wallet_accounts');
    }
};
