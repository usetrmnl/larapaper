---
title: '100 Releases of LaraPaper'
description: 'Celebrating LaraPaper 0.40.0, the 100th release.'
date: '2026-08-24'
author: bnussbau
seo:
  image: '/assets/images/100-releases-larapaper.jpg'
  canonicalUrl: 'https://usetrmnl.github.io/larapaper/posts/100-releases'
---

![LaraPaper 100 releases](/assets/images/100-releases-larapaper.jpg)

Since the [one-year post](/posts/one-year-larapaper) in March, the project has grown to **700 commits**, **340k+ downloads from the container registry**, and **370+ GitHub stars**. LaraPaper **0.40** marks the **100th release** and is the biggest release since the project’s initial launch.

## What's new in 0.40

### TRMNL X
Added support for TRMNL X OTA firmware updates, plus compatibility with **touchbar history** so recent screens stay browsable.

### Experimental features with “Lab”
**Lab** is a unified Settings view for alpha experiments before they are generally available. The first experiment is **MCP Server**: an opt-in Model Context Protocol endpoint so AI tools can list, create, update, and render recipes with an MCP token. [Learn more in the docs](/lab).

### Docs page
Documentation now lives at https://usetrmnl.github.io/larapaper. I'll gradually move more content out of the README and into the docs, making it easier to find, navigate, and keep up to date.

### Smarter image pipeline
The image pipeline now reuses the existing PNG when the SHA-256 hash matches, which avoids unnecessary downloads from the device.

### TRMNL Framework 3.2
The recipe renderer is bumped to **TRMNL Framework 3.2** with loads of new features. [(Release Notes)](https://trmnl.com/framework/releases)

### Holiday mode
Devices can now be paused for a set duration or until a chosen date and time. While holiday mode is on, the device only pings once every 24 hours to update battery status.

### Recipes
You can install from the catalog without closing the modal, and there is added compatibility for the `lat_lon` config field type.

### Home Assistant
Extra device attributes for battery plugins improve compatibility with [homeassistant-trmnl-battery](https://github.com/Beat2er/homeassistant-trmnl-battery).

### Calendars
The iCal parser now keeps multi-day events that overlap the visible window, whether they start inside it, end inside it, or span across it.

### Polish
A fix for device preview rotation, a new app logo, and some developer-focused improvements: an upgrade to Pest 5, resolved PHPStan warnings, and the Pint Blade formatter applied across the codebase.

## What the community builds with LaraPaper

[Akshath](https://ricekot.com/2026/trmnl-on-ipad/) turned a dusty iPad 3 into a dashboard by pointing Safari at `/mirror` and adding a custom Device Model for the larger screen. [Andrew Marder](https://andrewmarder.net/trmnl/) and [g7kse](https://g7kse.co.uk/blog/trmnl/) both revived old Kindles: Andrew wrote [ktrm](https://github.com/amarder/ktrm), a sleep-until-you-press-the-button client so a Voyage can show [Umami analytics](https://github.com/amarder/trmnl-umami) for hundreds of refreshes on one charge, while g7kse ran LaraPaper on a Raspberry Pi and landed on the KOReader.

[Jared](https://www.419.software/posts/self-hosting-a-trmnl-using-larapaper/) runs two Seeed DIY kits in 3D-printed housings, with custom recipes for today’s Asana tasks and Actual Budget. [Brett](https://brett.cloud/trmnl/) flashed various devices and published a [LaraPaper catalog](https://github.com/brettinternet/trmnl) of weather and AQI history, Home Assistant calendars, comics, Wikipedia, and GitHub graphs. And at Hasso-Plattner-Institut in Germany, the [Spark Community](https://spark-hpi.de/trmnl-hackathon/) hosted a Hackathon and used LaraPaper as the event server; participants shipped [various plugins](https://github.com/spark-hpi/trmnl-plugins) (bus departures, mensa menus, Moodle).


What are you building with LaraPaper? Tell us about it in the community survey: https://github.com/usetrmnl/larapaper/issues/47

## Thanks

Thank you to everyone supporting LaraPaper through contributions, feedback, bug reports and ideas.

Special thanks to supporters via the **Creators Fund**, the **referral program**, **GitHub Sponsors**, and **Buy Me a Coffee**.

## Outlook

For the next phase, the focus is on:

- A **transform layer**: The goal is a BYOS equivalent of Core’s "Serverless" scripts. Some initial work has been done by a contributor (https://github.com/spark-hpi/larapaper-serverless-runner) 
- A **reimplementation of the trmnl-liquid parser**, replacing the current Liquid parser with one that matches Core Liquid more closely, to improve compatibility with TRMNL recipe catalog. 

If LaraPaper helps your setup, you can support the project:

- Star on GitHub: [github.com/usetrmnl/larapaper](https://github.com/usetrmnl/larapaper)
- GitHub Sponsors: [github.com/sponsors/bnussbau](https://github.com/sponsors/bnussbau/)
- Buy Me a Coffee: [buymeacoffee.com/bnussbau](https://www.buymeacoffee.com/bnussbau)
