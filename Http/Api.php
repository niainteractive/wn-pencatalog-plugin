<?php namespace Niainteractive\PenCatalog\Http;

use Illuminate\Http\Request;
use Niainteractive\PenCatalog\Models\Item;
use Niainteractive\PenCatalog\Models\ItemPhoto;
use Niainteractive\PenCatalog\Models\Make;
use Niainteractive\PenCatalog\Models\Offer;
use Niainteractive\PenCatalog\Models\Settings;
use Niainteractive\PenCatalog\Models\Term;
use Niainteractive\PenCatalog\Models\TermGroup;
use Niainteractive\PenCatalog\Models\ItemType;
use Niainteractive\PenCatalog\Models\Vendor;

/**
 * Token-authenticated API for an external lookup agent.
 *
 * Design rules:
 *  - The vocabulary is authoritative. Unknown makes, terms or vendors are REFUSED with the list
 *    of valid values, unless the caller explicitly opts in with create_missing_* - an agent
 *    inventing its own vocabulary would wreck the catalogue's filterability.
 *  - Photos arrive as bytes or as a URL. The caller never needs filesystem access.
 *  - Every write is appended to storage/logs/pencatalog_api.log.
 */
class Api
{
    const MAX_UPLOAD_BYTES = 8388608;   // 8 MB
    const PHOTO_FOLDER = 'pens';
    const AUDIT_LOG = 'pencatalog_api.log';

    /* ------------------------------------------------------------------ endpoints */

