<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // الطول: لو عندك length_m بالفعل سيبه، وإلا أضفه
            if (! Schema::hasColumn('products', 'length_m')) {
                $table->decimal('length_m', 10, 3)->nullable()->after('product_color_id');
            }

            // السُمك (بالملّي)
            if (! Schema::hasColumn('products', 'thickness_mm')) {
                $table->decimal('thickness_mm', 10, 3)->nullable()->after('length_m');
            }

            // الحجم/السعة بالملّي لتر (ml)
            if (! Schema::hasColumn('products', 'volume_ml')) {
                $table->decimal('volume_ml', 12, 3)->nullable()->after('thickness_mm');
            }

            // التصنيف (حر نصّي — بدّل لاحقًا لـ ENUM لو حابب)
            if (! Schema::hasColumn('products', 'classification')) {
                $table->string('classification', 50)->nullable()->index()->after('volume_ml');
            }

            // ملاحظات
            if (! Schema::hasColumn('products', 'notes')) {
                $table->text('notes')->nullable()->after('classification');
            }

            // الصورة الأساسية
            if (! Schema::hasColumn('products', 'main_image')) {
                $table->string('main_image', 191)->nullable()->after('notes');
            }
        });

        // باك-فيلد اختياري: لو عندك عمود image_url هننسخه لـ main_image
        if (Schema::hasColumn('products', 'image_url') && Schema::hasColumn('products', 'main_image')) {
            DB::statement('UPDATE products SET main_image = image_url WHERE main_image IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'main_image')) {
                $table->dropColumn('main_image');
            }
            if (Schema::hasColumn('products', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('products', 'classification')) {
                $table->dropIndex(['classification']);
                $table->dropColumn('classification');
            }
            if (Schema::hasColumn('products', 'volume_ml')) {
                $table->dropColumn('volume_ml');
            }
            if (Schema::hasColumn('products', 'thickness_mm')) {
                $table->dropColumn('thickness_mm');
            }
            // length_m هنسيبه لأنه قديم عندك — لو أضفناه في up() ومحتاج تشيله، فعّل السطر التالي:
            // if (Schema::hasColumn('products', 'length_m')) { $table->dropColumn('length_m'); }
        });
    }
};
