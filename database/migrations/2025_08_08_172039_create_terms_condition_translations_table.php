<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTermsConditionTranslationsTable extends Migration
{
    public function up()
    {
        Schema::create('terms_condition_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terms_condition_id')->constrained('terms_conditions')->onDelete('cascade');
            $table->string('locale');
            $table->string('title');
            $table->text('content');
            $table->unique(['terms_condition_id', 'locale']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('terms_condition_translations');
    }
}
