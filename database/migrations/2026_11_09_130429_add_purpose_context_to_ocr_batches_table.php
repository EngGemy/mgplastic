<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_batches', function (Blueprint $table) {
            $table->string('purpose', 32)->default('pos')->after('user_id')->index();
            // null for POS; factory|warehouse|wholesale|shop for supply_order
            $table->string('source', 32)->nullable()->after('purpose')->index();
            // warehouse_id / wholesaler_id / retail_trader_id depending on source
            $table->unsignedBigInteger('supplier_user_id')->nullable()->after('source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ocr_batches', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'source', 'supplier_user_id']);
        });
    }
};
