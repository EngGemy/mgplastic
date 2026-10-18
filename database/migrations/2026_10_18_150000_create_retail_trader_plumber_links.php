<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retail_trader_plumber')) {
            Schema::create('retail_trader_plumber', function (Blueprint $table) {
                $table->id();
                $table->foreignId('retail_trader_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('plumber_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('linked_at')->useCurrent();
                $table->timestamps();

                $table->unique(['retail_trader_id', 'plumber_id'], 'retail_plumber_unique');
                $table->index('plumber_id');
            });
        }

        // Backfill from legacy single parent_distributor_id on plumbers.
        $links = DB::table('users')
            ->where('role', 'plumber')
            ->whereNotNull('parent_distributor_id')
            ->get(['id', 'parent_distributor_id']);

        foreach ($links as $link) {
            $parentRole = DB::table('users')->where('id', $link->parent_distributor_id)->value('role');
            if ($parentRole !== 'retail_trader') {
                continue;
            }

            $exists = DB::table('retail_trader_plumber')
                ->where('retail_trader_id', $link->parent_distributor_id)
                ->where('plumber_id', $link->id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('retail_trader_plumber')->insert([
                'retail_trader_id' => $link->parent_distributor_id,
                'plumber_id' => $link->id,
                'linked_by' => $link->parent_distributor_id,
                'linked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retail_trader_plumber');
    }
};
