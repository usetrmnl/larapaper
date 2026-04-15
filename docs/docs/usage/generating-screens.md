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
