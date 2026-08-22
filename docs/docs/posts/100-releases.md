---
title: '100 Releases of LaraPaper'
description: 'Celebrating LaraPaper 0.40.0, the 100th release.'
date: '2026-08-22'
author: bnussbau
seo:
  image: '/assets/images/100-releases-larapaper.jpg'
  canonicalUrl: 'https://usetrmnl.github.io/larapaper/posts/100-releases'
---

![LaraPaper 100 releases](/assets/images/100-releases-larapaper.jpg)

Since the [one-year post](/posts/one-year-larapaper) in March, the project has grown to **700 commits**, **340k+ downloads from the container registry**, and **370+ GitHub stars**. LaraPaper **0.40.0** is the **100th release** and it's the biggest since it's initial release.

## What's new in 0.40

- **TRMNL X** support for OTA firmware updates, plus compatibility with **touchbar history** so recent screens stay browsable instead.
- **Lab**, a unified Settings view for alpha experiments before they are generally available. **MCP Server** is the first experiment: an opt-in Model Context Protocol endpoint so AI tools can list, create, update, and render recipes with an MCP token. [(Docs)](/lab)
- **Smarter image pipeline** that reuses the existing PNG when the SHA-256 hash matches, which avoids unnecessary downloads from the device.
- Bumps the recipe renderer to **TRMNL Framework 3.2** with loads of new features. [(Release Notes)](https://trmnl.com/framework/releases) 
- **Holiday mode** for pausing a device for a set duration or until a chosen date and time. The device only pings once every 24 hours to update battery status.
- **Recipe UX** improvements: install from the catalog without closing the modal, and a added compatibility for the `lat_lon` custom field type for location-based recipes.
- **Home Assistant** improvements via extra device attributes for battery plugins. Improves compatibility with https://github.com/Beat2er/homeassistant-trmnl-battery 
- **Calendars**: the iCal parser now keeps multi-day events that overlap the visible window, whether they start inside it, end inside it, or span across it.
- **Polish**: a device preview rotation fix, a new app logo, and an upgrade to Pest 5.

## Thanks

Thank you to everyone supporting LaraPaper through contributions, feedback, bug reports and ideas.

Special thanks to supporters via the **Creators Fund**, the **referral program**, **GitHub Sponsors**, and **Buy Me a Coffee**.

## Outlook

For the next phase, the focus is on:

- A **transform layer**: The goal is a BYOS equivalent of Core’s "Serverless" scripts. Some initial work has been done by a contributor (https://github.com/spark-hpi/larapaper-serverless-runner) 
- A **reimplementation of the trmnl-liquid parser**, replacing the current Liquid parser with one that matches Core Liquid more closely, to improve compatibility with TRMNL recipe catalog. 

If LaraPaper helps your setup, you can support the project on the [Support](/support) page or:

- Star on GitHub: [github.com/usetrmnl/larapaper](https://github.com/usetrmnl/larapaper)
- GitHub Sponsors: [github.com/sponsors/bnussbau](https://github.com/sponsors/bnussbau/)
- Buy Me a Coffee: [buymeacoffee.com/bnussbau](https://www.buymeacoffee.com/bnussbau)
