<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use Winter\Storm\Database\Updates\Migration;
use Niainteractive\PenCatalog\Models\TermGroup;
use Niainteractive\PenCatalog\Models\Term;

/**
 * 1.0.3
 * - rating: tinyint 1-5  ->  decimal(3,1) so 1-10 with half points works
 * - inspiration: new free-text column ("Clone/Inspiration" in Max's sheet)
 * - Nib Swappable: new single-select term group (can the nib be replaced, and how)
 */
class UpdateRatingAndFields extends Migration
{
    public function up()
    {
        Schema::table('niainteractive_pencatalog_items', function ($table) {
            $table->decimal('rating', 3, 1)->nullable()->change();
            $table->string('inspiration')->nullable()->after('notes');
        });

        $group = TermGroup::where('slug', 'nib-swappable')->first();
        if (!$group) {
            $group = new TermGroup();
            $group->name = 'Nib Swappable';
            $group->slug = 'nib-swappable';
            $group->description = 'Whether the nib can be replaced, and with what. Use Nib Type for the exact fitting (#6 Bock, proprietary...).';
            $group->is_multi = 0;
            $group->sort_order = 8;
            $group->is_active = 1;
            $group->save();
            echo "  created term group: Nib Swappable\n";
        }

        $terms = [
            ['Standard unit (Bock / Jowo)', 'standard-unit'],
            ['Same-brand unit only',        'same-brand-only'],
            ['Proprietary - no swap',       'proprietary-no-swap'],
            ['Limited aftermarket',         'limited-aftermarket'],
            ['Unknown',                     'unknown'],
        ];

        $i = 0;
        foreach ($terms as $t) {
            $i++;
            if (Term::where('group_id', $group->id)->where('slug', $t[1])->exists()) {
                continue;
            }
            $term = new Term();
            $term->group_id  = $group->id;
            $term->name      = $t[0];
            $term->slug      = $t[1];
            $term->sort_order = $i;
            $term->save();
            echo "  created term: {$t[0]}\n";
        }
    }

    public function down()
    {
        Schema::table('niainteractive_pencatalog_items', function ($table) {
            $table->unsignedTinyInteger('rating')->nullable()->change();
            $table->dropColumn('inspiration');
        });
    }
}
