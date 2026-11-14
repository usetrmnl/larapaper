---
title: Pre-render playlist items
description: Refresh the cache of the next playlist item before a device is expected to fetch it.
---

# Pre-render playlist items

When a TRMNL device asks for its next screen, LaraPaper normally renders or refreshes the playlist item’s cached image during that request. With this lab experiment enabled, LaraPaper **predicts** which devices will call next and queues image generation ahead of time so the cache is ready when the device connects.

This does **not** change which screen the device shows. It only prepares the plugin image cache early.

## Enable

1. Sign in as the **first user**.
2. Go to **Settings → Lab** and turn on **Pre-render playlist items**.

You can also set `TOGGLE_PRERENDER_PLAYLIST_ITEMS=true` instead of using the Lab UI. See [Environment variables](/getting-started/environment-variables).

## Requirements

This feature relies on two background processes that must already be running for other LaraPaper jobs:

1. **Laravel scheduler** — `php artisan schedule:run` every minute (same cron as other scheduled tasks).
2. **Queue worker** — processes `PrerenderUpcomingPlaylistItemsJob` and the follow-up `RenderPlaylistItemImageJob`.

If either is missing, enabling the flag has no effect. The default Docker image does run these jobs.

## What gets pre-rendered

Each minute (when the flag is on), LaraPaper scans devices that:

- Have contacted the server before (`last_refreshed_at` is set)
- Are not mirror devices
- Have an **active** playlist

For each device it finds the **next** playlist item. Pre-rendering runs only when:

- The item supports image cache (recipe plugins; **not** mashups)
- The expected device call falls in the **61–120 second** window ahead of “now”
- The device is not paused or in sleep mode at that expected call time

**Expected call time** is `last_refreshed_at` plus the refresh interval. The playlist’s **refresh time** overrides the device default when set.

## Warm vs refresh

The job chooses how hard to render:

| Situation | Action |
| --- | --- |
| No cached image yet | Queue a **warm** render (`force: false`) |
| Polling recipe whose data will be stale by the expected call | Queue a **refresh** (`force: true`) to re-fetch data and re-render |
| Cache still fresh at the expected call | Nothing queued |

## Manual cache control

Playlist items also support on-demand **warm** and **refresh** via the API (`warmCache` / `refreshCache`). Those endpoints are separate from this lab automation.