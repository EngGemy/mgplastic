<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'network_code')) {
                $table->string('network_code', 32)->nullable()->unique()->after('id');
            }
        });

        if (! Schema::hasTable('wholesaler_retail_trader')) {
            Schema::create('wholesaler_retail_trader', function (Blueprint $table) {
                $table->id();
                $table->foreignId('wholesaler_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('retail_trader_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('linked_at')->useCurrent();
                $table->timestamps();

                $table->unique(['wholesaler_id', 'retail_trader_id'], 'wholesaler_retail_unique');
                $table->index('retail_trader_id');
            });
        }

        // Backfill network codes for existing network users
        $users = DB::table('users')
            ->whereIn('role', ['wholesale_distributor', 'retail_trader', 'plumber'])
            ->whereNull('network_code')
            ->orderBy('id')
            ->get(['id', 'role']);

        foreach ($users as $user) {
            $prefix = match ($user->role) {
                'wholesale_distributor' => 'MG-W',
                'retail_trader' => 'MG-R',
                'plumber' => 'MG-P',
                default => 'MG-U',
            };

            DB::table('users')->where('id', $user->id)->update([
                'network_code' => sprintf('%s-%06d', $prefix, $user->id),
            ]);
        }

        // Seed pivot from existing parent_distributor_id links
        $links = DB::table('users')
            ->where('role', 'retail_trader')
            ->whereNotNull('parent_distributor_id')
            ->get(['id', 'parent_distributor_id']);

        foreach ($links as $link) {
            $exists = DB::table('wholesaler_retail_trader')
                ->where('wholesaler_id', $link->parent_distributor_id)
                ->where('retail_trader_id', $link->id)
                ->exists();

            if (! $exists) {
                DB::table('wholesaler_retail_trader')->insert([
                    'wholesaler_id' => $link->parent_distributor_id,
                    'retail_trader_id' => $link->id,
                    'linked_by' => $link->parent_distributor_id,
                    'linked_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesaler_retail_trader');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'network_code')) {
                $table->dropUnique(['network_code']);
                $table->dropColumn('network_code');
            }
        });
    }
};
