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
