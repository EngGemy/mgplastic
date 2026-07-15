<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'show_social_links')) {
                $table->boolean('show_social_links')
                    ->default(true)
                    ->after('is_active');
            }

            if (! Schema::hasColumn('users', 'website')) {
                $table->string('website', 500)
                    ->nullable()
                    ->after('video_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['show_social_links', 'website'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
