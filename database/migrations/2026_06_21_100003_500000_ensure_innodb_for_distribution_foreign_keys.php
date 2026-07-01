<?php

use App\Support\Database\InnoDbMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        InnoDbMigration::ensureTablesEngine([
            'users',
            'invoices',
            'products',
            'invoice_items',
        ]);

        InnoDbMigration::convertAllMyIsamTablesToInnoDb();
    }

    public function down(): void
    {
        // Keep InnoDB — do not revert to MyISAM.
    }
};
