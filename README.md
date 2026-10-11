# Pen Catalog (Winter CMS plugin)

A backend-managed catalog of fountain pens — makes, terms (vocabularies), purchase offers
and photos — with a sortable, filterable front-end table and a detail page per pen.

## Design

Every descriptive facet (nationality, shape, colour, material, nib size/material, ink system)
is a **term** inside a **term group**. Backend users can add new groups and terms without any
code change, which is what keeps the plugin useful beyond fountain pens.

## Licence

GPL v3 (see LICENSE).

## API (for an external lookup agent)

Token-authenticated, scoped, and revocable. No cPanel, SSH, shell or database access is needed —
and the caller never touches the filesystem, because photos are posted as bytes.

**Setup.** Settings → Pen Catalog → *Enable the catalogue API*, and set *API may create and edit
records* on or off (off = read-only). The token is shown there; clear the field to revoke.

**Auth.** `Authorization: Bearer <token>` on every call.

> The web server must pass the Authorization header through to PHP. The site's `.htaccess`
> carries the required rewrite (`HTTP_AUTHORIZATION`); without it every call returns 401 because
> Apache strips the header under FPM. `X-Api-Token: <token>` also works as a fallback.

| Method | Path | Purpose |
|---|---|---|
| GET  | `/api/pencatalog/ping` | Liveness, whether the token can write |
| GET  | `/api/pencatalog/vocabularies` | Makes, term groups + valid terms, vendors, rating scale |
| GET  | `/api/pencatalog/pens` | List (`?make=`, `?updated_since=`, `?limit=`) |
| GET  | `/api/pencatalog/pens/{slug}` | One pen with terms, photos and offers |
| POST | `/api/pencatalog/pens` | Create or update a pen (idempotent on make + model) |
| POST | `/api/pencatalog/photos` | Attach a photo — multipart `image` file, or `url` to fetch |

**Vocabulary is authoritative.** An unknown make, term or vendor is refused with the list of valid
values rather than silently created, because invented vocabulary wrecks filterability. Pass
`create_missing_make` / `create_missing_terms` to override deliberately.

```bash
TOKEN=...
curl -H "Authorization: Bearer $TOKEN" https://HOST/api/pencatalog/vocabularies

# file a pen
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"make":"Pilot","model":"Custom 823","rating":9.5,
       "terms":{"ink-system":["Vacuum"],"nib-size":["Medium"]},
       "offers":[{"vendor":"JetPens","price":288.5,"url":"https://..."}]}' \
  https://HOST/api/pencatalog/pens

# photo as bytes (no filesystem access)
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -F "slug=pilot-custom-823" -F "image=@photo.jpg" https://HOST/api/pencatalog/photos

# or let the server fetch it
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"slug":"pilot-custom-823","url":"https://example.com/photo.jpg"}' \
  https://HOST/api/pencatalog/photos
```

Photos land in `storage/app/media/pens` as `<slug>-<number>.<ext>` and are immediately live.
Every write is appended to `storage/logs/pencatalog_api.log` with the caller's IP.
