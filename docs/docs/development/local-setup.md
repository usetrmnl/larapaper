---
title: Local setup
description: Run LaraPaper from source on your machine.
---

# Local setup

## Clone and configure

```bash
git clone git@github.com:usetrmnl/larapaper.git
cd larapaper
cp .env.example .env
```

## Install dependencies

```bash
composer install
npm i
npm run build
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```

## Run the server

Expose the app on your LAN:

```bash
php artisan serve --host=0.0.0.0 --port=4567
```

Open `http://localhost:4567` and sign in with `admin@example.com` / `admin@example.com`.

## After pulling updates

```bash
composer install
npm i
npm run build
```

See also [Docker](/development/docker) and [Devcontainer](/development/devcontainer).
