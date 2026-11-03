<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ota_updates')) {
            return;
        }

        Schema::create('ota_updates', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16); // ios|android
            $table->string('native_build', 64);
            $table->unsignedInteger('version');
            $table->string('file_path');
            $table->text('release_note')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'native_build', 'version'], 'ota_updates_platform_build_version_unique');
            $table->index(['platform', 'native_build', 'is_active', 'version'], 'ota_updates_check_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ota_updates');
    }
};
