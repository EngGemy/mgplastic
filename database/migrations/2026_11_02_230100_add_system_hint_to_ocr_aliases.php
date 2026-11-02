<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_aliases', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_aliases', 'system_hint')) {
                $table->string('system_hint', 8)->default('')->after('normalized_text');
            }
            if (! Schema::hasColumn('ocr_aliases', 'customer_user_id')) {
                $table->foreignId('customer_user_id')->nullable()->after('product_id')
                    ->constrained('users')->nullOnDelete();
            }
        });

        // Drop legacy unique(normalized_text) if present.
        $this->dropNormalizedUnique();

        Schema::table('ocr_aliases', function (Blueprint $table) {
            $table->unique(['normalized_text', 'system_hint', 'customer_user_id'], 'ocr_aliases_norm_system_customer_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_aliases', function (Blueprint $table) {
            try {
                $table->dropUnique('ocr_aliases_norm_system_customer_unique');
            } catch (\Throwable) {
            }
        });

        if (Schema::hasColumn('ocr_aliases', 'customer_user_id')) {
            Schema::table('ocr_aliases', function (Blueprint $table) {
                $table->dropConstrainedForeignId('customer_user_id');
            });
        }

        if (Schema::hasColumn('ocr_aliases', 'system_hint')) {
            Schema::table('ocr_aliases', function (Blueprint $table) {
                $table->dropColumn('system_hint');
            });
        }

        Schema::table('ocr_aliases', function (Blueprint $table) {
            $table->unique('normalized_text');
        });
    }

    protected function dropNormalizedUnique(): void
    {
        try {
            Schema::table('ocr_aliases', function (Blueprint $table) {
                $table->dropUnique(['normalized_text']);
            });

            return;
        } catch (\Throwable) {
        }

        // MySQL may name the index differently.
        try {
            DB::statement('ALTER TABLE ocr_aliases DROP INDEX ocr_aliases_normalized_text_unique');
        } catch (\Throwable) {
        }
    }
};
