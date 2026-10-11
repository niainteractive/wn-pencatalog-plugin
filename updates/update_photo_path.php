<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

/**
 * 1.0.4
 * Photos are stored as a path inside storage/app/media rather than a Winter file record.
 * The media directory is served directly (verified), so a file dropped in by the photo
 * pickup command is live at /storage/app/media/<path> with no copy step.
 */
class UpdatePhotoPath extends Migration
{
    public function up()
    {
        Schema::table('niainteractive_pencatalog_item_photos', function ($table) {
            $table->string('path', 255)->nullable()->index();
        });
    }

    public function down()
    {
        Schema::table('niainteractive_pencatalog_item_photos', function ($table) {
            $table->dropColumn('path');
        });
    }
}
