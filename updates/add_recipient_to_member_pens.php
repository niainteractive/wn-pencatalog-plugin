<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

/**
 * A gift is a third member list, not a flag on the wish list, so the row needs
 * somewhere to record who the pen is for. `acquired_on` doubles as the date it
 * was given; nothing else changes.
 */
class AddRecipientToMemberPens extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('niainteractive_pencatalog_member_pens')) {
            return;
        }

        if (!Schema::hasColumn('niainteractive_pencatalog_member_pens', 'recipient')) {
            Schema::table('niainteractive_pencatalog_member_pens', function ($table) {
                $table->string('recipient', 120)->nullable()->after('status');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('niainteractive_pencatalog_member_pens', 'recipient')) {
            Schema::table('niainteractive_pencatalog_member_pens', function ($table) {
                $table->dropColumn('recipient');
            });
        }
    }
}
