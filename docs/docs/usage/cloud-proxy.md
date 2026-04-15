---
title: Cloud proxy
description: Hybrid setups using LaraPaper as a proxy for TRMNL cloud plugins.
---

# Cloud proxy

LaraPaper can proxy the native TRMNL cloud service so you can mix local plugins (e.g. a 5-minute train monitor) with cloud plugins for the rest of the day. Requires a **TRMNL Developer Edition** license.

## Activate a device with cloud proxy

1. Set up the device with the official TRMNL cloud flow first (connect a plugin to verify cloud access).
2. Install LaraPaper, create a user, and sign in.
3. Enable **Permit Auto-Join** in the header.
4. Hold the device button for 5 seconds to reopen the captive portal (or reflash).
5. Run setup again; set **Server URL** to your LaraPaper address.
6. The device should appear in the device list; disable auto-join if you no longer need it.
7. Enable **Proxy** for the device. Ensure the queue worker is running (included in the Docker image).
8. When no LaraPaper plugin is scheduled, the device shows cloud plugins.

## Troubleshooting

Confirm a Developer license by calling `https://trmnl.app/api/display`.

- [Private API introduction](https://docs.usetrmnl.com/go/private-api/introduction)
- [Fetch screen content](https://docs.usetrmnl.com/go/private-api/fetch-screen-content)
