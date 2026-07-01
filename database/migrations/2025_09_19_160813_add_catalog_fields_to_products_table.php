<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // صورة الكتالوج (ملف واحد صورة)
            $table->string('catalog_image_path')->nullable()->after('main_image');
            $table->string('catalog_image_mime', 100)->nullable()->after('catalog_image_path');
            $table->unsignedBigInteger('catalog_image_size')->nullable()->after('catalog_image_mime');

            // مرفق الكتالوج (PDF واحد قابل للتنزيل)
            $table->string('catalog_pdf_path')->nullable()->after('catalog_image_size');
            $table->string('catalog_pdf_mime', 100)->nullable()->after('catalog_pdf_path');
            $table->unsignedBigInteger('catalog_pdf_size')->nullable()->after('catalog_pdf_mime');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'catalog_image_path',
                'catalog_image_mime',
                'catalog_image_size',
                'catalog_pdf_path',
                'catalog_pdf_mime',
                'catalog_pdf_size',
            ]);
        });
    }
};
