<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class AddPrivatePensToMemberPens extends Migration
{
    public function up()
    {
        Schema::table('niainteractive_pencatalog_member_pens', function ($table) {
            $table->unsignedInteger('item_id')->nullable()->change();
            $table->string('pen_make', 120)->nullable()->after('item_id');
            $table->string('pen_model', 160)->nullable()->after('pen_make');
        });
    }

    public function down()
    {
        Schema::table('niainteractive_pencatalog_member_pens', function ($table) {
            $table->dropColumn(['pen_make', 'pen_model']);
        });
    }
}
