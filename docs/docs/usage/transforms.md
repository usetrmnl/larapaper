---
title: Polling transforms
description: Reshape polled JSON before rendering with a local PHP engine or external execution provider.
---

# Polling transforms

A recipe can run a function after LaraPaper fetches and parses its polling URLs,
before the returned JSON is cached and passed to Liquid. LaraPaper continues to
control refresh timing; no separate push script or scheduler is necessary.

This first implementation applies to **polling recipes**. Static/webhook
transforms, saved state and complete hosted Serverless parity are not included.

## Enable a recipe

1. Set `TRANSFORM_ENABLED=true` in your deployment configuration.
2. Open the recipe's polling settings and enter its transform source/language.
3. Check **Enable this recipe’s transform**, then save.
4. Fetch fresh data using the recipe editor's existing refresh control.

The server switch defaults to false, and every recipe starts with its transform
inactive. Importing a recipe preserves its source but never enables its execution.
Review imported code before checking the recipe's enable box.

## Local PHP provider

The default `TRANSFORM_PROVIDER=local` runs PHP CLI, already available in the
Docker image. No extra service is needed. Define `run($input)` and return an
object or array:

```php
function run($input) {
    return [
        'count' => count($input['items'] ?? []),
        'label' => $input['trmnl']['plugin_settings']['custom_fields_values']['label'] ?? 'Items',
    ];
}
```

Input contains the parsed response plus `trmnl.user`, `trmnl.device` (without an
attached device during polling), and `trmnl.plugin_settings`. Runtime process
environment and `trmnl.system` are not included. For multiple polling URLs the
responses retain their existing `IDX_0`, `IDX_1`, etc. keys. Results replace the
merge data; return any source fields you want to retain.

The local provider runs a separate PHP process with a fresh working directory,
no inherited application environment, no host PHP ini/extensions, restricted
filesystem access, disabled process execution and runtime reconfiguration,
a memory cap and a deadline. It is a restricted PHP runtime, **not an OS-level
sandbox for hostile code**. Keep execution disabled when recipe authors are not
trusted; use an externally isolated provider for that deployment model.

Defaults:

| Setting | Default |
| --- | --- |
| `TRANSFORM_TIMEOUT_SECONDS` | `5` |
| `TRANSFORM_MEMORY_LIMIT_MB` | `128` |
| `TRANSFORM_BIN_PHP` | `php` |
| `TRANSFORM_ALLOW_NETWORK` | `false` |

Local network fetching is disabled by default. Operators can explicitly enable
`TRANSFORM_ALLOW_NETWORK=true` to allow PHP HTTP(S) stream requests such as
`file_get_contents` with a request context. This permits requests from the
application's network; use dedicated credentials scoped to the required API
reads. Shell execution and loading arbitrary PHP extensions remain disabled.
There is no new public listener and no Docker socket dependency.

## HTTP execution provider

Set `TRANSFORM_PROVIDER=http` and `TRANSFORM_RUNNER_URL=http://runner:3000` to
use a compatible external runner such as
[spark-hpi/larapaper-serverless-runner](https://github.com/spark-hpi/larapaper-serverless-runner).
That provider advertises PHP, Python and Node transform languages. Its deployment
and isolation remain the runner operator's responsibility.

LaraPaper sends `POST /run` with:

```json
{"language":"python","code":"def run(input): return input","input":{"items":[]},"timeout":5}
```

The runner must return a successful response containing an `output` object or
array. Optionally set `TRANSFORM_RUNNER_TOKEN` for bearer authentication. Redirects
are refused. Transport failures, non-success statuses, malformed results and
responses exceeding the 1 MiB output budget fail the transform. Runner error
bodies and user stdout/stderr are not copied into application logs or data.

`TransformProvider` is the execution contract; additional providers can implement
it without changing the polling pipeline or recipe editor.

## Failures and cached screens

A transform failure leaves the previous data and fetch timestamp untouched, so
it remains stale and is retried on the next normal refresh. Device display cycles
retain the last compatible cached image. If no compatible image exists, the
normal playlist fallback applies. The recipe editor reports the failed refresh.
Do not treat an old cached image as a successful fresh transform.

## Recipe archives

Archives use `serverless_language` in `settings.yml` and source at
`transform.php`, `transform.py`, or `transform.js`, either at the archive root or
under `src/`. A single unambiguous source file can also declare its language by
extension. Export preserves source/language. Imports always leave the transform
inactive, including when an archive claims it was enabled. Missing or ambiguous
source/language declarations are rejected rather than silently discarded.
