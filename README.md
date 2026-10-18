# Pen Catalog (Winter CMS plugin)

A backend-managed catalog of fountain pens - makes, terms (vocabularies), purchase offers
and photos - with a sortable, filterable front-end table and a detail page per pen.

## Design

Every descriptive facet (nationality, shape, colour, material, nib size/material, ink system)
is a **term** inside a **term group**. Backend users can add new groups and terms without any
code change, which is what keeps the plugin useful beyond fountain pens.

## Licence

GPL v3 (see LICENSE).

## API (for an external lookup agent)

Token-authenticated, scoped, and revocable. No cPanel, SSH, shell or database access is needed -
and the caller never touches the filesystem, because photos are posted as bytes.

**Setup.** Settings -> Pen Catalog -> *Enable the catalogue API*, and set *API may create and edit
records* on or off (off = read-only). The token is shown there; clear the field to revoke.

**Auth.** `Authorization: Bearer <token>` on every call. `X-Api-Token: <token>` also works.

> The web server must pass the Authorization header through to PHP. The site's `.htaccess`
> carries the required rewrite (`HTTP_AUTHORIZATION`); without it every call returns 401 because
> Apache strips the header under FPM.

`GET /ping` lists every endpoint, so a caller can discover the surface without reading this file.

### Endpoints

All paths are relative to `/api/pencatalog`. Everything except the GETs needs the write switch on.

| Method | Path | Purpose |
|---|---|---|
| GET  | `/ping` | Liveness, version, whether the token can write, endpoint list |
| GET  | `/vocabularies` | Makes (with notes), term groups + valid terms, vendors, rating scale |
| GET  | `/pens` | List (`?make=`, `?updated_since=`, `?limit=`) |
| GET  | `/pens/{slug}` | One pen with terms, photos and offers |
| POST | `/pens` | Create or update a pen (idempotent on make + model) |
| POST | `/photos` | Attach a photo - multipart `image` file, or `url` to fetch |
| GET  | `/makes` | Every make with notes, website, country, active flag, pen count |
| POST | `/makes` | Add or edit a make - **this is where a brand-level note goes** |
| GET  | `/vendors` | Every vendor with notes and website |
| POST | `/vendors` | Add or edit a vendor |
| GET  | `/terms` | Every term group and term, with each term's note |
| POST | `/terms` | Add or edit a term inside a group |
| GET  | `/term-groups` | Every group with its type and term count |
| POST | `/term-groups` | Add or edit a group (single-select or multi) |
| GET  | `/item-types` | Product types (pen, ink, paper ...) |
| POST | `/item-types` | Add or edit a product type |

### Writing the vocabulary

The read side says what exists; the write side is how new values get in. Every write is an
**upsert matched on slug or exact name**, so sending the same name twice edits the record
rather than duplicating it.

```bash
TOKEN=...
H=https://HOST/api/pencatalog

# a brand-level note (makes have a notes column; so do vendors)
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Delike","country":"Chinese",
       "notes":"Sells under several names; the New Moon and Alpha share a section."}' \
  $H/makes

# a vendor
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Bertram'"'"'s Inkwell","website":"https://bertramsinkwell.com","notes":"..."}' \
  $H/vendors

# a term: "group" is required, and must already exist unless create_missing_group is set
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"group":"nationality","name":"Turkish","notes":"Scrikss and other Istanbul makers"}' \
  $H/terms
```

Field names, per kind:

| Kind | Fields |
|---|---|
| make | `name`, `slug`, `website`, `notes`, `country` (a nationality term), `is_active` |
| vendor | `name`, `slug`, `website`, `notes`, `is_active` |
| term | `group` (required), `name`, `slug`, `notes` (alias `description`), `color`, `sort_order`, `is_active` |
| term-group | `name`, `slug`, `notes`, `single_select`, `sort_order`, `is_active` |
| item-type | `name`, `slug`, `notes`, `sort_order`, `is_active` |

`country` on a make and `group` on a term are the two places a term name is expected; both accept
the name or the slug, and both accept `create_missing_terms` to add the term on the way through.

**Retiring a value.** Do not try to delete a term or make that pens already point at - send
`is_active: false` instead. It stops appearing in filters and pickers without leaving a dangling
reference. Deleting is a backend job for values nothing uses.

### Terms on a pen: add, replace, remove

```bash
# add (default) - everything listed is attached, whatever is already there stays
{"make":"Pilot","model":"Custom 823","terms":{"ink-system":["Vacuum"]}}

# replace - the groups you list become EXACTLY what you list.
# Groups you do not mention are left alone; an empty list clears that group.
{"make":"Scrikss","model":"419","terms_mode":"replace","terms":{"nationality":["Turkish"]}}

# remove just these, change nothing else
{"make":"Scrikss","model":"419","terms_remove":{"nationality":["Other"]}}
```

Use `terms_mode: replace` (or `terms_remove`) to **correct** a value - for example when a pen was
filed as `["Other", "Turkish"]` and "Other" should not be there. Plain `terms` is add-only by
design, so it can never drop data by accident.

The parameters are `terms`, `terms_mode`, `terms_remove` and `create_missing_terms`. Anything else
containing "term" is refused with a 422 naming these, rather than being half-applied.

Every successful write returns `changes.terms_now` - the pen's full term set after the call, so a
caller can confirm the result without a second request.

