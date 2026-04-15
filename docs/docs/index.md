---
title: LaraPaper
description: Self-hostable TRMNL BYOS built with Laravel.
---

# LaraPaper

LaraPaper is a self-hostable implementation of a TRMNL server (BYOS), built with Laravel. Manage TRMNL devices, generate screens with **native plugins**, **recipes** (from the [community catalog](https://bnussbau.github.io/trmnl-recipe-catalog/) or [TRMNL catalog](https://trmnl.com/recipes)), or the **API**, and optionally act as a **proxy** for the native cloud service.

[![tests](https://github.com/usetrmnl/larapaper/actions/workflows/test.yml/badge.svg)](https://github.com/usetrmnl/larapaper/actions/workflows/test.yml)

## Key features

- **Device information** — Battery, WiFi, firmware, and more.
- **Auto-join** — Detect and add devices on your local network.
- **Screen generation** — Plugins (including mashups), recipes, API, markup, or code updates.
- **TRMNL Design Framework** — [trmnl.com/framework](https://trmnl.com/framework)
- **Recipe catalogs** — [Community](https://bnussbau.github.io/trmnl-recipe-catalog/) and [TRMNL](https://trmnl.com/recipes) imports.
- **Supported hardware** — TRMNL OG & X, SeeedStudio DIY kits, reTerminal E1001, custom ESP32 firmware, e-readers (KOReader, Kindle, Nook, Kobo), [trmnl-android](https://github.com/usetrmnl/trmnl-android), [trmnl-display](https://github.com/usetrmnl/trmnl-display) on Raspberry Pi.
- **TRMNL API proxy** — Hybrid local + cloud setups (Developer Edition).
- **Dark mode** in the web UI.
- **Docker** — Production `docker-compose` and devcontainer support.
- **Database** — SQLite by default; MySQL and PostgreSQL supported.

## How it works

A device calls `/api/display`. LaraPaper renders the selected screen with Puppeteer, converts the result to a device-specific PNG via ImageMagick, and the device downloads and displays that image.

## Next steps

::: card "Get started"
Deploy LaraPaper with Docker or run it locally for development.
::: button "Installation" /getting-started/installation
::: button "Local development" /development/local-setup
:::

## Related projects

- [epaper-pipeline-php](https://github.com/bnussbau/epaper-pipeline-php) — Browser rendering and image conversion with TRMNL Models API support.
- [laravel-trmnl-blade](https://github.com/bnussbau/laravel-trmnl-blade) — Blade components for the TRMNL design system.
- [trmnl-recipe-catalog](https://github.com/bnussbau/trmnl-recipe-catalog) — Community recipe catalog.
