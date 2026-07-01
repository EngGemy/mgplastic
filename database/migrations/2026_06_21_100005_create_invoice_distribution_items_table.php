<?php

use App\Support\Database\InnoDbMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        InnoDbMigration::ensureTablesEngine([
            'invoice_distributions',
            'invoice_items',
        ]);

        $distributionIdColumn = InnoDbMigration::referenceIdColumnDefinition('invoice_distributions', 'id');
        $invoiceItemIdColumn = InnoDbMigration::referenceIdColumnDefinition('invoice_items', 'id');

        Schema::create('invoice_distribution_items', function (Blueprint $table) use ($distributionIdColumn, $invoiceItemIdColumn) {
            $table->id();

            if ($distributionIdColumn === 'unsignedInteger') {
                $table->unsignedInteger('distribution_id');
            } else {
                $table->unsignedBigInteger('distribution_id');
            }

            if ($invoiceItemIdColumn === 'unsignedInteger') {
                $table->unsignedInteger('invoice_item_id');
            } else {
                $table->unsignedBigInteger('invoice_item_id');
            }

            $table->unsignedInteger('quantity');
            $table->unsignedInteger('points_value');
            $table->timestamps();

            $table->index(['distribution_id']);
            $table->index(['invoice_item_id']);
            $table->unique(['distribution_id', 'invoice_item_id'], 'idi_dist_item_uniq');
        });

        InnoDbMigration::convertTableToInnoDb('invoice_distribution_items');

        Schema::table('invoice_distribution_items', function (Blueprint $table) {
            $table->foreign('distribution_id')
                ->references('id')
                ->on('invoice_distributions')
                ->cascadeOnDelete();

            $table->foreign('invoice_item_id')
                ->references('id')
                ->on('invoice_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_distribution_items');
    }
};
