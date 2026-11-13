---
title: Home Assistant
description: Run LaraPaper as a Home Assistant add-on.
---

# Home Assistant

The **LaraPaper (BYOS)** add-on in [usetrmnl/trmnl-home-assistant](https://github.com/usetrmnl/trmnl-home-assistant) runs the official LaraPaper image inside Home Assistant.

The web UI opens through Home Assistant ingress (**Open Web UI** and the sidebar). TRMNL devices reach the device API on port `4567` on your home network. The database, generated screens, and app key live in `/data`, so they survive add-on updates and are included in Home Assistant backups.

Options, the AppArmor profile, and the changelog are in the [add-on documentation](https://github.com/usetrmnl/trmnl-home-assistant/blob/main/trmnl-larapaper/DOCS.md).

## Install

[![Add repository to Home Assistant](https://my.home-assistant.io/badges/supervisor_add_addon_repository.svg)](https://my.home-assistant.io/redirect/supervisor_add_addon_repository/?repository_url=https%3A%2F%2Fgithub.com%2Fusetrmnl%2Ftrmnl-home-assistant)

Or add the repository yourself:

1. Go to **Settings → Add-ons → Add-on Store → ⋮ → Repositories**.
2. Add `https://github.com/usetrmnl/trmnl-home-assistant`.
3. Install **LaraPaper (BYOS)**.

## Configure and start

1. Set **App URL** to the address your TRMNL uses to reach this add-on, for example `http://192.168.1.10:4567`. Use an IP address or a name the device can resolve. `.local` names usually fail. Leave off the trailing slash.
2. Start the add-on and click **Open Web UI**.
3. Register your account, then turn off **Allow registration**. LaraPaper is single-user by design. Only leave registration on for trusted people, like family and friends.

## Point the device

A new device starts in Wi-Fi pairing mode. To return to pairing, hold the left and right ends of the touch bar until the screen flashes (TRMNL X), or hold the button on the back for 6 to 8 seconds (TRMNL OG).

1. Connect to the **TRMNL** Wi-Fi network.
2. Tap **Advanced → Custom Server → Yes** and enter the App URL without a trailing slash.
3. Go **Back to Wi-Fi**, pick your network, and tap **Connect**.
4. Turn on **Auto-Join** in the LaraPaper header. The device appears on its own.

The "Please visit trmnl.com/start" screen comes from the firmware. The device picks up its playlist at the next refresh.

A TRMNL OG on firmware older than 1.4.6 has no **Custom Server** option and needs a firmware update first. See [Connecting devices](/getting-started/connecting-devices).

## Options

| Option | Default | What it does |
| --- | --- | --- |
| App URL | empty | Address in the screen links LaraPaper gives the device (`APP_URL`) |
| Allow registration | on | Whether anyone who can reach the web UI can create an account |

## Using it

**Open Web UI** and **Show in sidebar** show LaraPaper inside Home Assistant. Ingress follows Home Assistant, including Home Assistant Cloud and your own remote access, so port `4567` stays on the LAN. Sign in to LaraPaper there with a password. Passkeys created at the App URL stay bound to that address.

On the home network, the web UI is also at the App URL, next to the device API.

Add recipes under **Plugins**. See [Generating screens](/usage/generating-screens).

## Security

Port `4567` serves the TRMNL device API and the web UI. Keep it on your home network and use **Open Web UI** from outside. Ingress only answers Home Assistant's own proxy.

The container is confined by an AppArmor profile. See the [add-on documentation](https://github.com/usetrmnl/trmnl-home-assistant/blob/main/trmnl-larapaper/DOCS.md#security) for the profile and how to check denials.
