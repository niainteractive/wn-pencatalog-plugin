<?php

/*
 * PenCatalog API - for agents that look up pens and file what they find.
 *
 * Auth: Authorization: Bearer <token>   (token lives in Settings -> Pen Catalog)
 * The token is read-only unless "Allow the API to create and edit records" is on.
 *
 * Nothing here touches the filesystem or the database directly from outside: photos are POSTed
 * as bytes (or fetched by the server from a URL), and land in storage/app/media/pens.
 */

use Illuminate\Support\Facades\Route;
use Niainteractive\PenCatalog\Http\Api;

Route::group([
    'prefix'            => 'api/pencatalog',
    'withoutMiddleware' => [\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class],
], function () {
    Route::get('ping',         [Api::class, 'ping']);
    Route::get('vocabularies', [Api::class, 'vocabularies']);
    Route::get('pens',         [Api::class, 'pensIndex']);
    Route::get('pens/{slug}',  [Api::class, 'penShow']);
    Route::post('pens',        [Api::class, 'penStore']);
    Route::post('photos',      [Api::class, 'photoUpload']);

    /* The vocabulary: read every list, and add to it. Writing needs the write switch on.
     * POST makes is where a brand-level note belongs - the make row has a notes column. */
    Route::get('makes',        [Api::class, 'makesIndex']);
    Route::post('makes',       [Api::class, 'makeStore']);
    Route::get('vendors',      [Api::class, 'vendorsIndex']);
    Route::post('vendors',     [Api::class, 'vendorStore']);
    Route::get('terms',        [Api::class, 'termsIndex']);
    Route::post('terms',       [Api::class, 'termStore']);
    Route::get('term-groups',  [Api::class, 'termGroupsIndex']);
    Route::post('term-groups', [Api::class, 'termGroupStore']);
    Route::get('item-types',   [Api::class, 'itemTypesIndex']);
    Route::post('item-types',  [Api::class, 'itemTypeStore']);
});

/*
 * Member list actions as plain links. No `use` statements here: this file has
 * executable code above, and PHP requires imports at the top of the file.
 */
\Illuminate\Support\Facades\Route::middleware('web')->group(function () {

    \Illuminate\Support\Facades\Route::get('favorites/add', function () {
        $user = \Auth::getUser();
        if (!$user) { return \Illuminate\Support\Facades\Redirect::to('/login'); }
        $list = request('list') === 'owned' ? 'owned' : 'wish';
        if ($itemId = (int) request('item_id')) {
            \Niainteractive\PenCatalog\Models\MemberPen::addForUser($user->id, $itemId, $list);
        }
        $back = (string) request('return');
        if ($back === '' || $back[0] !== '/' || substr($back, 0, 2) === '//') {
            $back = '/pens';
        }
        return \Illuminate\Support\Facades\Redirect::to($back);
    });

    \Illuminate\Support\Facades\Route::get('favorites/remove', function () {
        $user = \Auth::getUser();
        if (!$user) { return \Illuminate\Support\Facades\Redirect::to('/login'); }
        $list = request('list') === 'owned' ? 'owned' : 'wish';
        if ($itemId = (int) request('item_id')) {
            \Niainteractive\PenCatalog\Models\MemberPen::removeForUser($user->id, $itemId, $list);
        }
        $back = (string) request('return');
        if ($back === '' || $back[0] !== '/' || substr($back, 0, 2) === '//') {
            $back = '/pens';
        }
        return \Illuminate\Support\Facades\Redirect::to($back);
    });

    \Illuminate\Support\Facades\Route::post('favorites/update', function () {
        $user = \Auth::getUser();
        if (!$user) { return \Illuminate\Support\Facades\Redirect::to('/login'); }
        $list = request('list') === 'owned' ? 'owned' : 'wish';
        $row = \Niainteractive\PenCatalog\Models\MemberPen::forUser($user->id)
            ->inList($list)->find((int) request('row_id'));
        if ($row) {
            foreach (['quantity','price_paid','acquired_on','condition','nib_grind','serial_no','notes','status'] as $f) {
                if (request()->has($f)) {
                    $row->{$f} = request($f) === '' ? null : request($f);
                }
            }
            $row->save();
        }
        $back = (string) request('return');
        if ($back === '' || $back[0] !== '/' || substr($back, 0, 2) === '//') { $back = '/favorites'; }
        return \Illuminate\Support\Facades\Redirect::to($back);
    });

    \Illuminate\Support\Facades\Route::get('favorites/bought', function () {
        $user = \Auth::getUser();
        if (!$user) { return \Illuminate\Support\Facades\Redirect::to('/login'); }
        \Niainteractive\PenCatalog\Models\MemberPen::markBought($user->id, (int) request('row_id'));
        $back = (string) request('return');
        if ($back === '' || $back[0] !== '/' || substr($back, 0, 2) === '//') { $back = '/favorites'; }
        return \Illuminate\Support\Facades\Redirect::to($back);
    });

    \Illuminate\Support\Facades\Route::post('ink-log/add', function () {
        $user = \Auth::getUser();
        if (!$user) { return \Illuminate\Support\Facades\Redirect::to('/login'); }
        $itemId = (int) request('item_id');
        $ownsIt = \Niainteractive\PenCatalog\Models\MemberPen::forUser($user->id)
            ->inList('owned')->where('item_id', $itemId)->exists();
        if ($ownsIt) {
            $e = new \Niainteractive\PenCatalog\Models\InkLogEntry();
            $e->user_id = $user->id;
            $e->item_id = $itemId;
            $e->ink_brand = request('ink_brand');
            $e->ink_name = request('ink_name');
            $e->ink_color = request('ink_color');
            $e->filled_on = request('filled_on') ?: date('Y-m-d');
            $e->notes = request('notes');
            $e->save();
        }
        return \Illuminate\Support\Facades\Redirect::to('/ink-log');
    });

    \Illuminate\Support\Facades\Route::get('ink-log/clean', function () {
        $user = \Auth::getUser();
        if (!$user) { return \Illuminate\Support\Facades\Redirect::to('/login'); }
        $row = \Niainteractive\PenCatalog\Models\InkLogEntry::forUser($user->id)->find((int) request('row_id'));
        if ($row) { $row->cleaned_on = date('Y-m-d'); $row->save(); }
        return \Illuminate\Support\Facades\Redirect::to('/ink-log');
    });

    \Illuminate\Support\Facades\Route::get('ink-log/remove', function () {
        $user = \Auth::getUser();
        if (!$user) { return \Illuminate\Support\Facades\Redirect::to('/login'); }
        \Niainteractive\PenCatalog\Models\InkLogEntry::forUser($user->id)->where('id', (int) request('row_id'))->delete();
        return \Illuminate\Support\Facades\Redirect::to('/ink-log');
    });
});
