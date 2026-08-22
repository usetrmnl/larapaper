---
title: MCP Server
description: Connect AI tools to LaraPaper recipes over the Model Context Protocol.
---

# MCP Server

LaraPaper can expose an HTTP [Model Context Protocol](https://modelcontextprotocol.io/) server so AI clients (Cursor, Claude Code, and others) can list, create, update, and render **recipe** plugins for the authenticated user.

The endpoint is `POST /mcp`. Native plugins are not available through these tools. Recipes are identified by **numeric database ID**, not UUID. Markup defaults to Blade; pass `markup_language: liquid` when writing Liquid templates.

## Enable

1. Sign in as the **first user**.
2. Go to **Settings → Lab** and turn on **MCP Server**.
3. Go to **Settings → API Tokens**, choose token type **MCP**, create a token, and copy it. The value is shown only once.

A regular API token (`*` ability) is rejected. The MCP endpoint requires the `mcp` ability. If the lab flag is off, `/mcp` returns **404**.

You can also set `TOGGLE_MCP=true` instead of using the Lab UI. See [Environment variables](/getting-started/environment-variables).

## Connect a client

Point the client at your public LaraPaper URL (`APP_URL`) plus `/mcp`, and send the MCP token as a bearer header.

Cursor / Claude Code (`mcp.json`):

```json
{
  "mcpServers": {
    "larapaper": {
      "url": "https://YOUR_LARAPAPER_HOST/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_MCP_TOKEN"
      }
    }
  }
}
```

Node-based MCP clients can fail against local HTTPS. Prefer the public `https://` origin you already use in the browser.

## Tools

| Tool | Purpose |
| --- | --- |
| `list-recipes` | List recipes you own (id, name, browser URL, data strategy). |
| `get-recipe` | Full details: markup, render settings, data payload. |
| `create-recipe` | Create a recipe. Optional Blade/Liquid, polling/webhook/static, polling URL. Returns id and editor URL. |
| `update-recipe-markup` | Update `full`, `half_horizontal`, `half_vertical`, `quadrant`, or `shared` markup. |
| `update-recipe-settings` | Data strategy, framework version, polling. Returns a webhook URL when strategy is webhook. |
| `render-recipe` | Render a layout size (`full` by default). Returns HTML, or a compile error to fix. |

Typical loop: `list-recipes` or `get-recipe` → `render-recipe` → `update-recipe-markup` / `update-recipe-settings` → render again. Use `create-recipe` when you need a new recipe.

## Example

After the server is connected, ask your AI client to build and verify a recipe:

> Using the LaraPaper MCP server, create a Blade recipe named "Morning weather" that polls `https://api.open-meteo.com/v1/forecast?latitude=48.2&longitude=16.4&current=temperature_2m`. Write a TRMNL full layout that shows the temperature. Call `render-recipe` and fix any errors until it succeeds, then give me the recipe URL.

That creates a polling recipe, writes markup, and iterates on `render-recipe` until the layout compiles. Open the returned URL in the recipe editor to preview it in the browser.
