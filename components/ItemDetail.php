<?php namespace Niainteractive\PenCatalog\Components;

use Cms\Classes\ComponentBase;
use Niainteractive\PenCatalog\Models\Item;
use Niainteractive\PenCatalog\Models\Settings;

class ItemDetail extends ComponentBase
{
    public $item;

    /** @var array group slug => [label, terms] */
    public $termGroups = [];

    public $offers;

    public $photos;

    /** @var array of sanitised video embed snippets */
    public $videoEmbeds = [];

    public function componentDetails()
    {
        return [
            'name'        => 'Pen detail',
            'description' => 'Full record for one catalogue entry.',
        ];
    }

    public function defineProperties()
    {
        return [
            'slug' => [
                'title'       => 'Slug (leave blank to use the URL parameter)',
                'default'     => '',
            ],
        ];
    }

    public function onRun()
    {
        $slug = (string) $this->property('slug');

        if ($slug === '' && method_exists($this, 'param')) {
            try {
                $slug = (string) $this->param('slug');
            } catch (\Throwable $e) {
                $slug = '';
            }
        }

        if ($slug === '') {
            $segments = \Request::segments();
            $slug = (string) end($segments);
        }

        if ($slug === '') {
            $slug = (string) get('slug');
        }

        if (Settings::get('require_login', false) && !$this->signedIn()) {
            $this->page['catalogRestricted'] = true;
            return;
        }

        $this->item = Item::with(['brand', 'terms.group', 'offers.vendor', 'photos.file'])
            ->where('slug', $slug)
            ->where('is_published', 1)
            ->first();

        if (!$this->item) {
            return \Response::make($this->controller->run('404'), 404);
        }

        foreach ($this->item->terms as $term) {
            $slugKey = $term->group ? $term->group->slug : 'other';
            if (!isset($this->termGroups[$slugKey])) {
                $this->termGroups[$slugKey] = [
                    'label' => $term->group ? $term->group->name : 'Other',
                    'terms' => [],
                ];
            }
            $this->termGroups[$slugKey]['terms'][] = $term;
        }

        $this->offers = $this->item->offers;
        $this->photos = $this->item->photos;
        $this->videoEmbeds = $this->item->videoEmbeds();

        $this->page['item'] = $this->item;
        $this->page['termGroups'] = $this->termGroups;
        $this->page['offers'] = $this->offers;
        $this->page['photos'] = $this->photos;
        $this->page['videoEmbeds'] = $this->videoEmbeds;
        $this->page['photo'] = $this->photos->where('is_primary', 1)->first() ?: $this->photos->first();
    }

    protected function signedIn()
    {
        try {
            return (bool) \Auth::guard('web')->check();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
