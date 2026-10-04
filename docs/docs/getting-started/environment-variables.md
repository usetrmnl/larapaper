---
title: Environment variables
description: LaraPaper environment configuration reference.
---

# Environment variables

| Variable | Description | Default |
| --- | --- | --- |
| `TRMNL_PROXY_BASE_URL` | Base URL of the native TRMNL service | `https://trmnl.app` |
| `TRMNL_PROXY_REFRESH_MINUTES` | How often to fetch new images from the cloud service | `15` |
| `REGISTRATION_ENABLED` | Allow registration via the web UI | `1` |
| `PASSKEYS_ENABLED` | Enable passkeys (requires HTTPS) | `0` |
| `SSL_MODE` | SSL mode when not behind a reverse proxy ([docs](https://serversideup.net/open-source/docker-php/docs/customizing-the-image/configuring-ssl)) | `off` |
| `FORCE_HTTPS` | Enforce HTTPS when the server terminates SSL | `0` |
| `TRUSTED_PROXIES` | Trusted proxy CIDRs, e.g. `"172.0.0.0/8"` or `*` | `null` |
| `PHP_OPCACHE_ENABLE` | Enable PHP OPcache | `0` |
| `TRMNL_IMAGE_URL_TIMEOUT` | Display endpoint response timeout (seconds) | `30` |
| `HTTP_CLIENT_TIMEOUT` | Outbound HTTP timeout when fetching recipe polling URLs (seconds) | `10` |
| `APP_TIMEZONE` | PHP timezone (UTC recommended) | `UTC` |

## Browser assets

Framework assets can be loaded from a local server or a mirror. Publish the
framework's `/css`, `/js`, `/fonts` and `/images` paths on that server; framework
stylesheets can refer to fonts and images through root-relative URLs.

| Variable | Description | Default |
| --- | --- | --- |
| `TRMNL_BLADE_FRAMEWORK_BASE_URL` | Base URL for versioned framework assets | `https://trmnl.com` |
| `TRMNL_BLADE_FRAMEWORK_CSS_URL` | Override the framework stylesheet URL | `null` |
| `TRMNL_BLADE_FRAMEWORK_JS_URL` | Override the framework JavaScript URL | `null` |
| `TRMNL_BLADE_HIGHCHARTS_JS_URL` | Highcharts script URL | `https://trmnl.com/js/highcharts/12.3.0/highcharts.js` |
| `TRMNL_BLADE_CHARTKICK_JS_URL` | Chartkick script URL | `https://trmnl.com/js/chartkick/5.0.1/chartkick.min.js` |
| `TRMNL_BLADE_HIGHCHARTS_PATTERN_FILL_URL` | Highcharts pattern-fill script URL | `https://trmnl.com/js/highcharts/12.3.0/pattern-fill.js` |
| `TRMNL_BLADE_MAPLIBRE_JS_URL` | MapLibre script URL | `https://trmnl.com/js/maplibre-gl/5.24.0/maplibre-gl.js` |
| `TRMNL_BLADE_MAPLIBRE_CSS_URL` | MapLibre stylesheet URL | `https://trmnl.com/js/maplibre-gl/5.24.0/maplibre-gl.css` |

Recipes retain their selected framework version when the base URL changes.
Explicit stylesheet and JavaScript URLs take precedence over that version.

Recipe render contexts expose the chart URLs as `trmnl.assets.highcharts_js_url`
and `trmnl.assets.chartkick_js_url`. The bundled pollen recipe uses these values.
The same context is available to inline Blade and Liquid markup, including the
external Liquid renderer. Existing saved templates with literal script URLs
keep those URLs until the template is edited.
