<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;

/**
 * 1.0.5
 * - videos: free-text box holding embed code for one or more video reviews, printed at the
 *   bottom of the pen's page. Sanitised on output by Classes\VideoEmbeds.
 */
class UpdateVideosField extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('niainteractive_pencatalog_items', 'videos')) {
            return;
        }

        Schema::table('niainteractive_pencatalog_items', function ($table) {
            $table->text('videos')->nullable()->after('inspiration');
        });
    }

    public function down()
    {
        if (Schema::hasColumn('niainteractive_pencatalog_items', 'videos')) {
            Schema::table('niainteractive_pencatalog_items', function ($table) {
                $table->dropColumn('videos');
            });
        }
    }
}
