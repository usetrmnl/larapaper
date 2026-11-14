---
title: Environment variables
description: LaraPaper environment configuration reference.
---

# Environment variables

| Variable | Description | Default |
| --- | --- | --- |
| `TRMNL_PROXY_BASE_URL` | Base URL of the native TRMNL service | `https://trmnl.app` |
| `TRMNL_PROXY_REFRESH_MINUTES` | How often to fetch new images from the cloud service | `15` |
| `MULTIUSER_REGISTRATION_ENABLED` | After the first account, allow more signups and disable user-authored Blade. Trusted environments only (family, friends) | `0` |
| `INSECURE_BLADE_ALLOW_PHP_EXECUTION` | Force compiling user-authored Blade as PHP on (`1`) or off (`0`). Unset: on for a single user, off for multi-user | auto |
| `PASSKEYS_ENABLED` | Enable passkeys (requires HTTPS). See [Authentication](/getting-started/authentication#passkeys) | `0` |
| `SSL_MODE` | SSL mode when not behind a reverse proxy ([docs](https://serversideup.net/open-source/docker-php/docs/customizing-the-image/configuring-ssl)) | `off` |
| `FORCE_HTTPS` | Enforce HTTPS when the server terminates SSL | `0` |
| `TRUSTED_PROXIES` | Trusted proxy CIDRs, e.g. `"172.0.0.0/8"` or `*`; also needed to [serve under a sub-path](/getting-started/installation#serving-under-a-sub-path) | `null` |
| `PHP_OPCACHE_ENABLE` | Enable PHP OPcache | `0` |
| `TRMNL_IMAGE_URL_TIMEOUT` | Display endpoint response timeout (seconds) | `30` |
| `HTTP_CLIENT_TIMEOUT` | Outbound HTTP timeout when fetching recipe polling URLs (seconds) | `10` |
| `APP_TIMEZONE` | PHP timezone (UTC recommended) | `UTC` |
| `TOGGLE_MCP` | Default for the MCP Server lab flag | `false` |
| `TRANSFORM_PROVIDER` | Set to `http` to enable [transforms](/usage/transforms) on an external runner | `null` |
| `TRANSFORM_RUNNER_URL` | Base URL of the transform runner | `http://transform:3000` |
| `TRANSFORM_RUNNER_TOKEN` | Optional bearer token sent to the runner | `null` |
| `TRANSFORM_TIMEOUT_SECONDS` | Max seconds for one transform | `5` |

## Browser assets

Framework CSS and JavaScript for recipe screens can be loaded from a mirror or local server. Publish the framework’s `/css` and `/js` paths on that host; recipe `framework_version` still selects the version segment in the URL.

| Variable | Description | Default |
| --- | --- | --- |
| `TRMNL_BLADE_FRAMEWORK_BASE_URL` | Base URL for versioned framework assets (`plugins.css`, `plugins.js`) | `https://trmnl.com` |
| `TRMNL_BLADE_FRAMEWORK_CSS_URL` | Override the framework stylesheet URL | `null` |
| `TRMNL_BLADE_FRAMEWORK_JS_URL` | Override the framework JavaScript URL | `null` |
| `TRMNL_BLADE_HIGHCHARTS_JS_URL` | Highcharts script URL | `https://trmnl.com/js/highcharts/12.3.0/highcharts.js` |
| `TRMNL_BLADE_CHARTKICK_JS_URL` | Chartkick script URL | `https://trmnl.com/js/chartkick/5.0.1/chartkick.min.js` |

Explicit stylesheet and JavaScript URLs take precedence over the base URL and per-recipe framework version.

Recipe render contexts expose the chart URLs as `trmnl.assets.highcharts_js_url` and `trmnl.assets.chartkick_js_url`, and the bundled pollen recipe uses them. Inline Blade and Liquid markup, including markup rendered by the external Liquid renderer, receive the same context. Recipes duplicated from the pollen recipe in earlier versions of LaraPaper contain literal trmnl.com script URLs. They keep loading the scripts from trmnl.com until those URLs are replaced with the `trmnl.assets` variables.
## OpenID Connect

Optional sign-in through an OpenID Connect provider. See [Authentication](/getting-started/authentication).

The redirect URI is `{APP_URL}/auth/oidc/callback`. It is not a separate variable.

| Variable | Description | Default |
| --- | --- | --- |
| `OIDC_ENABLED` | Enable the OIDC login button and callback | `false` |
| `OIDC_ENDPOINT` | Provider base URL, or the full well-known configuration URL | |
| `OIDC_CLIENT_ID` | OAuth client ID | |
| `OIDC_CLIENT_SECRET` | OAuth client secret | |
| `OIDC_SCOPES` | Comma-separated scopes | `openid,profile,email` |

## Security

LaraPaper is built for a single user on a VPS or in a homelab. Multi-user mode is for trusted environments only, like family and friends. Full isolation between users is a non-goal, so multi-user mode is not a security boundary.

The first account can always be created. After that, signup stays closed unless `MULTIUSER_REGISTRATION_ENABLED` is on.

User-authored Blade templates are compiled to PHP. In single-user mode that stays available, because the only account is the person running the server. In multi-user mode Blade is not compiled unless `INSECURE_BLADE_ALLOW_PHP_EXECUTION=1`. Setting `INSECURE_BLADE_ALLOW_PHP_EXECUTION=0` disables Blade in single-user mode as well. Turning the flag on means any registered user can execute PHP as the web server user. That includes reading `.env`, configuration, and other users' data. Liquid templates stay available either way.
