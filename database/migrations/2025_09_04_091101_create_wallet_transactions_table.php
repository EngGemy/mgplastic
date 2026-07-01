<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wallet_account_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['credit','debit','conversion','withdrawal','adjustment']);
            $t->bigInteger('amount_cents')->default(0); // + credit, - debit
            $t->integer('points_delta')->default(0);    // + earn, - consume
            $t->string('description')->nullable();
            $t->json('meta')->nullable();
            $t->morphs('related'); // related_type, related_id (invoice, withdrawal, etc.)
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('type');
        });
    }
    public function down(): void {
        Schema::dropIfExists('wallet_transactions');
    }
};
