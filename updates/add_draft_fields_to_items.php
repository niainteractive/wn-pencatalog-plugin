<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

class AddDraftFieldsToItems extends Migration
{
    public function up()
    {
        Schema::table('niainteractive_pencatalog_items', function ($table) {
            $table->string('source', 40)->nullable();
            $table->string('created_by', 120)->nullable();
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('niainteractive_pencatalog_items', function ($table) {
            $table->dropColumn(['source', 'created_by', 'verified_at']);
        });
    }
}
