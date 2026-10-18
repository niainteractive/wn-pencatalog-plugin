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
            'description' => 'A signed-in member\'s favorites or owned pens.',
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
        return $this->property('list', 'wish') === 'owned' ? 'owned' : 'wish';
    }

    protected function userId()
    {
        $user = \Auth::getUser();
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
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($records as $row) {
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
                'item'        => $row->item,
                'quantity'    => $row->quantity,
                'price_paid'  => $row->price_paid,
                'acquired_on' => $row->acquired_on ? $row->acquired_on->format('Y-m-d') : '',
                'nib_grind'   => $row->nib_grind,
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
        $itemId = (int) post('item_id');
        if ($itemId) {
            MemberPen::addForUser($userId, $itemId, $this->list());
        }
        return $this->refresh();
    }

    public function onRemove()
    {
        if (!$userId = $this->userId()) {
            return [];
        }
        MemberPen::removeForUser($userId, (int) post('item_id'), $this->list());
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
        foreach (['quantity', 'price_paid', 'acquired_on', 'condition', 'nib_grind', 'serial_no', 'notes', 'status'] as $field) {
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
