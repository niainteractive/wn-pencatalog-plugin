<?php namespace Niainteractive\PenCatalog\Components;

use Cms\Classes\ComponentBase;
use Niainteractive\PenCatalog\Models\Item;
use Niainteractive\PenCatalog\Models\Make;
use Niainteractive\PenCatalog\Models\Settings;
use Niainteractive\PenCatalog\Models\TermGroup;

/**
 * Sortable, filterable table of catalog entries.
 *
 * All links (sort, filter, click-through) are built server-side as plain query
 * strings, so the table works with JavaScript disabled. The login gate reads
 * the plugin setting and is OFF by default.
 */
class CatalogTable extends ComponentBase
{
    /** @var \Illuminate\Pagination\LengthAwarePaginator */
    public $items;

    /** @var array Rows prepared for the template (item + terms bucketed by group) */
    public $rows = [];

    /** @var array Rating filter choices (1-10, half points) */
    public $ratingOptions = [];

    /** @var array Column definitions */
    public $columns = [];

    /** @var array sort key => URL for the next sort direction */
    public $sortLinks = [];

    /** @var array group slug => [term slug => ['name' => ..., 'url' => ..., 'active' => bool]] */
    public $filterLinks = [];

    /** @var bool Rendered message instead of data? */
    public $restricted = false;

    /** @var \Niainteractive\PenCatalog\Models\Make|null */
    public $make;

    public function componentDetails()
    {
        return [
            'name'        => 'Pen catalog table',
            'description' => 'Sortable, filterable table of catalogue entries.',
        ];
    }

    public function defineProperties()
    {
        return [
            'perPage' => [
                'title'   => 'Rows per page',
                'default' => 50,
                'validationPattern' => '^[0-9]+$',
            ],
            'defaultSort' => [
                'title'   => 'Default sort column',
                'default' => 'model',
            ],
            'defaultDirection' => [
                'title'   => 'Default sort direction',
                'default' => 'asc',
            ],
            'baseUrl' => [
                'title'   => 'Base URL for links (leave blank for /pens)',
                'default' => '/pens',
            ],
        ];
    }

    public function onRun()
    {
        if (Settings::get('require_login', false) && !$this->userIsAllowed()) {
            $this->restricted = true;
            $this->page['catalogRestricted'] = true;
            return;
        }

        $this->loadData();
    }

