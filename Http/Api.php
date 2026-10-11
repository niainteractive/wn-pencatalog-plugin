<?php namespace Niainteractive\PenCatalog\Http;

use Illuminate\Http\Request;
use Niainteractive\PenCatalog\Models\Item;
use Niainteractive\PenCatalog\Models\ItemPhoto;
use Niainteractive\PenCatalog\Models\Make;
use Niainteractive\PenCatalog\Models\Offer;
use Niainteractive\PenCatalog\Models\Settings;
use Niainteractive\PenCatalog\Models\Term;
use Niainteractive\PenCatalog\Models\TermGroup;
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
            'version'    => '1.0.5',
            'can_write'  => (bool) Settings::get('api_allow_writes', false),
            'pens'       => Item::count(),
            'server_time' => date('c'),
        ]);
    }

    /** Everything the caller needs to file a pen without inventing values. */
    public function vocabularies(Request $request)
    {
        if ($fail = $this->auth($request)) {
            return $fail;
        }

        $makes = Make::orderBy('name')->get(['id', 'name', 'slug', 'website', 'country_term_id'])
            ->map(function ($m) {
                return ['name' => $m->name, 'slug' => $m->slug, 'website' => $m->website];
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

    /** Create or update a pen. Idempotent: matches on the slug built from make + model. */
    public function penStore(Request $request)
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

        foreach (['inspiration', 'description', 'notes'] as $text) {
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

        /* Terms: only names already in the vocabulary, unless opted in. */
        if (isset($data['terms']) && is_array($data['terms'])) {
            $termIds = [];
            $errors = [];

            foreach ($data['terms'] as $groupSlug => $values) {
                $group = TermGroup::where('slug', $groupSlug)->first();
                if (!$group) {
                    $errors[] = 'Unknown term group "' . $groupSlug . '".';
                    continue;
                }

                foreach ((array) $values as $name) {
                    $term = Term::where('group_id', $group->id)
                        ->where(function ($q) use ($name) {
                            $q->where('name', $name)->orWhere('slug', \Winter\Storm\Support\Str::slug($name));
                        })->first();

                    if (!$term) {
                        if (empty($data['create_missing_terms'])) {
                            $errors[] = 'Unknown term "' . $name . '" in group "' . $groupSlug . '". Valid: '
                                . implode(', ', Term::where('group_id', $group->id)->pluck('name')->all());
                            continue;
                        }
                        $term = new Term();
                        $term->group_id = $group->id;
                        $term->name = $name;
                        $term->slug = \Winter\Storm\Support\Str::slug($name);
                        $term->save();
                    }

                    $termIds[] = $term->id;
                }
            }

            if ($termIds) {
                $item->terms()->syncWithoutDetaching($termIds);
                $changes['terms_added'] = count($termIds);
                $item->load('terms');
            }

            if ($errors) {
                return $this->json(['ok' => false, 'errors' => $errors, 'pen' => $this->penArray($item)], 422);
            }
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

    /* ------------------------------------------------------------------ helpers */

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
        $terms = [];
        foreach ($item->terms as $term) {
            if ($term->group) {
                $terms[$term->group->slug][] = $term->name;
            }
        }

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
