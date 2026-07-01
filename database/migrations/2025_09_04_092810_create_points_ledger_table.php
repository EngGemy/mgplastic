<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('points_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plumber_id')->constrained('users')->cascadeOnDelete();
            $t->integer('points_delta'); // + award, - consume
            $t->morphs('source');        // source_type, source_id (invoice, conversion, admin_adjustment)
            $t->json('meta')->nullable();
            $t->timestamps();

            $t->index(['plumber_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('points_ledger');
    }
};
