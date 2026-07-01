<?php

use App\Support\Database\InnoDbMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        InnoDbMigration::convertAllMyIsamTablesToInnoDb();
    }

    public function down(): void
    {
        // Keep InnoDB — reverting to MyISAM would break foreign keys.
    }
};
