<?php namespace Niainteractive\PenCatalog\Components;

use Cms\Classes\ComponentBase;
use Niainteractive\PenCatalog\Models\Item;
use Niainteractive\PenCatalog\Models\MemberPen;

class MemberList extends ComponentBase
{
    public function componentDetails()
    {
        return [
            'name'        => 'Member list',
            'description' => 'A signed-in member\'s wish list, gift list or owned pens.',
        ];
    }

    public function defineProperties()
    {
        return [
            'list' => ['title' => 'List', 'default' => 'wish'],
        ];
    }

    protected function list()
    {
        $list = (string) $this->property('list', 'wish');

        return in_array($list, ['owned', 'wish', 'gift'], true) ? $list : 'wish';
    }

    protected function userId()
    {
        $user = \Niainteractive\PenCatalog\Classes\MemberAuth::user();
        return $user ? $user->id : null;
    }

    public function onRun()
    {
        $this->page['listKey'] = $this->list();
        $this->page['csrfToken'] = csrf_token();
        $this->page['isGuest'] = !$this->userId();

        if ($this->page['isGuest']) {
            $this->page['rows'] = [];
            $this->page['catalogOptions'] = [];
            return;
        }

        $rows = [];
        $records = MemberPen::forUser($this->userId())
            ->inList($this->list())
            ->with(['item.brand', 'item.type', 'item.terms.group'])
            ->get()
            // Alphabetical by the label the page shows: brand then model,
            // case-insensitive and natural (M2 before M10). Copies of one pen
            // sit together, ordered by colour then row id, so a shelf with two
            // X450s does not shuffle between page loads. Rows with no
            // catalogue entry sort last, under their own note.
            ->sortBy(function ($row) {
                if (!$row->item) {
                    return mb_strtolower(trim((string) $row->pen_make . ' ' . (string) $row->pen_model));
                }

                $brand = $row->item->brand ? $row->item->brand->name : '';

                return mb_strtolower(trim($brand . ' ' . $row->item->model . ' '
                    . (string) $row->variant_color . ' ' . $row->id));
            }, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        /* How many copies of each pen sit in this list, so a row can say
         * "copy 2 of 2" when the model name alone would be ambiguous. */
        $copyTotals = [];
        foreach ($records as $record) {
            $key = $record->item_id
                ? 'i'.$record->item_id
                : 'p'.mb_strtolower(trim((string) $record->pen_make . ' ' . (string) $record->pen_model));
            $copyTotals[$key] = ($copyTotals[$key] ?? 0) + 1;
        }
        $copySeen = [];

        foreach ($records as $row) {
            $key = $row->item_id
                ? 'i'.$row->item_id
                : 'p'.mb_strtolower(trim((string) $row->pen_make . ' ' . (string) $row->pen_model));
            $copySeen[$key] = ($copySeen[$key] ?? 0) + 1;

            $byGroup = [];
            if ($row->item) {
                foreach ($row->item->terms as $term) {
                    $slugKey = $term->group ? $term->group->slug : 'other';
                    $byGroup[$slugKey][] = $term->name;
                }
            }
            $rows[] = [
                'record'      => $row,
                'id'          => $row->id,
                'item_id'     => $row->item_id,
                'pen_make'    => $row->pen_make,
                'pen_model'   => $row->pen_model,
                'item'        => $row->item,
                'copy_no'     => $copySeen[$key],
                'copy_total'  => $copyTotals[$key],
                'price_paid'  => $row->price_paid,
                'acquired_on' => $row->acquired_on ? \Illuminate\Support\Carbon::parse($row->acquired_on)->format('Y-m-d') : '',
                'nib_grind'   => $row->nib_grind,
                'variant_color' => $row->variant_color,
                'photo'       => $row->photo,
                'updated_ts'  => $row->updated_at ? $row->updated_at->getTimestamp() : time(),
                'recipient'   => $row->recipient,
                'status'      => $row->status,
                'notes'       => $row->notes,
                'rating'      => $row->item ? $row->item->rating : null,
                'nationality' => implode(', ', $byGroup['nationality'] ?? []),
                'nib_type'    => implode(', ', $byGroup['nib-type'] ?? []),
                'ink'         => implode(', ', $byGroup['ink-system'] ?? []),
            ];
        }
        $this->page['rows'] = $rows;

        $this->page['catalogOptions'] = Item::with('brand')
            ->where('is_published', 1)
            ->get()
            ->sortBy(function ($i) { return ($i->brand ? $i->brand->name : '') . ' ' . $i->model; })
            ->map(function ($i) {
                return ['id' => $i->id, 'label' => ($i->brand ? $i->brand->name . ' ' : '') . $i->model];
            })->values();
    }

    public function onAdd()
    {
        if (!$userId = $this->userId()) {
            return ['#memberList' => '<p>Please sign in.</p>'];
        }

        /* copy=1 is the deliberate "add another copy" action. Without it an add
         * stays idempotent, so a repeated click cannot invent a second pen. */
        $copy = (bool) post('copy');
        $itemId = (int) post('item_id');

        if ($itemId) {
            MemberPen::addForUser($userId, $itemId, $this->list(), null, null, $copy);
        } elseif ($model = trim((string) post('pen_model'))) {
            MemberPen::addForUser($userId, null, $this->list(), post('pen_make'), $model, $copy);
        }

        return $this->refresh();
    }

    public function onRemove()
    {
        if (!$userId = $this->userId()) {
            return [];
        }

        /* A row id removes one physical pen. The old item_id form is kept for
         * links that mean "I do not own this pen at all", but the list itself
         * never renders it. */
        if ($rowId = (int) post('row_id')) {
            MemberPen::removeRow($userId, $rowId, $this->list());
        } else {
            MemberPen::removeForUser($userId, (int) post('item_id'), $this->list());
        }

        return $this->refresh();
    }

    public function onSaveRow()
    {
        if (!$userId = $this->userId()) {
            return [];
        }
        $row = MemberPen::forUser($userId)->inList($this->list())->find((int) post('row_id'));
        if (!$row) {
            return [];
        }
        foreach (MemberPen::EDITABLE as $field) {
            if (post($field) !== null) {
                $row->{$field} = post($field) === '' ? null : post($field);
            }
        }
        $row->save();
        \Flash::success('Saved.');
        return $this->refresh();
    }

    protected function refresh()
    {
        $this->onRun();
        return ['#memberList' => $this->renderPartial('@default')];
    }
}
