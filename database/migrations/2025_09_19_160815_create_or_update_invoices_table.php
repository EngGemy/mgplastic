<?php
// database/migrations/2025_09_19_000000_create_or_update_invoices_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plumber_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('vendor_store_id')->nullable()->constrained('plumber_stores')->nullOnDelete();

                $table->unsignedBigInteger('subtotal_cents')->default(0);
                $table->unsignedBigInteger('tax_cents')->default(0);
                $table->unsignedBigInteger('total_cents')->default(0);

                $table->string('currency', 3)->default('LYD');
                $table->string('number')->unique();

                $table->string('attachment_path')->nullable();

                // workflow
                $table->enum('status', ['pending_review','approved','rejected'])->default('pending_review');
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

                // ما يضيفه الأدمن
                $table->decimal('profit_percent', 5, 2)->nullable(); // مثال: 1.00 يعني 1%
                $table->unsignedBigInteger('points_awarded')->default(0);

                $table->json('rejection_reason')->nullable();

                $table->timestamps();
                $table->index(['plumber_id','status']);
            });
        } else {
            Schema::table('invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('invoices','profit_percent')) {
                    $table->decimal('profit_percent',5,2)->nullable()->after('reviewed_by');
                }
                if (! Schema::hasColumn('invoices','points_awarded')) {
                    $table->unsignedBigInteger('points_awarded')->default(0)->after('profit_percent');
                }
                if (! Schema::hasColumn('invoices','status')) {
                    $table->enum('status', ['pending_review','approved','rejected'])->default('pending_review')->after('attachment_path');
                }
                if (! Schema::hasColumn('invoices','approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('status');
                }
                if (! Schema::hasColumn('invoices','reviewed_by')) {
                    $table->foreignId('reviewed_by')->nullable()->after('approved_at')
                        ->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('invoices','rejection_reason')) {
                    $table->json('rejection_reason')->nullable()->after('reviewed_by');
                }
                if (! Schema::hasColumn('invoices','currency')) {
                    $table->string('currency',3)->default('LYD')->after('total_cents');
                }
                if (! Schema::hasColumn('invoices','number')) {
                    // هنخليه nullable مؤقتًا لو حتحتاج تملأ قيَم وبعدها تعمل unique بميجريشن لاحق
                    $table->string('number')->nullable()->after('currency');
                }
            });

            // ---- إصلاح وتحويل آمن لأعمدة الأموال الموجودة ----
            // 1) تنظيف القيم (NULL/''/سالب/كسور)
            foreach (['subtotal_cents','tax_cents','total_cents'] as $col) {
                if (! Schema::hasColumn('invoices', $col)) continue;

                DB::statement("UPDATE `invoices` SET `$col` = 0 WHERE `$col` IS NULL");

                try { DB::statement("UPDATE `invoices` SET `$col` = 0 WHERE `$col` = ''"); } catch (\Throwable $e) {}

                // لو كانت DECIMAL/DOUBLE، قرّب للأقرب
                try { DB::statement("UPDATE `invoices` SET `$col` = ROUND(`$col`) WHERE `$col` IS NOT NULL"); } catch (\Throwable $e) {}

                // امنع السالب قبل التحويل لـ unsigned
                DB::statement("UPDATE `invoices` SET `$col` = 0 WHERE `$col` < 0");
            }

            // 2) تحويل مرحلي: أولاً BIGINT signed nullable (لتفادي تحذير الـ unsigned)
            Schema::table('invoices', function (Blueprint $table) {
                foreach (['subtotal_cents','tax_cents','total_cents'] as $col) {
                    if (Schema::hasColumn('invoices', $col)) {
                        $table->bigInteger($col)->nullable()->change();
                    }
                }
            });

            // 3) تأكيد عدم وجود NULL
            DB::statement("UPDATE `invoices` SET `subtotal_cents` = 0 WHERE `subtotal_cents` IS NULL");
            DB::statement("UPDATE `invoices` SET `tax_cents` = 0 WHERE `tax_cents` IS NULL");
            DB::statement("UPDATE `invoices` SET `total_cents` = 0 WHERE `total_cents` IS NULL");

            // 4) التحويل النهائي: UNSIGNED + NOT NULL + DEFAULT 0
            Schema::table('invoices', function (Blueprint $table) {
                foreach (['subtotal_cents','tax_cents','total_cents'] as $col) {
                    if (Schema::hasColumn('invoices', $col)) {
                        $table->unsignedBigInteger($col)->default(0)->nullable(false)->change();
                    }
                }
            });

            // 5) لو أضفنا number كـ nullable فوق، ممكن تملأه الآن ثم تضيف unique في ميجريشن تالي
            // DB::statement("UPDATE `invoices` SET `number` = CONCAT('INV-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(id, 6, '0')) WHERE `number` IS NULL");
            // Schema::table('invoices', function (Blueprint $table) {
            //     $table->unique('number');
            // });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices','profit_percent')) $table->dropColumn('profit_percent');
            if (Schema::hasColumn('invoices','points_awarded')) $table->dropColumn('points_awarded');
            if (Schema::hasColumn('invoices','approved_at')) $table->dropColumn('approved_at');
            if (Schema::hasColumn('invoices','rejection_reason')) $table->dropColumn('rejection_reason');
            if (Schema::hasColumn('invoices','reviewed_by')) $table->dropConstrainedForeignId('reviewed_by');
            // لا نسقط status/currency/number للحفاظ على البيانات التاريخية
        });
    }
};
