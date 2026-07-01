<?php

use App\Support\Database\InnoDbMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        InnoDbMigration::ensureTablesEngine(['users', 'invoices']);

        $userIdColumn = InnoDbMigration::usersIdColumnDefinition();

        Schema::table('invoices', function (Blueprint $table) use ($userIdColumn) {
            if (! Schema::hasColumn('invoices', 'invoice_type')) {
                $table->enum('invoice_type', ['plumber_receipt', 'wholesale_pos'])
                    ->default('plumber_receipt')
                    ->after('id');
            }

            if (! Schema::hasColumn('invoices', 'wholesale_distributor_id')) {
                if ($userIdColumn === 'unsignedInteger') {
                    $table->unsignedInteger('wholesale_distributor_id')->nullable()->after('vendor_store_id');
                } else {
                    $table->unsignedBigInteger('wholesale_distributor_id')->nullable()->after('vendor_store_id');
                }
            }

            if (! Schema::hasColumn('invoices', 'issued_by')) {
                if ($userIdColumn === 'unsignedInteger') {
                    $table->unsignedInteger('issued_by')->nullable()->after('reviewed_by');
                } else {
                    $table->unsignedBigInteger('issued_by')->nullable()->after('reviewed_by');
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'wholesale_distributor_id')) {
                $table->foreign('wholesale_distributor_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (Schema::hasColumn('invoices', 'issued_by')) {
                $table->foreign('issued_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('plumber_id')->nullable()->change();
            $table->string('attachment_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'issued_by')) {
                $table->dropForeign(['issued_by']);
                $table->dropColumn('issued_by');
            }
            if (Schema::hasColumn('invoices', 'wholesale_distributor_id')) {
                $table->dropForeign(['wholesale_distributor_id']);
                $table->dropColumn('wholesale_distributor_id');
            }
            if (Schema::hasColumn('invoices', 'invoice_type')) {
                $table->dropColumn('invoice_type');
            }
        });
    }
};
