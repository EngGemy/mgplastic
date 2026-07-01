<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            // عمود أب اختياري لتفادي أي توقف حالي
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id') // اختياري: ضع العمود بعد id
                ->constrained('product_categories')
                ->nullOnDelete()   // لو اتشال الأب نخلي القيمة NULL
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            // إزالة الـFK ثم العمود
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
