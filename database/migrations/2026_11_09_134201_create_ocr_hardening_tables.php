<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 64)->nullable()->index();
            $table->foreignId('batch_id')->nullable()->constrained('ocr_batches')->nullOnDelete();
            $table->foreignId('image_id')->nullable()->constrained('ocr_images')->nullOnDelete();
            $table->string('model', 128)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success')->default(false)->index();
            $table->string('kind', 32)->default('gemini')->index(); // gemini|upload
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('ocr_images', function (Blueprint $table) {
            $table->json('result_json')->nullable()->after('finished_at');
            $table->string('phash', 64)->nullable()->after('sha256')->index();
        });

        Schema::table('ocr_batches', function (Blueprint $table) {
            $table->decimal('receipt_total', 14, 2)->nullable()->after('cart_added_at');
            $table->decimal('computed_total', 14, 2)->nullable()->after('receipt_total');
            $table->json('batch_flags')->nullable()->after('computed_total');
            $table->unsignedBigInteger('duplicate_of_batch_id')->nullable()->after('batch_flags')->index();
            $table->timestamp('last_activity_at')->nullable()->after('duplicate_of_batch_id')->index();
        });

        Schema::table('ocr_aliases', function (Blueprint $table) {
            if (! Schema::hasColumn('ocr_aliases', 'owner_user_id')) {
                $table->foreignId('owner_user_id')->nullable()->after('customer_user_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('ocr_aliases', 'qty_unit')) {
                $table->string('qty_unit', 16)->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('ocr_aliases', 'scope')) {
                $table->string('scope', 16)->default('global')->after('qty_unit')->index(); // user|global
            }
            if (! Schema::hasColumn('ocr_aliases', 'confirming_users')) {
                $table->unsignedSmallInteger('confirming_users')->default(1)->after('hits');
            }
        });

        // Allow per-user aliases without colliding with the old unique (norm+system+customer).
        try {
            Schema::table('ocr_aliases', function (Blueprint $table) {
                $table->dropUnique('ocr_aliases_norm_system_customer_unique');
            });
        } catch (\Throwable) {
            // index name may differ across environments
        }
        Schema::table('ocr_aliases', function (Blueprint $table) {
            $table->unique(
                ['normalized_text', 'system_hint', 'customer_user_id', 'owner_user_id', 'scope'],
                'ocr_aliases_norm_scope_owner_unique'
            );
        });

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'ocr_batch_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('ocr_batch_id')->nullable()->after('delivered_reference')->constrained('ocr_batches')->nullOnDelete();
            });
        }

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'ocr_batch_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('ocr_batch_id')->nullable()->constrained('ocr_batches')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'ocr_batch_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('ocr_batch_id');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'ocr_batch_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('ocr_batch_id');
            });
        }

        try {
            Schema::table('ocr_aliases', function (Blueprint $table) {
                $table->dropUnique('ocr_aliases_norm_scope_owner_unique');
            });
        } catch (\Throwable) {
        }
        Schema::table('ocr_aliases', function (Blueprint $table) {
            try {
                $table->unique(['normalized_text', 'system_hint', 'customer_user_id'], 'ocr_aliases_norm_system_customer_unique');
            } catch (\Throwable) {
            }
            if (Schema::hasColumn('ocr_aliases', 'confirming_users')) {
                $table->dropColumn('confirming_users');
            }
            if (Schema::hasColumn('ocr_aliases', 'scope')) {
                $table->dropColumn('scope');
            }
            if (Schema::hasColumn('ocr_aliases', 'qty_unit')) {
                $table->dropColumn('qty_unit');
            }
            if (Schema::hasColumn('ocr_aliases', 'owner_user_id')) {
                $table->dropConstrainedForeignId('owner_user_id');
            }
        });

        Schema::table('ocr_batches', function (Blueprint $table) {
            $table->dropColumn([
                'receipt_total',
                'computed_total',
                'batch_flags',
                'duplicate_of_batch_id',
                'last_activity_at',
            ]);
        });

        Schema::table('ocr_images', function (Blueprint $table) {
            $table->dropColumn(['result_json', 'phash']);
        });

        Schema::dropIfExists('ocr_usage');
    }
};
