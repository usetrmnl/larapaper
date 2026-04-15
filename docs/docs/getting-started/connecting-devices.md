---
title: Connecting devices
description: Add TRMNL devices via auto-join or manual setup.
---

# Connecting devices

## Auto-join (local network)

1. Enable **Permit Auto-Join** in the header (only one registered user is supported for auto-join).
2. Devices on your local network are detected and added when they connect.

This is the easiest way to connect devices with minimal configuration.

## Manual setup

1. Open **Devices** → **Add New Device**.
2. Obtain the device MAC address and API key from the TRMNL dashboard, or inspect requests to `/api/setup`.

## Point the device at your server

### Firmware 1.4.6+

1. Complete device setup.
2. After Wi-Fi credentials, choose **Custom Server**.
3. Enter your LaraPaper URL.

### Firmware older than 1.4.6

Flash updated firmware so the device can use a custom server URL. See [this guide](https://www.youtube.com/watch?v=3xehPW-PCOM).

For cloud proxy setup after pairing with TRMNL cloud, see [Cloud proxy](/usage/cloud-proxy).
