<?php

use App\Support\Database\InnoDbMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        InnoDbMigration::ensureTablesEngine(['users']);

        $userIdColumn = InnoDbMigration::usersIdColumnDefinition();

        Schema::table('users', function (Blueprint $table) use ($userIdColumn) {
            if (! Schema::hasColumn('users', 'parent_distributor_id')) {
                if ($userIdColumn === 'unsignedInteger') {
                    $table->unsignedInteger('parent_distributor_id')->nullable()->after('role');
                } else {
                    $table->unsignedBigInteger('parent_distributor_id')->nullable()->after('role');
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'parent_distributor_id')) {
                $table->foreign('parent_distributor_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'parent_distributor_id')) {
                $table->dropForeign(['parent_distributor_id']);
                $table->dropColumn('parent_distributor_id');
            }
        });
    }
};
