<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_settings', function (Blueprint $table) {
            $table->string('support_phone')->nullable()->after('contact_whatsapp');
            $table->string('support_whatsapp')->nullable()->after('support_phone');
        });
    }

    public function down(): void
    {
        Schema::table('website_settings', function (Blueprint $table) {
            $table->dropColumn(['support_phone', 'support_whatsapp']);
        });
    }
};
