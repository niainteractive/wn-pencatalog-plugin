<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateInkLog extends Migration
{
    public function up()
    {
        if (Schema::hasTable('niainteractive_pencatalog_ink_log')) {
            return;
        }

        Schema::create('niainteractive_pencatalog_ink_log', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('user_id')->unsigned()->index();
            $table->integer('item_id')->unsigned()->nullable()->index();
            $table->string('ink_brand', 120)->nullable();
            $table->string('ink_name', 120);
            $table->string('ink_color', 60)->nullable();
            $table->date('filled_on')->nullable();
            $table->date('cleaned_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('niainteractive_pencatalog_ink_log');
    }
}
