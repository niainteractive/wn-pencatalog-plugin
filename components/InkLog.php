<?php namespace Niainteractive\PenCatalog\Components;

use Cms\Classes\ComponentBase;
use Niainteractive\PenCatalog\Models\InkLogEntry;
use Niainteractive\PenCatalog\Models\MemberPen;

class InkLog extends ComponentBase
{
    public function componentDetails()
    {
        return [
            'name'        => 'Ink log',
            'description' => 'What is inked right now, and what has been used.',
        ];
    }

    public function onRun()
    {
        $user = \Niainteractive\PenCatalog\Classes\MemberAuth::user();
        $this->page['isGuest'] = !$user;
        $this->page['csrfToken'] = csrf_token();
        $this->page['preselectRow'] = 0;
        $this->page['current'] = [];
        $this->page['history'] = [];
        $this->page['pens'] = [];

        if (!$user) {
            return;
        }

        $this->page['current'] = InkLogEntry::currentForUser($user->id);
        $this->page['history'] = InkLogEntry::historyForUser($user->id);

        /*
         * The picker lists physical pens, not models: two X450s in different
         * colours are two choices, because they can be filled with two inks.
         * The value posted is the member_pen row id.
         */
        $records = MemberPen::forUser($user->id)->inList('owned')
            ->with('item.brand')
            ->get()
            ->filter(function ($row) { return (bool) $row->item; });

        $totals = [];
        foreach ($records as $row) {
            $totals[$row->item_id] = ($totals[$row->item_id] ?? 0) + 1;
        }

        $seen = [];
        $pens = [];
        foreach ($records as $row) {
            $seen[$row->item_id] = ($seen[$row->item_id] ?? 0) + 1;

            $label = ($row->item->brand ? $row->item->brand->name . ' ' : '') . $row->item->model;
            if ($row->variant_color) {
                $label .= ' — ' . $row->variant_color;
            }
            if ($totals[$row->item_id] > 1) {
                $label .= ' (copy ' . $seen[$row->item_id] . ' of ' . $totals[$row->item_id] . ')';
            }

            $pens[] = ['id' => $row->id, 'item_id' => $row->item_id, 'label' => $label];
        }

        $this->page['pens'] = $pens;

        /* /ink-log?row=N is exact (the "Ink it" link on a shelf row). The older
         * /ink-log?pen=<item id> still works and picks the first copy. */
        $wantedRow = (int) get('row');
        $wantedItem = (int) get('pen');

        if ($wantedRow) {
            foreach ($pens as $pen) {
                if ($pen['id'] === $wantedRow) {
                    $this->page['preselectRow'] = $wantedRow;
                }
            }
        } elseif ($wantedItem) {
            foreach ($pens as $pen) {
                if ($pen['item_id'] === $wantedItem) {
                    $this->page['preselectRow'] = $pen['id'];
                    break;
                }
            }
        }
    }
}
