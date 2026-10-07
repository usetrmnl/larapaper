---
title: Generating screens
description: Create and update TRMNL screens via markup, Blade, or API.
---

# Generating screens

## Markup (web UI)

1. Go to **Plugins → Markup**.
2. Enter markup or pick a template.
3. Save and apply.

Blade components: [laravel-trmnl-blade](https://github.com/bnussbau/laravel-trmnl-blade/tree/main/resources/views/components).

## Blade view

1. Edit `resources/views/trmnl.blade.php`.
2. Generate the screen:

```bash
php artisan trmnl:screen:generate
```

## API

`POST /api/screen` with header `Authorization: Bearer <TOKEN>`:

```json
{
  "markup": "<h1>Hello World</h1>"
}
```

## Fonts

Inter fonts are included in the frontend build through [Fontsource]. Run
`npm ci` and `npm run build` to produce the font stylesheet and files. Screens
load the font stylesheet separately from the admin UI styles.

HTML image rendering keeps the browser's file origin. Set `APP_URL` to the
application URL that the renderer can access. The asset server must permit
cross-origin font requests from the file origin, which sends `Origin: null`.
This requirement also applies when `ASSET_URL` specifies a separate server.

[Fontsource]: https://fontsource.org/fonts/inter
