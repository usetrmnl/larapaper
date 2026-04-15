---
title: Hosting
description: Deploy LaraPaper with Docker Compose or other hosting options.
---

# Hosting

LaraPaper runs anywhere Docker is supported: Raspberry Pi, VPS, NAS, or container platforms (Cloud Run, etc.).

## Docker Compose (recommended)

Use the production compose file at [docker/prod/docker-compose.yml](https://github.com/usetrmnl/larapaper/blob/main/docker/prod/docker-compose.yml).

For production, generate an application key and set it in your environment:

```bash
php artisan key:generate --show
```

Set `APP_KEY=` in your environment. For personal use you can disable registration (see [Environment variables](/getting-started/environment-variables)).

### Backup database

```bash
docker ps   # find the LaraPaper container id
docker cp {{CONTAINER_ID}}:/var/www/html/database/storage/database.sqlite database_backup.sqlite
```

### Update

```bash
docker compose pull
docker compose down
docker compose up -d
```

## Other hosting

| Option | Notes |
| --- | --- |
| **VPS + Dokploy** | [Template search](https://templates.dokploy.com/?q=trmnl+byos+laravel) for quick deploy without manual Docker setup. |
| **PikaPods** | Vote for a template: [feedback.pikapods.com](https://feedback.pikapods.com/posts/842/add-app-trmnl-byos-laravel) |
| **Umbrel** | Community store: [umbrel-store](https://github.com/bnussbau/umbrel-store) |
| **Laravel Forge / bare metal** | PHP 8.4+, Nginx or Apache, see [Requirements](/getting-started/requirements). |

## First login

- **Local:** open `http://localhost:4567` and sign in with `admin@example.com` / `admin@example.com` (after seeding).
- **Production:** register a user, or set `REGISTRATION_ENABLED=0` to disable public registration.
