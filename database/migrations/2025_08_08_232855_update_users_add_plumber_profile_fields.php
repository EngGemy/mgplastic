<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('about_me')->nullable()->after('profile_photo');
            $table->string('short_description')->nullable()->after('about_me');
            $table->longText('long_description')->nullable()->after('short_description');
            $table->string('video_url')->nullable()->after('long_description');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['about_me', 'short_description', 'long_description', 'video_url']);
        });
    }

};
