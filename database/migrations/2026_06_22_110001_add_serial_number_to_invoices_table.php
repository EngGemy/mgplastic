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
        InnoDbMigration::ensureTablesEngine(['invoices']);

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'serial_number')) {
                $table->unsignedBigInteger('serial_number')->nullable()->unique()->after('id');
            }
        });

        $rows = DB::table('invoices')->whereNull('serial_number')->orderBy('id')->get(['id', 'invoice_type', 'created_at']);

        $serial = (int) DB::table('invoices')->max('serial_number');

        foreach ($rows as $row) {
            $serial++;
            $type = $row->invoice_type ?? 'plumber_receipt';
            $prefix = $type === 'wholesale_pos' ? 'MG-J' : 'MG-S';
            $year = $row->created_at ? date('Y', strtotime($row->created_at)) : date('Y');
            $number = sprintf('%s-%s-%06d', $prefix, $year, $serial);

            DB::table('invoices')->where('id', $row->id)->update([
                'serial_number' => $serial,
                'number' => $number,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'serial_number')) {
                $table->dropUnique(['serial_number']);
                $table->dropColumn('serial_number');
            }
        });
    }
};
