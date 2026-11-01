<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'ocr_status')) {
                $table->string('ocr_status', 20)->default('idle')->after('attachment_path');
            }
            if (! Schema::hasColumn('invoices', 'ocr_draft')) {
                $table->json('ocr_draft')->nullable()->after('ocr_status');
            }
            if (! Schema::hasColumn('invoices', 'ocr_error')) {
                $table->string('ocr_error', 500)->nullable()->after('ocr_draft');
            }
            if (! Schema::hasColumn('invoices', 'ocr_confirmed_at')) {
                $table->timestamp('ocr_confirmed_at')->nullable()->after('ocr_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['ocr_confirmed_at', 'ocr_error', 'ocr_draft', 'ocr_status'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
