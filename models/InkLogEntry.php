<?php namespace Niainteractive\PenCatalog\Models;

use Model;

/**
 * One fill of one pen. The row is "current" while cleaned_on is null, so the
 * same table answers both "what is inked right now" and "what have I used".
 */
class InkLogEntry extends Model
{
    public $table = 'niainteractive_pencatalog_ink_log';

    public $belongsTo = [
        'item' => [Item::class, 'key' => 'item_id'],
    ];

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public static function currentForUser($userId)
    {
        return self::forUser($userId)->whereNull('cleaned_on')->with('item.brand')->orderByDesc('filled_on')->get();
    }

    public static function historyForUser($userId, $limit = 40)
    {
        return self::forUser($userId)->whereNotNull('cleaned_on')->with('item.brand')
            ->orderByDesc('cleaned_on')->limit($limit)->get();
    }
}
