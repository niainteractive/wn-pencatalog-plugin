<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

/**
 * What a member owns is an instance, not the catalogue's model. The colour they
 * actually have, and a photo of their own pen, belong on the member row; the
 * catalogue keeps the model-level colour term and the stock photo.
 */
class AddVariantFieldsToMemberPens extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('niainteractive_pencatalog_member_pens')) {
            return;
        }

        Schema::table('niainteractive_pencatalog_member_pens', function ($table) {
            if (!Schema::hasColumn('niainteractive_pencatalog_member_pens', 'variant_color')) {
                $table->string('variant_color', 60)->nullable()->after('nib_grind');
            }
            if (!Schema::hasColumn('niainteractive_pencatalog_member_pens', 'photo')) {
                $table->string('photo', 255)->nullable()->after('variant_color');
            }
        });
    }

    public function down()
    {
        // Losing the column would orphan files in storage/app/media/mypens,
        // so a rollback leaves them alone on purpose.
        Schema::table('niainteractive_pencatalog_member_pens', function ($table) {
            if (Schema::hasColumn('niainteractive_pencatalog_member_pens', 'photo')) {
                $table->dropColumn('photo');
            }
            if (Schema::hasColumn('niainteractive_pencatalog_member_pens', 'variant_color')) {
                $table->dropColumn('variant_color');
            }
        });
    }
}
