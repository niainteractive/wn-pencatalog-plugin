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
        $user = \Auth::getUser();
        $this->page['isGuest'] = !$user;
        $this->page['csrfToken'] = csrf_token();
        $this->page['preselect'] = (int) get('pen');
        $this->page['current'] = [];
        $this->page['history'] = [];
        $this->page['pens'] = [];

        if (!$user) {
            return;
        }

        $this->page['current'] = InkLogEntry::currentForUser($user->id);
        $this->page['history'] = InkLogEntry::historyForUser($user->id);

        $this->page['pens'] = MemberPen::forUser($user->id)->inList('owned')
            ->with('item.brand')
            ->get()
            ->filter(function ($row) { return (bool) $row->item; })
            ->map(function ($row) {
                return [
                    'id'    => $row->item->id,
                    'label' => ($row->item->brand ? $row->item->brand->name . ' ' : '') . $row->item->model,
                ];
            })->values();
    }
}
