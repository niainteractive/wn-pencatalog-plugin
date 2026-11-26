<?php namespace Niainteractive\PenCatalog\Models;

use Model;

/**
 * A member's relationship to a catalog item. The catalog stays the source of
 * truth for what a pen is; this row holds only what the member owns or wants.
 *
 * One row is one physical pen. A member who owns two Jinhao X450s in different
 * colours has two rows: colour, price, photo, serial, nib and recipient all
 * belong to the individual pen, so they cannot share a row. That is why there
 * is no longer a unique key on (user_id, item_id, list), and no quantity
 * column - counting a shelf is counting rows.
 */
class MemberPen extends Model
{
    public $table = 'niainteractive_pencatalog_member_pens';

    /**
     * Date columns. Without this Storm hands back a string, and any code that
     * treats acquired_on as a date - the list formatter does - dies on it.
     */
    protected $dates = ['acquired_on'];

    /**
     * The row fields a member may change. Both save paths - the AJAX handler and
     * the plain /favorites/update route - read this list, so a new field cannot
     * be accepted by one and silently dropped by the other.
     */
    const EDITABLE = [
        'price_paid', 'acquired_on', 'condition',
        'nib_grind', 'variant_color', 'serial_no', 'notes', 'status', 'recipient',
        'pen_make', 'pen_model',
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

    /**
     * Add a pen to a member list.
     *
     * $newCopy = false keeps the old safety: if the pen is already in that list
     * the existing row is returned, so a double-clicked link cannot quietly add
     * a second pen. "Add another copy" passes $newCopy = true and always
     * creates a row, because that is exactly what the member asked for.
     */
    public static function addForUser($userId, $itemId, $list, $make = null, $model = null, $newCopy = false)
    {
        $make = $make !== null ? trim((string) $make) : null;
        $model = $model !== null ? trim((string) $model) : null;

        if ($itemId) {
            if (!$newCopy) {
                $row = self::where('user_id', $userId)->where('item_id', $itemId)->where('list', $list)->first();
                if ($row) {
                    return $row;
                }
            }

            $row = new self();
            $row->user_id = $userId;
            $row->item_id = $itemId;
            $row->list = $list;
            $row->status = self::statusForList($list);
            $row->save();

            return $row;
        }

        /*
         * A pen the catalogue does not list. It belongs to the member only, so
         * no moderation is involved - the row lives on their shelf, sorted and
         * edited like any other, and can be linked to a catalogue entry later.
         */
        if ($model === null || $model === '') {
            return null;
        }

        if (!$newCopy) {
            $row = self::where('user_id', $userId)
                ->where('list', $list)
                ->whereNull('item_id')
                ->where('pen_make', $make)
                ->where('pen_model', $model)
                ->first();

            if ($row) {
                return $row;
            }
        }

        $row = new self();
        $row->user_id = $userId;
        $row->item_id = null;
        $row->pen_make = $make !== '' ? $make : null;
        $row->pen_model = $model;
        $row->list = $list;
        $row->status = self::statusForList($list);
        $row->save();

        return $row;
    }

    protected static function statusForList($list)
    {
        return $list === 'owned' ? 'owned' : ($list === 'gift' ? 'gifted' : 'wanted');
    }

    /**
     * Move a wish-list row into the owned list, keeping what the member entered.
     *
     * It moves rather than merges: a second copy of a pen a member already owns
     * is a separate physical pen, so folding it into the existing row would
     * destroy one colour, price or nib to record the other.
     */
    public static function markBought($userId, $rowId)
    {
        $wish = self::where('user_id', $userId)->where('list', 'wish')->find($rowId);
        if (!$wish) {
            return false;
        }

        $wish->list = 'owned';
        $wish->status = 'owned';
        $wish->save();

        return true;
    }

    /**
     * Move a wish-list row into the gift list. The pen was never ours, so the
     * row keeps its recipient and the date it was given but does not touch the
     * owned list.
     */
    public static function markGifted($userId, $rowId)
    {
        $wish = self::where('user_id', $userId)->where('list', 'wish')->find($rowId);
        if (!$wish) {
            return false;
        }

        $wish->list = 'gift';
        $wish->status = 'gifted';
        $wish->save();

        return true;
    }

    /**
     * Delete one physical pen. This is what the row's Remove control uses.
     */
    public static function removeRow($userId, $rowId, $list)
    {
        $row = self::forUser($userId)->inList($list)->find((int) $rowId);
        if (!$row) {
            return false;
        }

        self::deletePhoto($row);

        /* An ink entry records history: it keeps item_id but must not point at a
         * member row that no longer exists. */
        InkLogEntry::forUser($userId)->where('member_pen_id', $row->id)->update(['member_pen_id' => null]);

        $row->delete();

        return true;
    }

    /**
     * Delete every copy of a catalogue pen from one list. Used by the boolean
     * toggles on a pen's page ("I own this"), where the pen - not a copy - is
     * the subject. The member lists remove one copy at a time via removeRow().
     */
    public static function removeForUser($userId, $itemId, $list)
    {
        $rows = self::where('user_id', $userId)->where('item_id', $itemId)->where('list', $list)->get();
        foreach ($rows as $row) {
            self::deletePhoto($row);
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->id;
        }
        if ($ids) {
            InkLogEntry::forUser($userId)->whereIn('member_pen_id', $ids)->update(['member_pen_id' => null]);
        }

        return self::where('user_id', $userId)->where('item_id', $itemId)->where('list', $list)->delete();
    }

    protected static function deletePhoto($row)
    {
        /* Drop the member's own photo with the row, or the file outlives the record. */
        if ($row->photo && is_file(storage_path('app/'.$row->photo))) {
            @unlink(storage_path('app/'.$row->photo));
        }
    }

    /**
     * Per-pen counts for the catalogue and pen pages: [item_id][list] => number
     * of copies. Truthy where the old boolean was, and it can print "2".
     */
    public static function mapForUser($userId)
    {
        $map = [];
        foreach (self::forUser($userId)->get(['item_id', 'list']) as $row) {
            $itemId = (int) $row->item_id;
            $map[$itemId][$row->list] = ($map[$itemId][$row->list] ?? 0) + 1;
        }
        return $map;
    }
}
