<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Marsol OTP integration
            $table->string('otp_request_id')->nullable()->after('otp_code');
            $table->string('otp_resend_token')->nullable()->after('otp_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['otp_request_id', 'otp_resend_token']);
        });
    }
};
