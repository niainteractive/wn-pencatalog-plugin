# Front-end pages

The plugin ships the components and the routes, but **not the pages** — pages belong
to your theme. This file lists every page the front end needs, with copy-paste stubs.
Styling is left to your theme; the stubs use no CSS classes at all.

## Before you start

1. Install and enable the plugin:

   ```bash
   composer require niainteractive/wn-pencatalog-plugin
   php artisan winter:up
   ```

   Installing from a folder or zip instead? Put it at
   `plugins/niainteractive/pencatalog`, then run `php artisan winter:up` to create
   the tables and seed the vocabularies.

2. **Member pages need `Winter.User`** (`winter/wn-user-plugin`). The catalogue itself
   works without it: browsing, filtering and pen detail are unaffected. Without it,
   nothing can sign in, so `/mypens`, `/wishlist`, `/gifts`, `/ink-log`, `/account`
   and `/profile` have no one to serve.

   ```bash
   composer require winter/wn-user-plugin
   php artisan winter:up
   ```

3. Make sure `storage/app/media` is writable by the web server. Pen photos live in
   `storage/app/media/pens`, member uploads in `storage/app/media/mypens`.

## The pages

| URL | File | Components |
|---|---|---|
| `/pens` | `pens.htm` | `catalogTable`, optional `suggestPen` |
| `/pen/:slug` | `pen.htm` | `itemDetail` |
| `/makes` | `makes.htm` | `makesIndex` |
| `/members` | `members.htm` | none required; optional `suggestPen` |
| `/login` | `login.htm` | `account` |
| `/register` | `register.htm` | `account` |
| `/reset-password` | `reset-password.htm` | `resetPassword` |
| `/account` | `account.htm` | `account`, `session` |
| `/profile` | `profile.htm` | `account`, `session` |
| `/mypens` | `mypens.htm` | `memberList` (`list = "owned"`), `session` |
| `/wishlist` | `wishlist.htm` | `memberList` (`list = "wish"`), `session` |
| `/gifts` | `gifts.htm` | `memberList` (`list = "gift"`), `session` |
| `/ink-log` | `ink-log.htm` | `inkLog`, `session` |

**The plugin's own routes need no page.** `/favorites/*`, `/ink-log/*` and
`/member-photo/*` are registered by the plugin, as are the API routes under
`/api/pencatalog/*`.

`[session]` is Winter's session component. `security="user"` sends a signed-out
visitor to the login page instead of showing an empty list.

## Copy-paste stubs

Each stub is the front matter followed by `==` and the page body, exactly as the CMS
editor stores it. Rename the layout to one that exists in your theme.

### pens.htm

```twig
url = "/pens"
title = "Pens"
layout = "default"

[catalogTable]
perPage = 50
defaultSort = "model"
defaultDirection = "asc"
baseUrl = "/pens"

[suggestPen]
==
<h1>Pens</h1>

{% component 'catalogTable' %}

{% component 'suggestPen' %}
```

`catalogTable` properties:

| Property | Default | Notes |
|---|---|---|
| `perPage` | settings (`rows_per_page`) | rows per page |
| `defaultSort` | `model` | `model`, `brand`, `rating`, `inspiration`, `weight` |
| `defaultDirection` | `asc` | |
| `baseUrl` | current page | set it when the table is not the only thing on the page |

Leave `[suggestPen]` out if you do not want members suggesting pens.

### pen.htm

```twig
url = "/pen/:slug"
title = "Pen"
layout = "default"

[itemDetail]
==
{% component 'itemDetail' %}
```

### makes.htm

```twig
url = "/makes"
title = "Makes"
layout = "default"

[makesIndex]
==
{% component 'makesIndex' %}
```

### Members area

All six member pages need `[session]` with the same two settings:

```twig
[session]
security = "user"
redirect = "login"
```

**login.htm**

```twig
url = "/login"
title = "Sign in"
layout = "default"

[account]
redirect = "account"
==
{% component 'account' %}
```

**register.htm**

```twig
url = "/register"
title = "Create an account"
layout = "default"

[account]
redirect = "account"
==
{% component 'account' %}
```

**reset-password.htm**

```twig
url = "/reset-password"
title = "Reset your password"
layout = "default"

[resetPassword]
==
{% component 'resetPassword' %}
```

**account.htm**

```twig
url = "/account"
title = "Your account"
layout = "default"

[account]

[session]
security = "user"
redirect = "login"
==
{% component 'account' %}
```

**profile.htm** — the same page with `[account]` and `[session]`.

**mypens.htm**

```twig
url = "/mypens"
title = "My Pens"
layout = "default"

[memberList]
list = "owned"

[session]
security = "user"
redirect = "login"
==
{% component 'memberList' %}
```

**wishlist.htm** — same, `list = "wish"`.

**gifts.htm** — same, `list = "gift"`.

**ink-log.htm**

```twig
url = "/ink-log"
title = "Ink log"
layout = "default"

[inkLog]

[session]
security = "user"
redirect = "login"
==
{% component 'inkLog' %}
```

### Optional: members.htm

A landing page for the member area. `[suggestPen]` is the only component it needs;
add sign-in and register links in your own markup.

```twig
url = "/members"
title = "Members"
layout = "default"

[suggestPen]
==
<p><a href="{{ 'login'|page }}">Sign in</a> or
   <a href="{{ 'register'|page }}">create an account</a>.</p>

{% component 'suggestPen' %}
```

## Menus

Add `/pens`, `/makes`, `/members` and `/contact` to the main menu. The member pages
(`/mypens`, `/wishlist`, `/gifts`, `/ink-log`, `/account`, `/profile`) are best kept in
a dropdown: the session component already sends guests to the login page, so they do
not need hiding.

## Settings

**Settings → Pen Catalog**:

| Setting | Purpose |
|---|---|
| Require a front-end login to view the catalog | guests get the sign-in page instead of the table |
| Show vendor offers | prints offers on the detail page |
| Default currency | used when a pen has no currency of its own |
| Rows per page | the `catalogTable` default |
| Enable the catalogue API | off by default; see the API section of the README |
| API may create and edit | writes stay drafts until published in the backend |

## Backend permissions

Give these to the people who need them; all are under the **Pen Catalog** tab:

| Permission | Grants |
|---|---|
| `niainteractive.pencatalog.manage_items` | pens, photos, publish |
| `niainteractive.pencatalog.manage_vocabularies` | makes, term groups, terms, item types |
| `niainteractive.pencatalog.manage_vendors` | vendors and offers |
| `niainteractive.pencatalog.import_export` | CSV import and export |
| `niainteractive.pencatalog.manage_suggestions` | the suggestion queue and the demand report |
| `niainteractive.pencatalog.access_settings` | settings, including the API token |

A catalogue editor needs `manage_items` and, if they maintain brands, `manage_vocabularies`.
Do **not** give `access_settings` to anyone who does not need the API token.

## Verifying the deployment

```bash
curl -o /dev/null -s -w '%{http_code}\n' https://example.com/pens        # 200
curl -o /dev/null -s -w '%{http_code}\n' https://example.com/pen/pilot-custom-823  # 200
curl -o /dev/null -s -w '%{http_code}\n' https://example.com/mypens      # 302 to /login
curl -o /dev/null -s -w '%{http_code}\n' https://example.com/backend     # 302
```

A 500 on `/pens` usually means the theme page is missing `[catalogTable]`, or the
plugin is enabled but `winter:up` has not run since it was installed.
