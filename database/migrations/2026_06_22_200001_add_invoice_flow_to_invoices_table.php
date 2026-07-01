<?php

use App\Support\Database\InnoDbMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        InnoDbMigration::ensureTablesEngine([
            'users',
            'invoices',
            'invoice_distributions',
        ]);

        $userIdColumn = InnoDbMigration::usersIdColumnDefinition();
        $invoiceIdColumn = InnoDbMigration::referenceIdColumnDefinition('invoices', 'id');
        $distributionIdColumn = InnoDbMigration::referenceIdColumnDefinition('invoice_distributions', 'id');

        Schema::table('invoices', function (Blueprint $table) use ($userIdColumn, $invoiceIdColumn, $distributionIdColumn) {
            if (! Schema::hasColumn('invoices', 'invoice_flow')) {
                $table->enum('invoice_flow', ['incoming', 'outgoing'])
                    ->default('incoming')
                    ->after('invoice_type');
            }

            if (! Schema::hasColumn('invoices', 'parent_invoice_id')) {
                if ($invoiceIdColumn === 'unsignedInteger') {
                    $table->unsignedInteger('parent_invoice_id')->nullable()->after('invoice_flow');
                } else {
                    $table->unsignedBigInteger('parent_invoice_id')->nullable()->after('invoice_flow');
                }
            }

            if (! Schema::hasColumn('invoices', 'counterparty_user_id')) {
                if ($userIdColumn === 'unsignedInteger') {
                    $table->unsignedInteger('counterparty_user_id')->nullable()->after('parent_invoice_id');
                } else {
                    $table->unsignedBigInteger('counterparty_user_id')->nullable()->after('parent_invoice_id');
                }
            }

            if (! Schema::hasColumn('invoices', 'source_distribution_id')) {
                if ($distributionIdColumn === 'unsignedInteger') {
                    $table->unsignedInteger('source_distribution_id')->nullable()->after('counterparty_user_id');
                } else {
                    $table->unsignedBigInteger('source_distribution_id')->nullable()->after('counterparty_user_id');
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'parent_invoice_id')) {
                $table->foreign('parent_invoice_id')
                    ->references('id')
                    ->on('invoices')
                    ->nullOnDelete();
            }

            if (Schema::hasColumn('invoices', 'counterparty_user_id')) {
                $table->foreign('counterparty_user_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }

            if (Schema::hasColumn('invoices', 'source_distribution_id')) {
                $table->foreign('source_distribution_id')
                    ->references('id')
                    ->on('invoice_distributions')
                    ->nullOnDelete();
            }
        });

        DB::table('invoices')
            ->where('invoice_type', 'wholesale_pos')
            ->whereNull('invoice_flow')
            ->update(['invoice_flow' => 'incoming']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'source_distribution_id')) {
                $table->dropForeign(['source_distribution_id']);
                $table->dropColumn('source_distribution_id');
            }
            if (Schema::hasColumn('invoices', 'counterparty_user_id')) {
                $table->dropForeign(['counterparty_user_id']);
                $table->dropColumn('counterparty_user_id');
            }
            if (Schema::hasColumn('invoices', 'parent_invoice_id')) {
                $table->dropForeign(['parent_invoice_id']);
                $table->dropColumn('parent_invoice_id');
            }
            if (Schema::hasColumn('invoices', 'invoice_flow')) {
                $table->dropColumn('invoice_flow');
            }
        });
    }
};
