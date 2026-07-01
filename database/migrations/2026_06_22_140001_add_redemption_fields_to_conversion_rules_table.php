<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('conversion_rules', 'name')) {
                $table->string('name')->nullable()->after('id');
            }
            if (! Schema::hasColumn('conversion_rules', 'starts_at')) {
                $table->timestamp('starts_at')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('conversion_rules', 'ends_at')) {
                $table->timestamp('ends_at')->nullable()->after('starts_at');
            }
            if (! Schema::hasColumn('conversion_rules', 'notify_on_conversion')) {
                $table->boolean('notify_on_conversion')->default(true)->after('ends_at');
            }
            if (! Schema::hasColumn('conversion_rules', 'notification_message_ar')) {
                $table->text('notification_message_ar')->nullable()->after('notify_on_conversion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversion_rules', function (Blueprint $table) {
            foreach (['name', 'starts_at', 'ends_at', 'notify_on_conversion', 'notification_message_ar'] as $col) {
                if (Schema::hasColumn('conversion_rules', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