### Unknown vocabulary is refused

An unknown make, term or vendor returns **422 with the list of valid values** instead of being
silently created, because invented vocabulary wrecks filterability. Pass `create_missing_make` /
`create_missing_terms` to override deliberately. Where possible, add the value properly first
(`POST /makes`, `POST /terms`) so it arrives with its note attached.

### Photos

Photos land in `storage/app/media/pens` as `<slug>-<number>.<ext>` and are immediately live.

```bash
# bytes (no filesystem access needed)
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -F "slug=pilot-custom-823" -F "caption=..." -F "image=@photo.jpg" $H/photos

# or let the server fetch it
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"slug":"pilot-custom-823","url":"https://example.com/photo.jpg"}' $H/photos
```

Keep images **around 1200px on the long edge**. There is no server-side resizing, and oversized
photos are the single biggest drag on page speed. JPG, PNG, WebP and GIF are accepted; 8 MB max.

Every write is appended to `storage/logs/pencatalog_api.log` with the caller's IP.

### Videos

A pen can carry video reviews. The `videos` field takes embed code - on YouTube or Vimeo that is
Share, then Embed - with **one or more videos per pen**, and the detail page prints them at the
bottom in the order given.

A bare YouTube, Vimeo, Dailymotion or Bilibili link on its own line is turned into a player, so a
caller that found a review only has to send the URL:

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"make":"Pilot","model":"Custom 823",
       "videos":"https://www.youtube.com/watch?v=dQw4w9WgXcQ\nhttps://vimeo.com/347119375"}' \
  $H/pens
```

`GET /pens/{slug}` returns the stored text plus `video_count`, so a caller can check the field
survived without loading the page.

**Use the slug the API returns, do not rebuild it.** The slug is generated from make + model, and a
model containing a parenthetical or extra words produces a longer slug than you might expect -
`"model": "V60 (Triangular)"` yields `majohn-v60-triangular`, not `majohn-v60`. Every write response
includes the real `slug`; posting a photo to a slug you reconstructed yourself fails with a 404 and
looks like the photo endpoint is broken.

Only player markup survives this field: scripts, `on*` handlers, and `javascript:`/`data:` URLs are
stripped, because it is the one field the public detail page prints unescaped. The consequence is
that embeds which build their player with JavaScript - X/Twitter, Instagram, Facebook - will not
work. Send an iframe-style embed or a plain link instead. Bare YouTube links become
youtube-nocookie players, and every player is marked `loading="lazy"` so a page with several
videos does not fetch them all at once.

### Measurements are four separate numeric fields

The item form holds dimensions as numbers, and the API takes them under these exact names:

| Field | Meaning |
|---|---|
| `length_closed` | capped length, in millimetres, one decimal |
| `length_uncapped` | uncapped length, in millimetres |
| `length_posted` | posted length, in millimetres |
| `weight` | grams |

**There is no `length` field.** Sending `length` is not an error and does not return 422 - the write
succeeds, the key is ignored, and the pen saves with no measurement. It reads afterwards as though
the data was never entered. The lesson generalises: a 200/201 with `"ok": true` only tells you the
fields the API recognised, which its `changes` block lists. Check the field name before concluding
that a write failed, and read the record back rather than trusting the response.

A pen created with only `make` and `model` shows as a near-empty row everywhere - blanks are
missing data, not a bug. Always pull `/vocabularies` first and send `terms`, `rating` and `offers`
with the pen. Terms must match the vocabulary exactly; `nationality`, `material`, `shape`,
`nib-size`, `nib-material`, `ink-system` and the single-select `nib-swappable` are the groups to
fill. Rating is 1-10 in half points.

### Member lists and the ink log (1.0.8)

Signed-in members keep two private lists against the catalogue: favorites and owned
(`member_pens`, one row per `(user, item, list)`, so a pen can be both wanted and owned).
The catalogue remains the source of truth for what a pen *is*; the member row holds only
their relationship to it — price paid, date acquired, nib, notes. Deleting a catalogue
item therefore strands member rows, which is why unpublishing is preferred.

State changes go through plain routes in `routes.php` rather than the AJAX framework:

| Route | Does |
|---|---|
| `GET favorites/add` · `GET favorites/remove` | add or remove in either list |
| `GET favorites/bought` | move a favorites row onto the owned list, folding quantities if already owned |
| `POST favorites/update` | save the per-row fields (needs `_token`) |
| `POST ink-log/add` · `GET ink-log/clean` · `GET ink-log/remove` | fill and clean entries |

Two gotchas worth keeping in mind:

- **`\Auth::getUser()`, not `\Auth::guard('web')`.** Winter's user plugin swaps in its own
  `AuthManager`, which has no `guard()` method; calling it throws. The same manager has no
  `check()` either. `getUser()` is the one that works.
- **The AJAX framework was not usable here.** Snowboard sends no CSRF token, so a
  `data-request` button failed silently. Links plus one POST form with a rendered `_token`
  cover the same ground, work with JavaScript off, and are testable with curl. The one
  exception is the optimistic star/plus toggle in the layout, which paints instantly and
  fires the same URL with `fetch()`; if the request fails it reverts.

The ink log is one row per fill, "current" while `cleaned_on` is null — so the same table
answers both "what is inked right now" and "what have I used", and cleaning a pen is a
date stamp rather than a move between tables.
