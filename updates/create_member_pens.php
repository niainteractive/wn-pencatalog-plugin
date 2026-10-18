<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class CreateMemberPens extends Migration
{
    public function up()
    {
        if (Schema::hasTable('niainteractive_pencatalog_member_pens')) {
            return;
        }

        Schema::create('niainteractive_pencatalog_member_pens', function ($table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('user_id')->unsigned()->index();
            $table->integer('item_id')->unsigned()->nullable()->index();
            $table->string('list', 20)->default('wish');
            $table->integer('quantity')->default(1);
            $table->string('status', 20)->default('wanted');
            $table->integer('priority')->nullable();
            $table->date('acquired_on')->nullable();
            $table->decimal('price_paid', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('condition', 20)->nullable();
            $table->string('nib_grind', 120)->nullable();
            $table->string('serial_no', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'item_id', 'list'], 'member_pens_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('niainteractive_pencatalog_member_pens');
    }
}
