<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('marsol_otp_request_id')->nullable()->after('otp_code');
            $table->string('marsol_otp_resend_token')->nullable()->after('marsol_otp_request_id');
            $table->timestamp('marsol_otp_expires_at')->nullable()->after('marsol_otp_resend_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'marsol_otp_request_id',
                'marsol_otp_resend_token',
                'marsol_otp_expires_at'
            ]);
        });
    }
};
