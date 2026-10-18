<?php namespace Niainteractive\PenCatalog\Components;

use Cms\Classes\ComponentBase;
use Niainteractive\PenCatalog\Models\Make;

class MakesIndex extends ComponentBase
{
    public function componentDetails()
    {
        return [
            'name'        => 'Makes index',
            'description' => 'Lists every active make with a pen count.'
        ];
    }

    public function onRun()
    {
        $this->page['makes'] = Make::where('is_active', 1)
            ->withCount('items')
            ->orderBy('name')
            ->get();
    }
}
