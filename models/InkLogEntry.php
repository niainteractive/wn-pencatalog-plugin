<?php namespace Niainteractive\PenCatalog\Models;

use Model;

/**
 * One fill of one pen. The row is "current" while cleaned_on is null, so the
 * same table answers both "what is inked right now" and "what have I used".
 *
 * member_pen_id names the physical pen, not the catalogue model: two X450s in
 * different colours are different pens and can hold different inks. item_id is
 * kept alongside so history survives the member deleting the shelf row, and so
 * a fill can still link to the pen's catalogue page.
 */
class InkLogEntry extends Model
{
    public $table = 'niainteractive_pencatalog_ink_log';

    public $belongsTo = [
        'item' => [Item::class, 'key' => 'item_id'],
        'memberPen' => [MemberPen::class, 'key' => 'member_pen_id'],
    ];

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public static function currentForUser($userId)
    {
        return self::forUser($userId)->whereNull('cleaned_on')
            ->with(['item.brand', 'memberPen'])
            ->orderByDesc('filled_on')->get();
    }

    public static function historyForUser($userId, $limit = 40)
    {
        return self::forUser($userId)->whereNotNull('cleaned_on')
            ->with(['item.brand', 'memberPen'])
            ->orderByDesc('cleaned_on')->limit($limit)->get();
    }
}
