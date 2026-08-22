---
title: Lab
description: Experimental LaraPaper features before general availability.
---

# Lab

Lab is where experimental features land before they are generally available. They are **alpha**: they may change, and they can affect system stability.

Open **Settings → Lab** from the beaker item in the user menu. Only the **first user** (user id `1`) can turn lab flags on or off.

## Current experiments

::: card "MCP Server" icon:bot
Expose a Model Context Protocol server so AI tools can list, create, update, and render your recipes. Requires an MCP API token.
::: button "MCP Server" /lab/mcp
:::

## Enable a flag

1. Sign in as the first registered user.
2. Go to **Settings → Lab**.
3. Toggle the experiment on.

You can also enable the MCP server with `TOGGLE_MCP=true`. See [Environment variables](/getting-started/environment-variables).