    protected function userIsAllowed()
    {
        if (!$this->page || !$this->page->layout) {
            return false;
        }

        try {
            return (bool) \Auth::guard('web')->check();
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function loadData()
    {
        $base = rtrim((string) $this->property('baseUrl', '/pens'), '/');
        $base = $base === '' ? '/pens' : $base;

        $sortable = ['model', 'brand', 'rating', 'inspiration', 'weight'];

        $sort = (string) get('sort');
        if (!in_array($sort, $sortable, true)) {
            $sort = (string) $this->property('defaultSort', 'model');
        }
        if (!in_array($sort, $sortable, true)) {
            $sort = 'model';
        }

        $dir = strtolower((string) get('dir')) === 'desc' ? 'desc' : 'asc';

        $query = Item::with(['brand', 'terms.group'])->where('is_published', 1);

        if ($sort === 'brand') {
            $query->leftJoin('niainteractive_pencatalog_makes as mk', 'mk.id', '=', 'niainteractive_pencatalog_items.make_id')
                  ->orderBy('mk.name', $dir)
                  ->select('niainteractive_pencatalog_items.*');
        } else {
            $query->orderBy('niainteractive_pencatalog_items.' . $sort, $dir);
        }

        /* Make filter */
        $makeSlug = (string) get('make');
        if ($makeSlug !== '') {
            $make = Make::where('slug', $makeSlug)->first();
            if ($make) {
                $query->where('make_id', $make->id);
                $this->make = $make;
            } else {
                // unknown make: return nothing rather than silently ignoring the filter
                $query->whereRaw('1 = 0');
            }
        }

        /* Term filters: ?term[nationality]=japanese */
        $termFilters = (array) get('term');
        $termFilters = array_filter(array_map('strval', $termFilters), function ($v) {
            return $v !== '';
        });

        foreach ($termFilters as $groupSlug => $termSlug) {
            $query->whereHas('terms', function ($q) use ($groupSlug, $termSlug) {
                $q->where('slug', $termSlug)->whereHas('group', function ($g) use ($groupSlug) {
                    $g->where('slug', $groupSlug);
                });
            });
        }

        /* Rating filter */
        $rating = (float) get('rating');
        if ($rating > 0) {
            $query->where('rating', '>=', $rating);
        }

        $this->items = $query->paginate((int) $this->property('perPage', 50));

        /* Prepare rows */
        foreach ($this->items as $item) {
            $byGroup = [];
            foreach ($item->terms as $term) {
                $slug = $term->group ? $term->group->slug : 'other';
                $byGroup[$slug][] = $term;
            }
            $this->rows[] = ['item' => $item, 'terms' => $byGroup];
        }

        /* Rating filter choices: 1-10 in half points, high to low */
        $this->ratingOptions = [];
        for ($r = 10; $r >= 1; $r -= 0.5) {
            $this->ratingOptions[] = $r;
        }

        /* Columns */
        $this->columns = [
            ['key' => 'brand',       'label' => 'Make',        'kind' => 'brand', 'sort' => 'brand'],
            ['key' => 'model',       'label' => 'Model',       'kind' => 'model', 'sort' => 'model'],
            ['key' => 'rating',      'label' => 'Rating',      'kind' => 'field', 'sort' => 'rating', 'suffix' => ' / 10'],
            ['key' => 'inspiration', 'label' => 'Inspiration', 'kind' => 'field', 'sort' => 'inspiration'],
            ['key' => 'nationality', 'label' => 'Nationality', 'kind' => 'terms', 'group' => 'nationality'],
            ['key' => 'nib-type',    'label' => 'Nib Type',    'kind' => 'terms', 'group' => 'nib-type'],
            ['key' => 'ink-system',  'label' => 'Ink',         'kind' => 'terms', 'group' => 'ink-system'],
            ['key' => 'weight',      'label' => 'Weight',      'kind' => 'field', 'sort' => 'weight', 'suffix' => ' g'],
        ];

        /* Sort links (flip direction on the active column) */
        foreach ($this->columns as $column) {
            $key = isset($column['sort']) ? $column['sort'] : null;
            if (!$key) {
                continue;
            }
            $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
            $this->sortLinks[$key] = $base . $this->queryString(array_merge($_GET, ['sort' => $key, 'dir' => $nextDir]));
        }

        /* Filter links: every active group, capped so the page stays sane */
        $groups = TermGroup::where('is_active', 1)->orderBy('sort_order')->get();
        foreach ($groups as $group) {
            $terms = $group->terms()->where('is_active', 1)->orderBy('sort_order')->get();
            if ($terms->isEmpty()) {
                continue;
            }
            foreach ($terms as $term) {
                $active = (isset($termFilters[$group->slug]) && $termFilters[$group->slug] === $term->slug);
                $params = $_GET;
                if ($active) {
                    unset($params['term'][$group->slug]);
                } else {
                    $params['term'][$group->slug] = $term->slug;
                }
                $params['page'] = null;
                $this->filterLinks[$group->slug][$term->slug] = [
                    'name'   => $term->name,
                    'active' => $active,
                    'url'    => $base . $this->queryString($params),
                ];
            }
        }

        $this->page['rows'] = $this->rows;
        $this->page['columns'] = $this->columns;
        $this->page['sortLinks'] = $this->sortLinks;
        $this->page['filterLinks'] = $this->filterLinks;
        $this->page['items'] = $this->items;
        $this->page['groupLabels'] = $groups->pluck('name', 'slug')->all();
        $this->page['make'] = $this->make;
    }

    /** AJAX filter/sort refresh (progressive enhancement) */
    public function onFilter()
    {
        $this->loadData();

        return [
            '#catalogTable' => $this->renderPartial('@table'),
        ];
    }

    protected function queryString($params)
    {
        $clean = [];
        foreach ((array) $params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $clean[$key] = $value;
        }
        $qs = http_build_query($clean);

        return $qs === '' ? '' : '?' . $qs;
    }
}