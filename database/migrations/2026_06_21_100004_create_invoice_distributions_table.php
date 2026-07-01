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

        if (! Schema::hasTable('invoice_distributions')) {
            $userIdColumn = InnoDbMigration::usersIdColumnDefinition();
            $invoiceIdColumn = InnoDbMigration::referenceIdColumnDefinition('invoices', 'id');

            Schema::create('invoice_distributions', function (Blueprint $table) use ($userIdColumn, $invoiceIdColumn) {
                $table->id();

                if ($invoiceIdColumn === 'unsignedInteger') {
                    $table->unsignedInteger('invoice_id');
                } else {
                    $table->unsignedBigInteger('invoice_id');
                }

                if ($userIdColumn === 'unsignedInteger') {
                    $table->unsignedInteger('from_user_id');
                    $table->unsignedInteger('to_user_id');
                } else {
                    $table->unsignedBigInteger('from_user_id');
                    $table->unsignedBigInteger('to_user_id');
                }

                $table->unsignedBigInteger('parent_distribution_id')->nullable();
                $table->tinyInteger('tier')->unsigned();
                $table->enum('status', ['draft', 'confirmed', 'points_awarded'])->default('draft');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('points_awarded_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['invoice_id', 'from_user_id']);
                $table->index(['to_user_id', 'status']);
                $table->index(['parent_distribution_id']);
            });
        }

        InnoDbMigration::convertTableToInnoDb('invoice_distributions');

        Schema::table('invoice_distributions', function (Blueprint $table) {
            if (! InnoDbMigration::hasForeignKey('invoice_distributions', 'invoice_id')) {
                $table->foreign('invoice_id')
                    ->references('id')
                    ->on('invoices')
                    ->cascadeOnDelete();
            }

            if (! InnoDbMigration::hasForeignKey('invoice_distributions', 'from_user_id')) {
                $table->foreign('from_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            }

            if (! InnoDbMigration::hasForeignKey('invoice_distributions', 'to_user_id')) {
                $table->foreign('to_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            }

            if (! InnoDbMigration::hasForeignKey('invoice_distributions', 'parent_distribution_id')) {
                $table->foreign('parent_distribution_id')
                    ->references('id')
                    ->on('invoice_distributions')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_distributions');
    }
};
