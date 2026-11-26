<?php namespace Niainteractive\PenCatalog\Updates;

use Schema;
use DB;
use Winter\Storm\Database\Updates\Migration;

/**
 * One row per physical pen, and an ink entry that names the pen rather than the model.
 *
 * The unique key on (user_id, item_id, list) was the only thing stopping a member
 * recording two of the same pen in one list - which is exactly what buying two
 * X450s to give away requires. Every per-copy attribute (colour, price, photo,
 * serial, nib, recipient) already lived on the row, so the key was the whole
 * problem. It is replaced with a plain lookup index.
 *
 * quantity is retired: with one row per pen it has no meaning, and two counting
 * mechanisms in one table is how a shelf count starts disagreeing with itself.
 * Any existing row claiming more than one is expanded into copies first, so no
 * data is lost when the column goes.
 *
 * ink_log gains member_pen_id, because "which pen has this ink in it" is a
 * question about one pen, not about every X450 in the catalogue.
 */
class MemberCopiesAndInkPen extends Migration
{
    const PENS = 'niainteractive_pencatalog_member_pens';
    const INK  = 'niainteractive_pencatalog_ink_log';

    public function up()
    {
        if (Schema::hasTable(self::PENS)) {
            if ($this->hasIndex(self::PENS, 'member_pens_unique')) {
                Schema::table(self::PENS, function ($table) {
                    $table->dropUnique('member_pens_unique');
                });
            }

            if (!$this->hasIndex(self::PENS, 'member_pens_lookup')) {
                Schema::table(self::PENS, function ($table) {
                    $table->index(['user_id', 'item_id', 'list'], 'member_pens_lookup');
                });
            }

            if (Schema::hasColumn(self::PENS, 'quantity')) {
                foreach (DB::table(self::PENS)->where('quantity', '>', 1)->get() as $row) {
                    $extra = max(0, (int) $row->quantity - 1);

                    for ($i = 0; $i < $extra; $i++) {
                        $copy = (array) $row;
                        unset($copy['id']);
                        DB::table(self::PENS)->insert($copy);
                    }
                }

                Schema::table(self::PENS, function ($table) {
                    $table->dropColumn('quantity');
                });
            }
        }

        if (Schema::hasTable(self::INK) && !Schema::hasColumn(self::INK, 'member_pen_id')) {
            Schema::table(self::INK, function ($table) {
                $table->integer('member_pen_id')->unsigned()->nullable()->index();
            });

            /* Existing entries named a model. Point each at the member's owned row
             * for that pen. At this point the unique key has only just been dropped
             * and no duplicates can exist yet, so the match is unambiguous. */
            DB::statement(
                'UPDATE '.self::INK.' e
                 JOIN '.self::PENS.' m
                   ON m.user_id = e.user_id AND m.item_id = e.item_id AND m.list = "owned"
                 SET e.member_pen_id = m.id
                 WHERE e.member_pen_id IS NULL AND e.item_id IS NOT NULL'
            );
        }
    }

    public function down()
    {
        /*
         * The unique key is deliberately NOT recreated. Once a member holds two
         * copies, restoring it would fail and roll the whole update back, which
         * would leave the site on the new code with the old schema. Rolling back
         * the column changes is as far as this migration goes.
         */
        if (Schema::hasTable(self::INK) && Schema::hasColumn(self::INK, 'member_pen_id')) {
            Schema::table(self::INK, function ($table) {
                $table->dropColumn('member_pen_id');
            });
        }

        if (Schema::hasTable(self::PENS)) {
            if (!Schema::hasColumn(self::PENS, 'quantity')) {
                Schema::table(self::PENS, function ($table) {
                    $table->integer('quantity')->default(1);
                });
            }

            if ($this->hasIndex(self::PENS, 'member_pens_lookup')) {
                Schema::table(self::PENS, function ($table) {
                    $table->dropIndex('member_pens_lookup');
                });
            }
        }
    }

    protected function hasIndex($table, $name)
    {
        return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$name])) > 0;
    }
}
