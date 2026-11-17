<?php namespace Niainteractive\PenCatalog\Models;

use Model;

/**
 * A member's relationship to a catalog item. The catalog stays the source of
 * truth for what a pen is; this row holds only what the member owns or wants.
 */
class MemberPen extends Model
{
    public $table = 'niainteractive_pencatalog_member_pens';

    /**
     * The row fields a member may change. Both save paths - the AJAX handler and
     * the plain /favorites/update route - read this list, so a new field cannot
     * be accepted by one and silently dropped by the other.
     */
    const EDITABLE = [
        'quantity', 'price_paid', 'acquired_on', 'condition',
        'nib_grind', 'serial_no', 'notes', 'status', 'recipient',
    ];

    public $belongsTo = [
        'item' => [Item::class, 'key' => 'item_id'],
    ];

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeInList($query, $list)
    {
        return $query->where('list', $list);
    }

    public static function addForUser($userId, $itemId, $list)
    {
        $row = self::where('user_id', $userId)->where('item_id', $itemId)->where('list', $list)->first();
        if ($row) {
            return $row;
        }
        $row = new self();
        $row->user_id = $userId;
        $row->item_id = $itemId;
        $row->list = $list;
        $row->status = $list === 'owned' ? 'owned' : ($list === 'gift' ? 'gifted' : 'wanted');
        $row->save();
        return $row;
    }

    /**
     * Move a favorites row into the owned list, keeping what the member entered.
     * If they already own that pen, fold the quantities together instead of
     * creating a second row for the same thing.
     */
    public static function markBought($userId, $rowId)
    {
        $wish = self::where('user_id', $userId)->where('list', 'wish')->find($rowId);
        if (!$wish) {
            return false;
        }

        $owned = $wish->item_id
            ? self::where('user_id', $userId)->where('list', 'owned')->where('item_id', $wish->item_id)->first()
            : null;

        if ($owned) {
            $owned->quantity = max(1, (int) $owned->quantity) + max(1, (int) $wish->quantity);
            foreach (['notes', 'price_paid', 'acquired_on', 'condition', 'nib_grind', 'serial_no'] as $f) {
                if (!$owned->{$f} && $wish->{$f}) {
                    $owned->{$f} = $wish->{$f};
                }
            }
            $owned->save();
            $wish->delete();
        } else {
            $wish->list = 'owned';
            $wish->status = 'owned';
            $wish->save();
        }

        return true;
    }

    /**
     * Move a wish-list row into the gift list. The pen was never ours, so the
     * row keeps its recipient and the date it was given but does not touch the
     * owned list. Two gifts of the same pen fold into one row, like markBought().
     */
    public static function markGifted($userId, $rowId)
    {
        $wish = self::where('user_id', $userId)->where('list', 'wish')->find($rowId);
        if (!$wish) {
            return false;
        }

        $gift = $wish->item_id
            ? self::where('user_id', $userId)->where('list', 'gift')->where('item_id', $wish->item_id)->first()
            : null;

        if ($gift) {
            $gift->quantity = max(1, (int) $gift->quantity) + max(1, (int) $wish->quantity);
            foreach (['notes', 'price_paid', 'acquired_on', 'recipient'] as $f) {
                if (!$gift->{$f} && $wish->{$f}) {
                    $gift->{$f} = $wish->{$f};
                }
            }
            $gift->save();
            $wish->delete();
        } else {
            $wish->list = 'gift';
            $wish->status = 'gifted';
            $wish->save();
        }

        return true;
    }

    public static function removeForUser($userId, $itemId, $list)
    {
        return self::where('user_id', $userId)->where('item_id', $itemId)->where('list', $list)->delete();
    }

    public static function mapForUser($userId)
    {
        $map = [];
        foreach (self::forUser($userId)->get(['item_id', 'list']) as $row) {
            $map[(int) $row->item_id][$row->list] = true;
        }
        return $map;
    }
}
