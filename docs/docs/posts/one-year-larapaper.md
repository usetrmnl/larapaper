---
title: 'One Year of LaraPaper: Building a BYOS for TRMNL'
description: 'A year of turning LaraPaper into a BYOS.'
date: '2026-03-20'
author: bnussbau
seo:
  image: '/assets/images/one-year-larapaper.jpg'
  canonicalUrl: 'https://usetrmnl.github.io/larapaper/posts/one-year-larapaper'
---

![LaraPaper community milestone](/assets/images/one-year-larapaper.jpg)

One year ago, I started building what would later become LaraPaper because I wanted one very specific thing: a train schedule in the morning that updated more frequently. The native cloud flow was designed around a 15-minute refresh interval, but I wanted updates every 5 minutes during the morning rush. From there, I kept expanding LaraPaper into a BYOS (Bring Your Own Server) for TRMNL.

At this milestone, the project has reached **90+ releases**, **500+ commits**, **~85k downloads from the container registry** and **230+ GitHub stars**.

## What changed in one year

- **Proxy feature** for hybrid setups, combining local and cloud-native behavior.
- **Device models support** for better compatibility across different display hardware.
- **TRMNL recipe catalog integration** for importing and adapting existing recipes from the community.
- **Image Webhook and Alias support** enabling local network data access and letting you combine core and self-hosted services in a more flexible way.
- **iCal feed support** for calendar-driven dashboards and schedules.
- **Support for temperature and humidity sensors** mounted to TRMNL e-paper devices.

## How LaraPaper works

In short, a device calls the `/api/display` endpoint, LaraPaper renders the selected screen content with Puppeteer, and the image pipeline converts it into a PNG variant that matches the active device model using ImageMagick. The device downloads and displays the rendered image.

## Who is using LaraPaper

Here are some insights from the community survey about setups and motivations for using LaraPaper:

- Hosting across **NAS systems, home servers, Raspberry Pi setups, and VPS**.
- Strong preference for **Docker Compose**
- Common content themes like **weather, calendars, train/public transport data, and Home Assistant dashboards**.
- Frequent usage centered on **local data access, privacy, and ownership**.

Some users run a single DIY screen in a hallway, while others are preparing multi-device setups with different playlists for morning, evening, and shared spaces.

## Thanks

Thank you to everyone supporting LaraPaper through feedback, bug reports, contributions and ideas.

Special thanks to supporters via the **Creators Fund**, the **referral program**, **GitHub Sponsors**, and **Buy Me a Coffee**.

## Outlook

For the next phase, the focus is on:

- Improve recipe compatibility
- Upgrading to the freshly released Laravel 13 framework
- General stability improvements until a 1.0 release

If LaraPaper helps your setup, you can support the project on the [Support](/support) page or:

- Star on GitHub: [github.com/usetrmnl/larapaper](https://github.com/usetrmnl/larapaper)
- GitHub Sponsors: [github.com/sponsors/bnussbau](https://github.com/sponsors/bnussbau/)
- Buy Me a Coffee: [buymeacoffee.com/bnussbau](https://www.buymeacoffee.com/bnussbau)
