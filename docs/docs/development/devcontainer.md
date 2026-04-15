---
title: Devcontainer
description: Develop LaraPaper inside a VS Code Dev Container.
---

# Devcontainer

Open the repository in VS Code with the **Dev Containers** extension. The devcontainer builds automatically and starts the app.

## Setup inside the container

```bash
cp .env.example.local .env
php artisan migrate --seed
php artisan storage:link
```

Open the **Ports** tab and use the forwarded address in your browser. Sign in with `admin@example.com` / `admin@example.com`.

## After pull

```bash
composer install
npm i
npm run build
```
