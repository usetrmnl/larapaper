---
title: Write your own transform provider
description: The HTTP contract a transform runner implements so LaraPaper can execute recipe transforms on it.
---

# Write your own transform provider

LaraPaper does not execute [transforms](/usage/transforms) itself. It sends each run to an HTTP **transform provider**, which runs the code and returns the result. [byos-transform-runner](https://github.com/bnussbau/byos-transform-runner) is the reference. Any service that implements `POST /run` and `GET /manifest` can replace it.

```sh
TRANSFORM_PROVIDER=http
TRANSFORM_RUNNER_URL=https://runner.example.com
TRANSFORM_RUNNER_TOKEN=change-me   # optional; sent as Authorization: Bearer
```

## `POST /run`

LaraPaper sends one request per transform run:


```json
{
  "language": "python",
  "code": "def run(input):\n    return {\"count\": len(input[\"items\"])}",
  "input": {
    "items": [1, 2, 3],
    "trmnl": { "user": {}, "plugin_settings": { "custom_fields_values": {} } }
  },
  "timeout": 5
}
```

| Field | Description |
| --- | --- |
| `language` | `php`, `node` (JavaScript), `python`, or `ruby` |
| `code` | Source that defines `run(input)`. Legacy TRMNL code may define `transform(input)` |
| `input` | The data plus `trmnl`. See [Input and output](/usage/transforms#input-and-output) |
| `timeout` | Seconds allowed (`TRANSFORM_TIMEOUT_SECONDS`) |

Call that function with `input` and answer **200**:

```json
{"output": {"count": 3}, "logs": ""}
```

`output` must be a JSON object or array. `logs` is optional console or stderr text. On an exception, timeout, or invalid output, answer with any non-2xx status:

```json
{"error": "NameError: name 'items' is not defined", "logs": "..."}
```

LaraPaper shows the first 1 KiB of `error` to the recipe owner and stores the untransformed data. The message is not written to LaraPaper's logs.

## `GET /manifest`

Required. Describe the provider and the languages it can run:

```json
{
  "name": "BYOS Transform Runner",
  "version": "1.0.0",
  "language_capabilities": ["node", "php", "python", "ruby"]
}
```

`language_capabilities` is required: any of `php`, `node`, `python`, `ruby`. Unknown entries are ignored. `name`, `version`, `author`, `license`, `repository`, and `contributor_info` are optional metadata.

LaraPaper caches the list for 5 minutes and only runs those languages. If the endpoint is missing, fails, or has no list, no transform runs and the untransformed data is stored. It asks again after 30 seconds.

## Limits

- LaraPaper waits `timeout` + 2 seconds. Enforce the timeout yourself so a slow script returns an error instead of a dropped request.
- Responses over 1 MiB (`TRANSFORM_MAX_OUTPUT_BYTES`) fail the transform.
- Redirects are not followed.
- Only `trmnl.user` and `trmnl.plugin_settings` are sent. There is no device, system, or sensor context.
- When `TRANSFORM_RUNNER_TOKEN` is set, requests carry that bearer token. Reject requests without it if the service is reachable by others.

## Security

Recipe authors, including authors of imported recipes, write this code. Run each transform in a fresh sandbox with no secrets, no host filesystem, and caps on memory, CPU, and output. If you allow network access, the code can reach anything the provider can reach. A child process with a timeout is only a starting point; use bubblewrap, gVisor, or a per-run container before accepting untrusted code.
