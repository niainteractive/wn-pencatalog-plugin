<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateSuggestionsTable extends Migration
{
    public function up()
    {
        Schema::create('niainteractive_pencatalog_suggestions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('make', 120)->nullable();
            $table->string('model', 160);
            $table->string('url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('new');
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index(['make', 'model']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('niainteractive_pencatalog_suggestions');
    }
}
