---
title: Transform
description: Reshape polled JSON, or generate static recipe data, with a PHP, JavaScript, Python or Ruby run(input) function before rendering.
---

# Transform ("Serverless")

A recipe can run `run(input)` on its data before that data is stored and passed to the template. This mirrors [TRMNL serverless](https://help.trmnl.com/en/articles/14130649-serverless). LaraPaper still controls when a refresh happens.

It runs on a **Transform Provider**: either the built-in local provider, which runs JavaScript with the Node.js binary in the LaraPaper container, or an external **transform runner** for PHP, JavaScript, Python and Ruby.

| Data strategy | When it runs | What is stored |
| --- | --- | --- |
| Polling | Every refresh, after the polling URLs are fetched | The output |
| Static | Every refresh, instead of polling (`input` holds only `trmnl`) | The output |
| Webhook | Not supported | — |

## Enable transforms

### Local JavaScript provider

Set `TRANSFORM_PROVIDER=local`. JavaScript transforms then run with the Node.js binary already in the LaraPaper image; no extra container is needed. Node.js 24 or newer is required.

| Setting | Description | Default |
| --- | --- | --- |
| `TRANSFORM_PROVIDER` | `local` enables the local provider | unset |
| `TRANSFORM_TIMEOUT_SECONDS` | Max seconds for one transform | `5` |
| `TRANSFORM_MEMORY_LIMIT_MB` | Max JavaScript heap for one transform | `128` |
| `TRANSFORM_MAX_OUTPUT_BYTES` | Max size of the result and console output | `1048576` |
| `TRANSFORM_NODE_BINARY` | Name or absolute path of the Node.js binary | `node` |
| `TRANSFORM_ALLOW_NETWORK` | `false` makes `fetch()` in transforms fail | `true` |

Each run is isolated in two layers:

- **A new Node.js process per run**, started with an empty environment (no `APP_KEY`, database credentials or other variables) under Node's permission model without any grants: no filesystem access, child processes, workers or native addons. Code generation from strings is disabled, and the timeout, heap and output limits above apply.
- **A fresh JavaScript context inside that process.** The script has no `process`, `require`, `import()`, sockets, `WebAssembly` or shared memory, and nothing is shared between runs or between users.

The script gets the parts of the TRMNL Node runtime that transforms use, implemented inside the sandbox: `fetch` (below), `setTimeout`/`setInterval`, `AbortController`/`AbortSignal`, `TextEncoder`/`TextDecoder` (UTF-8), `atob`/`btoa`, `queueMicrotask`, `console` and `Intl`. Timers run in real time within `TRANSFORM_TIMEOUT_SECONDS`. Typed arrays and `ArrayBuffer` are available, with their combined size capped at `TRANSFORM_MEMORY_LIMIT_MB`. An `if (require.main === module)` block for local testing is skipped.

`fetch()` works for loading more data: the script only describes the request, and LaraPaper performs it with the same HTTP client as polling and hands back the status, headers and body. The script never holds a connection, cookies or server credentials. Responses support `ok`, `status`, `headers`, `text()` and `json()`; binary bodies are not available. A run may make up to 20 requests, request bodies must be strings (use `JSON.stringify`), responses are capped at 5 MiB, and request time counts toward `TRANSFORM_TIMEOUT_SECONDS`. Like polling URLs, any `http`/`https` URL can be fetched; set `TRANSFORM_ALLOW_NETWORK=false` to turn requests off.

```js
async function run(input) {
    const response = await fetch('https://example.com/catalogue.json');
    if (!response.ok) throw new Error('Catalogue HTTP ' + response.status);
    const { items } = await response.json();

    return { item: items[Math.floor(Math.random() * items.length)] };
}
```

Use `console.log` for debugging.

This is a restricted runtime, not an OS-level sandbox: the process runs as the same OS user as LaraPaper and has no CPU quota beyond the timeout. If you do not trust the people who write recipes on your instance, run transforms on an external runner instead.

### External transform runner

Set `TRANSFORM_PROVIDER=http`. Runs go to `TRANSFORM_RUNNER_URL` (default `http://transform:3000`). `TRANSFORM_RUNNER_TOKEN` is an optional bearer token. `TRANSFORM_TIMEOUT_SECONDS` defaults to `5`.

[docker/prod-transform/docker-compose.yml](https://github.com/usetrmnl/larapaper/blob/main/docker/prod-transform/docker-compose.yml) starts LaraPaper with [byos-transform-runner](https://github.com/bnussbau/byos-transform-runner):

```sh
docker compose -f docker/prod-transform/docker-compose.yml up -d
```

```yaml
services:
    app:
        image: ghcr.io/usetrmnl/larapaper:latest
        environment:
            - TRANSFORM_PROVIDER=http
    transform:
        image: ghcr.io/bnussbau/byos-transform-runner:latest
        security_opt:
            - seccomp=unconfined
            - apparmor=unconfined
        expose:
            - "3000"
```

The runner isolates each run with bubblewrap, which needs the relaxed `security_opt` settings. To use a runner elsewhere, set `TRANSFORM_RUNNER_URL` (and optionally `TRANSFORM_RUNNER_TOKEN`). You can also [write your own transform provider](/usage/transform-provider).

| Setting | Description | Default |
| --- | --- | --- |
| `TRANSFORM_PROVIDER` | `http` enables the external runner | unset |
| `TRANSFORM_RUNNER_URL` | Base URL of the runner | `http://transform:3000` |
| `TRANSFORM_RUNNER_TOKEN` | Bearer token sent to the runner | unset |
| `TRANSFORM_TIMEOUT_SECONDS` | Max seconds for one transform | `5` |

Bubblewrap needs `security_opt: [seccomp=unconfined, apparmor=unconfined]` on the `transform` service. For another host, set `TRANSFORM_RUNNER_URL`, or [write your own provider](/usage/transform-provider).

## Add a transform

1. On a polling or static recipe, click **+** and choose **Transform**. The tab starts with an example that adds `transformed_at`.
2. Pick JavaScript, PHP, Python, or Ruby and write `run(input)`. **Run** executes the editor (after fetching the polling URLs for a polling recipe) and stores nothing.
3. **Save.** The transform applies on the next refresh. A static recipe runs it on every refresh instead of polling, so `run(input)` builds the data itself. While a static recipe has a transform, the data payload shows the last output and cannot be edited. **Run transform now** refreshes it immediately.

The local provider runs JavaScript only. With an external runner, languages come from its `GET /manifest`. If the runner cannot run the language or cannot be reached, **Run** is disabled. You can still save, import, and export the code; the untransformed data is stored ([Failures](#failures)). Uncheck **Transform** and save to hide the tab. The code is kept.

## Input and output

For polling, `input` is the data's top-level keys. For static, `input` holds only `trmnl`. A polled JSON array is `input.data`; several URLs are `IDX_0`, `IDX_1`, and so on. `input.trmnl` has `user` and `plugin_settings` (including `custom_fields_values`). `device` is `null`. `system` and `sensors` exist only at render time.

JavaScript may declare `function run(input)`, `const run = …`, `export function run`, `export default`, or `module.exports`; `run` may be `async`. The deprecated TRMNL name `transform(input)` also works when there is no `run`.

Return an object (an associative array in PHP). It **replaces** the stored data, so include any source fields you still need. A returned `trmnl` key is dropped. JSON `{}` may arrive in PHP as `[]`. `console.log`, `print`, `puts`, and `echo` show under **Console output**.

```js
function run(input) {
    const label = input.trmnl.plugin_settings.custom_fields_values.label ?? 'Items';

    return { ...input, count: input.items.length, label };
}
```

## Failures

On an error, timeout, invalid return, unreachable runner, or unsupported language, LaraPaper logs a warning. Polling stores the untransformed data. Static keeps the last successful output. The recipe page shows the time, the error, and the script output (owner only, never in the application log). The next success clears it. A template that needs the new fields shows the device error screen.

## Recipe archives

The source is `transform.php`, `transform.py`, `transform.rb`, or `transform.js`, at the archive root or under `src/`. The extension sets the language. `serverless_language` in `settings.yml` chooses among several files; several files without it are rejected. Review the code before import: it runs on the next refresh.
