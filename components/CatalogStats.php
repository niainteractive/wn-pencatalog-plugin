<?php namespace Niainteractive\PenCatalog\Components;

use Cms\Classes\ComponentBase;
use Niainteractive\PenCatalog\Models\Item;
use Niainteractive\PenCatalog\Models\Make;
use Niainteractive\PenCatalog\Models\Vendor;
use Niainteractive\PenCatalog\Models\MemberPen;

/**
 * Live catalogue counts for the home page.
 *
 * Nothing here is decorative: a number printed on the home page should be a
 * number the catalogue can prove, or it drifts the moment someone adds a pen.
 */
class CatalogStats extends ComponentBase
{
    public function componentDetails()
    {
        return [
            'name'        => 'Catalogue stats',
            'description' => 'Publishes live counts (pens, makes, vendors, logged pens) to the page.',
        ];
    }

    public function onRun()
    {
        $this->page['statsPens'] = Item::where('is_published', 1)->count();
        $this->page['statsMakes'] = Make::count();
        $this->page['statsVendors'] = Vendor::count();
        $this->page['statsShelf'] = MemberPen::where('list', 'owned')->count();
    }
}
