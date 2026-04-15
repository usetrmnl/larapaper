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
| `APP_TIMEZONE` | PHP timezone (UTC recommended) | `UTC` |