    public function ping(Request $request)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        return $this->json([
            'ok'         => true,
            'service'    => 'PenCatalog API',
            'version'    => '1.1.1',
            'can_write'  => (bool) Settings::get('api_allow_writes', false),
            'pens'       => Item::count(),
            'endpoints'  => [
                'GET  ping', 'GET  vocabularies',
                'GET  pens', 'GET  pens/{slug}', 'POST pens',
                'POST photos',
                'GET  makes', 'POST makes',
                'GET  vendors', 'POST vendors',
                'GET  terms', 'POST terms',
                'GET  term-groups', 'POST term-groups',
                'GET  item-types', 'POST item-types',
            ],
            'server_time' => date('c'),
        ]);
    }

    /** Everything the caller needs to file a pen without inventing values. */
    public function vocabularies(Request $request)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        $makes = Make::orderBy('name')->withCount('items')
            ->get(['id', 'name', 'slug', 'website', 'notes', 'country_term_id'])
            ->map(function ($m) {
                return [
                    'name'    => $m->name,
                    'slug'    => $m->slug,
                    'website' => $m->website,
                    'notes'   => $m->notes,
                    'country' => $m->country ? $m->country->name : null,
                    'pens'    => (int) $m->items_count,
                ];
            })->all();

        $groups = [];
        foreach (TermGroup::orderBy('sort_order')->get() as $group) {
            $terms = Term::where('group_id', $group->id)->orderBy('sort_order')->get(['name', 'slug']);
            if ($terms->isEmpty()) {
                continue;
            }
            $groups[$group->slug] = [
                'label'         => $group->name,
                'single_select' => ((int) $group->is_multi === 0),
                'terms'         => $terms->pluck('name')->all(),
            ];
        }

        return $this->json([
            'ok'       => true,
            'makes'    => $makes,
            'term_groups' => $groups,
            'vendors'  => Vendor::orderBy('name')->pluck('name')->all(),
            'rating'   => '1 to 10, half points (e.g. 7.5)',
        ]);
    }

    public function pensIndex(Request $request)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        $query = Item::with(['brand', 'terms.group']);

        if ($make = (string) $request->query('make')) {
            $query->whereHas('brand', function ($q) use ($make) {
                $q->where('slug', $make)->orWhere('name', 'like', $make);
            });
        }

        if ($since = (string) $request->query('updated_since')) {
            $query->where('updated_at', '>=', $since);
        }

        $limit = min(200, max(1, (int) $request->query('limit', 50)));
        $items = $query->orderBy('updated_at', 'desc')->limit($limit)->get();

        $out = [];
        foreach ($items as $item) {
            $out[] = $this->penArray($item);
        }

        return $this->json(['ok' => true, 'count' => count($out), 'pens' => $out]);
    }

    public function penShow(Request $request, $slug)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        $item = Item::with(['brand', 'terms.group', 'offers.vendor', 'photos'])
            ->where('slug', $slug)->first();

        if (!$item) {
            return $this->fail('No pen with slug "' . $slug . '".', 404);
        }

        return $this->json(['ok' => true, 'pen' => $this->penArray($item, true)]);
    }

    /**
     * Create or update a pen. Idempotent: matches on the slug built from make + model.
     *
     * Wrapped so that anything unexpected comes back as JSON with the reason rather than an
     * HTML error page - a caller on the far side of an API learns nothing from a 500 with no body.
     */
    public function penStore(Request $request)
    {
        try {
            return $this->penStoreInner($request);
        } catch (\Throwable $e) {
            return $this->crash($request, 'pens', $e);
        }
    }

    protected function penStoreInner(Request $request)
    {
        if ($fail = $this->auth($request, true)) {
            return $fail;
        }

        $data = $request->json()->all() ?: $request->all();

        $makeName = trim((string) ($data['make'] ?? ''));
        $model    = trim((string) ($data['model'] ?? ''));

        if ($makeName === '' || $model === '') {
            return $this->fail('Both "make" and "model" are required.', 422);
        }

        $make = Make::where('slug', \Winter\Storm\Support\Str::slug($makeName))
            ->orWhere('name', $makeName)
            ->first();

        if (!$make) {
            if (empty($data['create_missing_make'])) {
                return $this->fail(
                    'Unknown make "' . $makeName . '". Pass create_missing_make=true to add it, or use one of: '
                    . implode(', ', Make::orderBy('name')->pluck('name')->all()),
                    422
                );
            }
            $make = new Make();
            $make->name = $makeName;
            $make->slug = \Winter\Storm\Support\Str::slug($makeName);
            $make->is_active = 1;
            $make->save();
        }

        $item = Item::where('make_id', $make->id)->where('model', $model)->first();
        $action = $item ? 'updated' : 'created';

        if (!$item) {
            $item = new Item();
            $item->make_id = $make->id;
            $item->model = $model;
        }

        $changes = [];

        foreach (['rating', 'length_closed', 'length_uncapped', 'length_posted', 'weight'] as $numeric) {
            if (array_key_exists($numeric, $data) && $data[$numeric] !== null && $data[$numeric] !== '') {
                $item->$numeric = (float) $data[$numeric];
                $changes[$numeric] = $item->$numeric;
            }
        }

        // "videos" takes embed code or a bare YouTube/Vimeo/Dailymotion/Bilibili link; the
        // plugin turns either into a sanitised player on the detail page.
        foreach (['inspiration', 'description', 'notes', 'videos'] as $text) {
            if (array_key_exists($text, $data)) {
                $item->$text = (string) $data[$text];
                $changes[$text] = 'set';
            }
        }

        if (isset($data['is_published'])) {
            $item->is_published = (int) (bool) $data['is_published'];
        }

        if (!$item->slug) {
            $item->generateSlugIfEmpty();
        }

        $item->save();   // slug is generated on save for new rows

        /* Terms.
         *
         *   terms         {group: [names]}   the terms to act on
         *   terms_mode    "add" (default) or "replace"
         *   terms_remove  {group: [names]}   drop just these, change nothing else
         *
         * "add"     - attach what is listed; whatever is already on the pen stays.
         * "replace" - the groups you list become exactly what you list. Groups you do NOT
         *             mention are left alone, and an empty list clears that group. This is the
         *             way to correct a value: a single-select group like nationality goes from
         *             ["Other", "Turkish"] to ["Turkish"] with terms_mode=replace.
         *
         * Terms must already exist unless create_missing_terms is set. An unknown parameter is
         * refused with the accepted list rather than half-applied.
         */
        $acceptedTermKeys = ['terms', 'terms_mode', 'terms_remove', 'create_missing_terms'];
        foreach (array_keys($data) as $key) {
            if (stripos($key, 'term') !== false && !in_array($key, $acceptedTermKeys, true)) {
                return $this->fail(
                    'Unknown parameter "' . $key . '". The term parameters are: '
                    . implode(', ', $acceptedTermKeys) . '. To swap a pen\'s terms send "terms" with '
                    . '"terms_mode": "replace"; to drop a single term use "terms_remove".',
                    422
                );
            }
        }

        $mode = strtolower(trim((string) ($data['terms_mode'] ?? 'add')));
        if ($mode === '') {
            $mode = 'add';
        }
        if (!in_array($mode, ['add', 'replace'], true)) {
            return $this->fail('"terms_mode" must be "add" or "replace" (got "' . $mode . '").', 422);
        }

        $errors = [];

        if (isset($data['terms']) && is_array($data['terms'])) {
            $replaced = [];

            foreach ($data['terms'] as $groupSlug => $values) {
                $group = TermGroup::where('slug', $groupSlug)->first();
                if (!$group) {
                    $errors[] = 'Unknown term group "' . $groupSlug . '". Known groups: '
                        . implode(', ', TermGroup::orderBy('sort_order')->pluck('slug')->all());
                    continue;
                }

                $termIds = [];
                foreach ((array) $values as $name) {
                    $term = $this->findTerm($group, $name, !empty($data['create_missing_terms']), $errors);
                    if ($term) {
                        $termIds[] = $term->id;
                    }
                }

                if ($mode === 'replace') {
                    // Clear this one group only, then attach exactly what was asked for.
                    // An empty list therefore clears the group.
                    $item->terms()->detach(Term::where('group_id', $group->id)->pluck('id')->all());
                    $replaced[] = $groupSlug;
                }

                if ($termIds) {
                    $item->terms()->syncWithoutDetaching($termIds);
                }
            }

            $item->load('terms');
            $changes['terms_mode'] = $mode;
            if ($replaced) {
                $changes['terms_replaced_in'] = $replaced;
            }
            $changes['terms_now'] = $this->termsByGroup($item);
        }

        if (isset($data['terms_remove']) && is_array($data['terms_remove'])) {
            $removed = [];
            $absent = [];

            foreach ($data['terms_remove'] as $groupSlug => $values) {
                $group = TermGroup::where('slug', $groupSlug)->first();
                if (!$group) {
                    $errors[] = 'Unknown term group "' . $groupSlug . '" in terms_remove.';
                    continue;
                }

                foreach ((array) $values as $name) {
                    $term = $this->findTerm($group, $name, false, $errors, false);
                    if (!$term) {
                        $absent[] = $groupSlug . ':' . $name;   // already gone; not an error
                        continue;
                    }
                    $item->terms()->detach($term->id);
                    $removed[] = $groupSlug . ':' . $term->name;
                }
            }

            $item->load('terms');
            $changes['terms_removed'] = $removed;
            if ($absent) {
                $changes['terms_not_present'] = $absent;
            }
            $changes['terms_now'] = $this->termsByGroup($item);
        }

        if ($errors) {
            return $this->json(['ok' => false, 'errors' => $errors, 'pen' => $this->penArray($item)], 422);
        }

        /* Offers: appended only when they are not already on the pen. */
        $errors = [];
        if (!empty($data['offers']) && is_array($data['offers'])) {
            foreach ($data['offers'] as $offerData) {
                $vendor = null;
                if (!empty($offerData['vendor'])) {
                    $vendor = Vendor::where('name', $offerData['vendor'])->first();
                    if (!$vendor) {
                        $errors[] = 'Unknown vendor "' . $offerData['vendor'] . '". Valid: '
                            . implode(', ', Vendor::pluck('name')->all());
                        continue;
                    }
                }

                $exists = Offer::where('item_id', $item->id)
                    ->where('vendor_id', $vendor ? $vendor->id : null)
                    ->where('url', $offerData['url'] ?? null)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $offer = new Offer();
                $offer->item_id = $item->id;
                $offer->vendor_id = $vendor ? $vendor->id : null;
                $offer->label = $offerData['label'] ?? null;
                $offer->url = $offerData['url'] ?? null;
                $offer->price = isset($offerData['price']) ? (float) $offerData['price'] : null;
                $offer->currency = $offerData['currency'] ?? Settings::get('default_currency', 'USD');
                $offer->save();
                $changes['offer_added'] = ($vendor ? $vendor->name : 'no vendor');
            }
        }

        $this->audit($request, $action, $item->slug);

        return $this->json([
            'ok'      => true,
            'action'  => $action,
            'id'      => $item->id,
            'slug'    => $item->slug,
            'changes' => $changes,
        ], $action === 'created' ? 201 : 200);
    }

    /**
     * Attach a photo. Either multipart with an "image" file, or JSON with a "url" for the
     * server to fetch. Bytes land in storage/app/media/pens; no filesystem access needed.
     */
    public function photoUpload(Request $request)
    {
        try {
            return $this->photoUploadInner($request);
        } catch (\Throwable $e) {
            return $this->crash($request, 'photo', $e);
        }
    }

    protected function photoUploadInner(Request $request)
    {
        if ($fail = $this->auth($request, true)) {
            return $fail;
        }

        $slug = trim((string) ($request->input('slug') ?: ($request->json()->get('slug') ?? '')));
        if ($slug === '') {
            return $this->fail('"slug" (the pen) is required.', 422);
        }

        $item = Item::where('slug', $slug)->first();
        if (!$item) {
            return $this->fail('No pen with slug "' . $slug . '".', 404);
        }

        $folder = storage_path('app/media/' . self::PHOTO_FOLDER);
        if (!is_dir($folder) && !mkdir($folder, 0775, true)) {
            return $this->fail('Cannot create the media folder.', 500);
        }

        $number = (int) ($request->input('number') ?: 0);
        $extension = null;
        $bytes = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            if (!$file->isValid()) {
                return $this->fail('The upload failed to arrive.', 422);
            }
            if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
                return $this->fail('Image is larger than ' . (self::MAX_UPLOAD_BYTES / 1048576) . ' MB.', 413);
            }
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $bytes = file_get_contents($file->getRealPath());
        } elseif ($url = (string) ($request->input('url') ?: ($request->json()->get('url') ?? ''))) {
            $fetched = $this->fetchUrl($url);
            if (is_string($fetched)) {
                return $this->fail($fetched, 422);
            }
            $bytes = $fetched['bytes'];
            $extension = $fetched['extension'];
        } else {
            return $this->fail('Provide either an "image" file (multipart) or a "url".', 422);
        }

        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        if (!in_array($extension, ['jpg', 'png', 'webp', 'gif'], true)) {
            return $this->fail('Unsupported image type "' . $extension . '". Use jpg, png, webp or gif.', 422);
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return $this->fail('That does not look like an image.', 422);
        }

        if ($number <= 0) {
            $number = $this->nextNumber($folder, $slug);
        }

        $filename = $slug . '-' . $number . '.' . $extension;
        $target = $folder . '/' . $filename;

        if (is_file($target)) {
            return $this->fail(
                'pens/' . $filename . ' already exists. Pass a different "number", or delete it first.',
                409
            );
        }

        if (file_put_contents($target, $bytes) === false) {
            return $this->fail('Could not write the image.', 500);
        }

        $relative = self::PHOTO_FOLDER . '/' . $filename;

        $photo = new ItemPhoto();
        $photo->item_id = $item->id;
        $photo->path = $relative;
        $photo->sort_order = $number;
        $photo->caption = $request->input('caption') ?: null;
        $photo->is_primary = ItemPhoto::where('item_id', $item->id)->where('is_primary', 1)->exists() ? 0 : 1;
        $photo->save();

        $this->audit($request, 'photo', $slug . ' (' . $relative . ')');

        return $this->json([
            'ok'        => true,
            'photo_id'  => $photo->id,
            'path'      => $relative,
            'url'       => $photo->url,
            'width'     => $info[0],
            'height'    => $info[1],
            'primary'   => (bool) $photo->is_primary,
        ], 201);
    }

    /* ------------------------------------------------------------------ vocabulary: read */

    /** Every make, with the brand-level notes that had nowhere to live before. */
    public function makesIndex(Request $request)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        $makes = Make::orderBy('name')->withCount('items')->get()->map(function ($m) {
            return [
                'id'      => $m->id,
                'name'    => $m->name,
                'slug'    => $m->slug,
                'website' => $m->website,
                'notes'   => $m->notes,
                'country' => $m->country ? $m->country->name : null,
                'active'  => (bool) $m->is_active,
                'pens'    => (int) $m->items_count,
            ];
        })->all();

        return $this->json(['ok' => true, 'count' => count($makes), 'makes' => $makes]);
    }

    public function vendorsIndex(Request $request)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        $vendors = Vendor::orderBy('name')->get()->map(function ($v) {
            return [
                'id'      => $v->id,
                'name'    => $v->name,
                'slug'    => $v->slug,
                'website' => $v->website,
                'notes'   => $v->notes,
                'active'  => (bool) $v->is_active,
            ];
        })->all();

        return $this->json(['ok' => true, 'count' => count($vendors), 'vendors' => $vendors]);
    }

    /** Every group and term. A term keeps its note in the description column. */
    public function termsIndex(Request $request)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        $out = [];
        foreach (TermGroup::orderBy('sort_order')->get() as $group) {
            $terms = Term::where('group_id', $group->id)->orderBy('sort_order')->orderBy('name')->get();
            $out[$group->slug] = [
                'label'         => $group->name,
                'single_select' => ((int) $group->is_multi === 0),
                'active'        => (bool) $group->is_active,
                'terms'         => $terms->map(function ($t) {
                    return [
                        'id'     => $t->id,
                        'name'   => $t->name,
                        'slug'   => $t->slug,
                        'notes'  => $t->description,
                        'color'  => $t->color_hex,
                        'active' => (bool) $t->is_active,
                    ];
                })->all(),
            ];
        }

        return $this->json(['ok' => true, 'term_groups' => $out]);
    }

    public function termGroupsIndex(Request $request)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        $groups = TermGroup::orderBy('sort_order')->get()->map(function ($g) {
            return [
                'id'            => $g->id,
                'name'          => $g->name,
                'slug'          => $g->slug,
                'notes'         => $g->description,
                'single_select' => ((int) $g->is_multi === 0),
                'sort_order'    => (int) $g->sort_order,
                'active'        => (bool) $g->is_active,
                'terms'         => Term::where('group_id', $g->id)->count(),
            ];
        })->all();

        return $this->json(['ok' => true, 'count' => count($groups), 'term_groups' => $groups]);
    }

    public function itemTypesIndex(Request $request)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        $types = ItemType::orderBy('sort_order')->get()->map(function ($t) {
            return [
                'id'         => $t->id,
                'name'       => $t->name,
                'slug'       => $t->slug,
                'notes'      => $t->description,
                'sort_order' => (int) $t->sort_order,
                'active'     => (bool) $t->is_active,
            ];
        })->all();

        return $this->json(['ok' => true, 'count' => count($types), 'item_types' => $types]);
    }

    /* ------------------------------------------------------------------ vocabulary: write
     *
     * Adding to the vocabulary is how the caller says "this brand exists", "this vendor exists",
     * "this term exists". All of them are upserts matched on slug or exact name, so filing the
     * same make twice edits it rather than duplicating it.
     *
     * To retire something that pens already point at, send is_active=false instead of deleting -
     * the record stops appearing in filters but nothing dangles.
     */

    public function makeStore(Request $request)
    {
        return $this->upsertVocabulary($request, 'make');
    }

    public function vendorStore(Request $request)
    {
        return $this->upsertVocabulary($request, 'vendor');
    }

    public function termStore(Request $request)
    {
        return $this->upsertVocabulary($request, 'term');
    }

    public function termGroupStore(Request $request)
    {
        return $this->upsertVocabulary($request, 'term-group');
    }

    public function itemTypeStore(Request $request)
    {
        return $this->upsertVocabulary($request, 'item-type');
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Find a term by name or slug inside a group, optionally creating it.
     * Returns null when it is missing and creation was not allowed.
     */
    protected function findTerm(TermGroup $group, $name, $create, array &$errors, $report = true)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $term = Term::where('group_id', $group->id)
            ->where(function ($q) use ($name) {
                $q->where('name', $name)->orWhere('slug', \Winter\Storm\Support\Str::slug($name));
            })->first();

        if ($term) {
            return $term;
        }

        if (!$create) {
            if ($report) {
                $errors[] = 'Unknown term "' . $name . '" in group "' . $group->slug . '". Valid: '
                    . implode(', ', Term::where('group_id', $group->id)->pluck('name')->all())
                    . ' (or pass create_missing_terms=true)';
            }
            return null;
        }

        $term = new Term();
        $term->group_id = $group->id;
        $term->name = $name;
        $term->slug = \Winter\Storm\Support\Str::slug($name);
        $term->is_active = 1;
        $term->save();

        return $term;
    }

    /** The terms on an item, keyed by group slug - what a caller needs to confirm a change. */
    protected function termsByGroup(Item $item)
    {
        $out = [];
        foreach ($item->terms as $term) {
            if ($term->group) {
                $out[$term->group->slug][] = $term->name;
            }
        }

        return $out;
    }

    protected function slugFor(array $data, $name)
    {
        $slug = trim((string) ($data['slug'] ?? ''));

        return \Winter\Storm\Support\Str::slug($slug !== '' ? $slug : $name);
    }

    /** An unexpected failure still has to answer in JSON, or the caller learns nothing. */
    protected function crash(Request $request, $where, \Throwable $e)
    {
        @file_put_contents(
            storage_path('logs/' . self::AUDIT_LOG),
            sprintf("[%s] %s ERROR %s: %s (%s)\n", date('c'), $request->ip(), $where, $e->getMessage(),
                str_replace(storage_path() . '/', '', $e->getFile()) . ':' . $e->getLine()),
            FILE_APPEND
        );

        return $this->fail('Unexpected error while handling ' . $where . ': ' . $e->getMessage()
            . ' - this is logged, please pass it on', 500);
    }

    /* ------------------------------------------------------------------ vocabulary: upserts */

    protected function upsertVocabulary(Request $request, $kind)
    {
        if ($fail = $this->auth($request, true)) {
            return $fail;
        }

        $data = $request->json()->all() ?: $request->all();
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            return $this->fail('"name" is required.', 422);
        }

        try {
            switch ($kind) {
                case 'make':
                    return $this->upsertMake($request, $data, $name);
                case 'vendor':
                    return $this->upsertVendor($request, $data, $name);
                case 'term':
                    return $this->upsertTerm($request, $data, $name);
                case 'term-group':
                    return $this->upsertTermGroup($request, $data, $name);
                case 'item-type':
                    return $this->upsertItemType($request, $data, $name);
            }
        } catch (\Throwable $e) {
            return $this->crash($request, $kind, $e);
        }

        return $this->fail('Unknown vocabulary "' . $kind . '".', 400);
    }

    protected function upsertMake(Request $request, array $data, $name)
    {
        $slug = $this->slugFor($data, $name);
        $make = Make::where('slug', $slug)->orWhere('name', $name)->first();
        $action = $make ? 'updated' : 'created';

        if (!$make) {
            $make = new Make();
            $make->slug = $slug;
            $make->is_active = 1;
        }

        $changes = [];
        if ($make->name !== $name) {
            $make->name = $name;
            $changes['name'] = $name;
        }

        foreach (['website', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $make->$field = ($data[$field] === null || $data[$field] === '') ? null : (string) $data[$field];
                $changes[$field] = $make->$field === null ? 'cleared' : 'set';
            }
        }

        if (array_key_exists('is_active', $data)) {
            $make->is_active = (int) (bool) $data['is_active'];
            $changes['is_active'] = (int) $make->is_active;
        }

        if (!empty($data['country'])) {
            $group = TermGroup::where('slug', 'nationality')->first();
            $errors = [];
            $term = $group
                ? $this->findTerm($group, $data['country'], !empty($data['create_missing_terms']), $errors)
                : null;

            if (!$term) {
                return $this->fail('Unknown country "' . $data['country'] . '". '
                    . ($errors ? implode(' ', $errors) : 'There is no nationality term group.'), 422);
            }

            $make->country_term_id = $term->id;
            $changes['country'] = $term->name;
        }

        $make->save();
        $this->audit($request, 'make ' . $action, $make->slug);

        return $this->json([
            'ok'      => true,
            'action'  => $action,
            'kind'    => 'make',
            'id'      => $make->id,
            'name'    => $make->name,
            'slug'    => $make->slug,
            'notes'   => $make->notes,
            'website' => $make->website,
            'changes' => $changes,
        ], $action === 'created' ? 201 : 200);
    }

    protected function upsertVendor(Request $request, array $data, $name)
    {
        $slug = $this->slugFor($data, $name);
        $vendor = Vendor::where('slug', $slug)->orWhere('name', $name)->first();
        $action = $vendor ? 'updated' : 'created';

        if (!$vendor) {
            $vendor = new Vendor();
            $vendor->slug = $slug;
            $vendor->is_active = 1;
        }

        $changes = [];
        if ($vendor->name !== $name) {
            $vendor->name = $name;
            $changes['name'] = $name;
        }

        foreach (['website', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $vendor->$field = ($data[$field] === null || $data[$field] === '') ? null : (string) $data[$field];
                $changes[$field] = $vendor->$field === null ? 'cleared' : 'set';
            }
        }

        if (array_key_exists('is_active', $data)) {
            $vendor->is_active = (int) (bool) $data['is_active'];
            $changes['is_active'] = (int) $vendor->is_active;
        }

        $vendor->save();
        $this->audit($request, 'vendor ' . $action, $vendor->slug);

        return $this->json([
            'ok'      => true,
            'action'  => $action,
            'kind'    => 'vendor',
            'id'      => $vendor->id,
            'name'    => $vendor->name,
            'slug'    => $vendor->slug,
            'notes'   => $vendor->notes,
            'website' => $vendor->website,
            'changes' => $changes,
        ], $action === 'created' ? 201 : 200);
    }

    protected function upsertTerm(Request $request, array $data, $name)
    {
        $groupRef = trim((string) ($data['group'] ?? $data['term_group'] ?? ''));
        if ($groupRef === '') {
            return $this->fail('"group" is required - the slug or name of the term group. Known groups: '
                . implode(', ', TermGroup::orderBy('sort_order')->pluck('slug')->all()), 422);
        }

        $group = TermGroup::where('slug', \Winter\Storm\Support\Str::slug($groupRef))
            ->orWhere('name', $groupRef)->first();

        if (!$group) {
            if (empty($data['create_missing_group'])) {
                return $this->fail('Unknown term group "' . $groupRef . '". Known groups: '
                    . implode(', ', TermGroup::orderBy('sort_order')->pluck('slug')->all())
                    . ' - pass create_missing_group=true to add a new one.', 422);
            }
            $group = new TermGroup();
            $group->name = $groupRef;
            $group->slug = \Winter\Storm\Support\Str::slug($groupRef);
            $group->is_multi = array_key_exists('is_multi', $data) ? (int) (bool) $data['is_multi'] : 1;
            $group->is_active = 1;
            $group->save();
        }

        $slug = $this->slugFor($data, $name);
        $term = Term::where('group_id', $group->id)
            ->where(function ($q) use ($name, $slug) {
                $q->where('name', $name)->orWhere('slug', $slug);
            })->first();

        $action = $term ? 'updated' : 'created';
        if (!$term) {
            $term = new Term();
            $term->group_id = $group->id;
            $term->slug = $slug;
            $term->is_active = 1;
        }

        $changes = [];
        if ($term->name !== $name) {
            $term->name = $name;
            $changes['name'] = $name;
        }

        if (array_key_exists('notes', $data) || array_key_exists('description', $data)) {
            $note = array_key_exists('notes', $data) ? $data['notes'] : $data['description'];
            $term->description = ($note === null || $note === '') ? null : (string) $note;
            $changes['notes'] = $term->description === null ? 'cleared' : 'set';
        }

        if (array_key_exists('color', $data) || array_key_exists('color_hex', $data)) {
            $color = array_key_exists('color', $data) ? $data['color'] : $data['color_hex'];
            $term->color_hex = $color ?: null;
            $changes['color'] = $term->color_hex;
        }

        if (array_key_exists('sort_order', $data)) {
            $term->sort_order = (int) $data['sort_order'];
            $changes['sort_order'] = $term->sort_order;
        }

        if (array_key_exists('is_active', $data)) {
            $term->is_active = (int) (bool) $data['is_active'];
            $changes['is_active'] = (int) $term->is_active;
        }

        $term->save();
        $this->audit($request, 'term ' . $action, $group->slug . ':' . $term->name);

        return $this->json([
            'ok'      => true,
            'action'  => $action,
            'kind'    => 'term',
            'id'      => $term->id,
            'name'    => $term->name,
            'slug'    => $term->slug,
            'group'   => ['id' => $group->id, 'slug' => $group->slug, 'name' => $group->name],
            'notes'   => $term->description,
            'changes' => $changes,
        ], $action === 'created' ? 201 : 200);
    }

    protected function upsertTermGroup(Request $request, array $data, $name)
    {
        $slug = $this->slugFor($data, $name);
        $group = TermGroup::where('slug', $slug)->orWhere('name', $name)->first();
        $action = $group ? 'updated' : 'created';

        if (!$group) {
            $group = new TermGroup();
            $group->slug = $slug;
            $group->is_active = 1;
        }

        $changes = [];
        if ($group->name !== $name) {
            $group->name = $name;
            $changes['name'] = $name;
        }

        if (array_key_exists('notes', $data) || array_key_exists('description', $data)) {
            $note = array_key_exists('notes', $data) ? $data['notes'] : $data['description'];
            $group->description = ($note === null || $note === '') ? null : (string) $note;
            $changes['notes'] = $group->description === null ? 'cleared' : 'set';
        }

        if (array_key_exists('single_select', $data) || array_key_exists('is_multi', $data)) {
            $multi = array_key_exists('is_multi', $data) ? $data['is_multi'] : !$data['single_select'];
            $group->is_multi = (int) (bool) $multi;
            $changes['single_select'] = ((int) $group->is_multi === 0);
        }

        if (array_key_exists('sort_order', $data)) {
            $group->sort_order = (int) $data['sort_order'];
            $changes['sort_order'] = $group->sort_order;
        }

        if (array_key_exists('is_active', $data)) {
            $group->is_active = (int) (bool) $data['is_active'];
            $changes['is_active'] = (int) $group->is_active;
        }

        $group->save();
        $this->audit($request, 'term-group ' . $action, $group->slug);

        return $this->json([
            'ok'      => true,
            'action'  => $action,
            'kind'    => 'term-group',
            'id'      => $group->id,
            'name'    => $group->name,
            'slug'    => $group->slug,
            'changes' => $changes,
        ], $action === 'created' ? 201 : 200);
    }

    protected function upsertItemType(Request $request, array $data, $name)
    {
        $slug = $this->slugFor($data, $name);
        $type = ItemType::where('slug', $slug)->orWhere('name', $name)->first();
        $action = $type ? 'updated' : 'created';

        if (!$type) {
            $type = new ItemType();
            $type->slug = $slug;
            $type->is_active = 1;
        }

        $changes = [];
        if ($type->name !== $name) {
            $type->name = $name;
            $changes['name'] = $name;
        }

        if (array_key_exists('notes', $data) || array_key_exists('description', $data)) {
            $note = array_key_exists('notes', $data) ? $data['notes'] : $data['description'];
            $type->description = ($note === null || $note === '') ? null : (string) $note;
            $changes['notes'] = $type->description === null ? 'cleared' : 'set';
        }

        if (array_key_exists('sort_order', $data)) {
            $type->sort_order = (int) $data['sort_order'];
            $changes['sort_order'] = $type->sort_order;
        }

        if (array_key_exists('is_active', $data)) {
            $type->is_active = (int) (bool) $data['is_active'];
            $changes['is_active'] = (int) $type->is_active;
        }

        $type->save();
        $this->audit($request, 'item-type ' . $action, $type->slug);

        return $this->json([
            'ok'      => true,
            'action'  => $action,
            'kind'    => 'item-type',
            'id'      => $type->id,
            'name'    => $type->name,
            'slug'    => $type->slug,
            'changes' => $changes,
        ], $action === 'created' ? 201 : 200);
    }

    protected function auth(Request $request, $write = false)
    {
        if (!Settings::get('api_enabled', false)) {
            return $this->fail('The catalogue API is switched off.', 503);
        }

        $token = (string) Settings::get('api_token', '');
        if ($token === '') {
            return $this->fail('No API token has been generated yet.', 503);
        }

        // Bearer is the documented way; X-Api-Token is a fallback for hosts that strip
        // the Authorization header before PHP sees it.
        $given = (string) $request->bearerToken();
        if ($given === '') {
            $given = (string) $request->header('X-Api-Token', '');
        }
        if ($given === '' || !hash_equals($token, $given)) {
            return $this->fail('Invalid or missing bearer token.', 401);
        }

        if ($write && !Settings::get('api_allow_writes', false)) {
            return $this->fail('This token is read-only.', 403);
        }

        return null;
    }

    protected function penArray(Item $item, $full = false)
    {
        $terms = $this->termsByGroup($item);

        $out = [
            'id'          => $item->id,
            'make'        => $item->brand ? $item->brand->name : null,
            'model'       => $item->model,
            'slug'        => $item->slug,
            'rating'      => $item->rating !== null ? (float) $item->rating : null,
            'inspiration' => $item->inspiration,
            'terms'       => $terms,
            'updated_at'  => (string) $item->updated_at,
        ];

        if ($full) {
            $out += [
                'length_closed'   => $item->length_closed ? (float) $item->length_closed : null,
                'length_uncapped' => $item->length_uncapped ? (float) $item->length_uncapped : null,
                'length_posted'   => $item->length_posted ? (float) $item->length_posted : null,
                'weight'          => $item->weight ? (float) $item->weight : null,
                'description'     => $item->description,
                'notes'           => $item->notes,
                'videos'          => $item->videos,
                'video_count'     => count($item->videoEmbeds()),
                'is_published'    => (bool) $item->is_published,
            ];
        }

        $out['photos'] = $item->photos->map(function ($photo) {
            return ['path' => $photo->path, 'url' => $photo->url, 'primary' => (bool) $photo->is_primary];
        })->all();

        if ($full) {
            $out['offers'] = $item->offers->map(function ($offer) {
                return [
                    'vendor' => $offer->vendor ? $offer->vendor->name : null,
                    'price'  => $offer->price !== null ? (float) $offer->price : null,
                    'currency' => $offer->currency,
                    'url'    => $offer->url,
                ];
            })->all();
        }

        return $out;
    }

    protected function nextNumber($folder, $slug)
    {
        $used = [];
        foreach (glob($folder . '/' . $slug . '-*.*') ?: [] as $file) {
            if (preg_match('/^' . preg_quote($slug, '/') . '-(\d+)\.[A-Za-z0-9]+$/', basename($file), $m)) {
                $used[] = (int) $m[1];
            }
        }
        $n = 1;
        while (in_array($n, $used, true)) {
            $n++;
        }
        return $n;
    }

    /** Fetch an image by URL, refusing anything that is not a public http(s) resource. */
    protected function fetchUrl($url)
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return 'Only http and https URLs are accepted.';
        }
        if (empty($parts['host'])) {
            return 'That URL has no host.';
        }

        $ip = gethostbyname($parts['host']);
        if ($ip === $parts['host'] && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'Could not resolve that host.';
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'Refusing to fetch from a private or reserved address.';
        }

        $handle = @fopen($url, 'rb');
        if (!$handle) {
            return 'Could not fetch that URL.';
        }

        $bytes = '';
        while (!feof($handle) && strlen($bytes) <= self::MAX_UPLOAD_BYTES) {
            $bytes .= fread($handle, 65536);
        }
        fclose($handle);

        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            return 'That image is larger than ' . (self::MAX_UPLOAD_BYTES / 1048576) . ' MB.';
        }

        $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION) ?: 'jpg');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if (!in_array($extension, ['jpg', 'png', 'webp', 'gif'], true)) {
            $extension = 'jpg';
        }

        return ['bytes' => $bytes, 'extension' => $extension];
    }

    protected function audit(Request $request, $action, $target)
    {
        $line = sprintf("[%s] %s %s %s -> %s\n", date('c'), $request->ip(), $action, $target, $request->path());
        @file_put_contents(storage_path('logs/' . self::AUDIT_LOG), $line, FILE_APPEND);
    }

    protected function json($data, $code = 200)
    {
        return response()->json($data, $code);
    }

    protected function fail($message, $code = 400)
    {
        return $this->json(['ok' => false, 'error' => $message], $code);
    }
}
