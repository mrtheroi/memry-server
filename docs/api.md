# API

HTTP endpoints and MCP tools served by memry-server. Production host: `https://api.memry.com.mx`. See [Security](security.md) for authentication and rate limit details.

## MCP tools

Served at `POST /mcp/memory` (Sanctum bearer token). Agents discover the tools with `tools/list` and invoke them with `tools/call`.

Project names are normalized on write and on query (trimmed, lowercased, repeated `--`/`__` collapsed), so `dbmcp`, `dbMcp` and ` DbMcp ` refer to the same project.

| Tool | Arguments (* required) | Behavior |
| --- | --- | --- |
| `save-memory` | `session_id`*, `type`*, `title`*, `content`*, `project`, `topic_key` | Saves an observation. The same `topic_key` in the same project (under any spelling) updates it instead of duplicating it. |
| `search-memory` | `query`*, `limit` (1–20, default 10), `project` | Full-text search (title weighs more than content), ordered by relevance. Pass `project` to search only that project; omit it to search all projects. |
| `session-summary` | `session_id`*, `project`*, `content`*, `repo` | Saves the session summary as an observation of type `session_summary`. Pass `repo` when the project spans several repositories; the title becomes `Session summary: {project} ({repo})`. |
| `get-context` | `project`* | Returns a bounded context of the project, limited to the request's team and the projects the user can access (a project that exists only in another team answers `No context found for project {name}.`): the last 3 session summaries (the newest in full, the other two with their date and a 300-character preview), up to 20 topic-key memories with a 300-character preview, and up to 10 other recent memories by title. |
| `get-memory` | `id`* | Returns the full content of one memory of the request's team (the token's team, or the user's personal team for older tokens), limited to the projects the user can access. An id that does not exist or belongs to another team returns `Memory not found.` |
| `save-prompt` | `session_id`*, `content`*, `project` | Stores the user's prompt verbatim. |

## HTTP context endpoint

`GET /api/context?project={name}` returns exactly the text of `get-context` as `text/plain`, so shell hooks (e.g. a Claude Code `SessionStart` hook) can load the project context without speaking MCP JSON-RPC. It uses the same Sanctum bearer tokens and the same rate limit as MCP requests.

```bash
curl -sf -H "Authorization: Bearer <token>" \
  "https://<your-host>/api/context?project=dbmcp"
```

| Status | When |
| --- | --- |
| `200` | The context, or `No context found for project {project}.` |
| `401` | Missing or invalid token |
| `422` | `project` missing, not a string, or longer than 255 characters |
| `429` | Rate limit exceeded |

## Terminal login

The `memry` CLI logs in without a browser: request a one-time code by email, then exchange it for a Sanctum token. Signup is open: an unknown email creates the user on its first successful login.

```bash
curl -s -X POST https://<your-host>/api/auth/code \
  -H "Content-Type: application/json" -d '{"email":"ada@example.com"}'
# 202 {"message":"If the email is valid, a login code has been sent."}

curl -s -X POST https://<your-host>/api/auth/token \
  -H "Content-Type: application/json" -d '{"email":"ada@example.com","code":"042917"}'
# 200 {"token":"1|..."}

curl -s -X DELETE https://<your-host>/api/auth/token -H "Authorization: Bearer <token>"
# 204 (only this token is revoked)
```

The email is trimmed and lowercased. The code has 6 digits, expires in 5 minutes, works once, and is replaced by any newer code for the same email. The token is named `memry-cli` and works for `/mcp/memory` and `/api/context`.

| Endpoint | Status | When |
| --- | --- | --- |
| `POST /api/auth/code` | `202` | Always for a valid email (same answer whether or not the user exists) |
| | `422` | `email` missing, not a string, not an email, or longer than 255 characters |
| | `429` | Rate limit exceeded for the email or the IP (see [Security](security.md#rate-limits)) |
| `POST /api/auth/token` | `200` | `{"token": "..."}` |
| | `422` | Validation errors, or `Invalid or expired code.` (wrong, expired, used or superseded code; the 5th wrong attempt burns the code) |
| | `429` | Rate limit exceeded for the IP (see [Security](security.md#rate-limits)) |
| `DELETE /api/auth/token` | `204` | The token used for the request is revoked; the user's other tokens stay valid |
| | `401` | Missing, invalid, expired (30 days without use) or already revoked token |
| | `429` | Rate limit exceeded for the user (see [Security](security.md#rate-limits)) |
| `GET /api/auth/tokens` | `200` | `{"data": [{"id", "name", "abilities", "created_at", "last_used_at", "expires_at", "current"}]}` for the caller's tokens only. `expires_at` is when the token expires from inactivity; `current` marks the token used for the request; `abilities` is `["*"]` for a full-access token and `["usage:write"]` for the usage token. Hashes and plaintext are never returned |
| `DELETE /api/auth/tokens/{id}` | `204` | That token is revoked; the others stay valid |
| | `404` | Unknown id or a token of another user (indistinguishable) |
| | `429` | Rate limit exceeded for the user |

### Token abilities and the usage token

A token carries abilities. Tokens from the login flow and `memory:token` are **full access** (`["*"]`). Every token-authenticated endpoint (all of `/api/*` behind `auth:sanctum`, and the MCP endpoint `/mcp/memory`) requires full access; dashboard sessions count as full access.

A second kind, the **usage token**, carries only `usage:write`. It exists to be written into `~/.claude/settings.json` for OpenTelemetry export and is rejected with `403` on every other endpoint, including MCP, `/api/me`, the memory, stats, token and account endpoints, and this one (a usage token cannot mint another).

| Endpoint | Status | Meaning |
| --- | --- | --- |
| `POST /api/auth/usage-token` | `201` | `{"id": 42, "token": "id\|plain"}`: `id` is the new token's numeric id (the same number as the prefix of `token`); a new token named `memry-usage`, bound to the caller's team, with abilities `["usage:write"]`. Requires a full-access token or a session |
| | `401` | Missing, invalid or expired token |
| | `403` | The caller is a usage token |
| | `429` | Rate limit exceeded for the user |

The call **only issues**: earlier `memry-usage` tokens of the user stay valid, so a client that fails to store the new token still has a working export. Revoke the old ones with `DELETE /api/auth/usage-tokens` (below). The plaintext is shown only in this response. The usage token follows the same idle expiry as any token (30 days without use).

Tokens expire after 30 days without use (`MEMRY_TOKEN_IDLE_DAYS`); an expired token answers `401 {"message": "Unauthenticated."}`, like any invalid token. `php artisan memory:prune-tokens` (scheduled daily) removes them.

## OTLP usage ingest

`POST /api/otlp/v1/metrics` receives the OpenTelemetry metrics Claude Code exports (token and cost usage) as OTLP/HTTP **JSON**. It is the only token endpoint outside the full-access group.

**Auth.** `Authorization: Bearer <token>` with the `usage:write` ability: the usage token from `POST /api/auth/usage-token` (a full-access token also works). Other tokens without that ability get `403`. Identity comes only from the token: rows are stored for the token's user and team, and any `user.*`, `organization.*` or other identity attribute in the payload is ignored.

**Format.** `Content-Type: application/json` (a `charset` parameter is fine), the OTLP JSON encoding of an `ExportMetricsServiceRequest`: camelCase fields, int64 values (`asInt`, `*TimeUnixNano`) as strings. Compressed bodies (`Content-Encoding`) are not supported. Only two metrics are read, everything else is ignored silently:

| Metric | Stored as | Attributes read |
| --- | --- | --- |
| `claude_code.token.usage` (Sum) | `tokens`, with `token_type` `input`, `output`, `cacheRead` or `cacheCreation` (any other type is skipped) | `type`, `model`, `query_source`, `session.id` |
| `claude_code.cost.usage` (Sum, USD) | `cost` | `model`, `query_source`, `session.id` |

Only **delta** data points (`aggregationTemporality` 1) are stored, which is the Claude Code default. Cumulative points are skipped and reported in the response (below). Values that are exactly zero as sent are skipped (not reported), `model`, `query_source` and `session.id` are cut to 200 characters, and a missing `model` is stored as `unknown`. Points whose time window is not valid int64 nanoseconds are skipped.

**Values.** A point's value is `asInt` (a decimal int64 string, or a JSON integer) or `asDouble` (a finite JSON number). Counters here are monotonic deltas, so a missing value, a non-numeric or out-of-int64-range `asInt`, an array or object, a negative number, `NaN`/`Infinity` (as strings), a `claude_code.token.usage` value that is not exactly a whole number (an `asDouble` such as `1.5`, `3.0000001` or `0.0000001`; `3.0` is fine), a `claude_code.token.usage` value above **1,000,000,000,000** (one trillion tokens, `OtlpMetricsParser::MAX_TOKENS`) or a `claude_code.cost.usage` value above **1,000,000,000** USD (`OtlpMetricsParser::MAX_COST_USD`) is **invalid**. No real delta comes near those limits. Invalid points are skipped, never a server error, and counted in `partialSuccess` (below). Every check is made on the value exactly as sent, before any rounding. Values are then stored as `numeric(38,6)`: cost keeps 6 decimals by contract and is rounded half-up, so a positive cost below half a micro-dollar (for example `0.0000001`) is stored as `0.000000` rather than dropped.

**Series identity.** A series is the metric plus the **complete** attribute set of the data point (sorted by key, values normalized to strings, hashed with a keyed HMAC-SHA256 into `series_hash`), so points that differ in any attribute (`speed`, `effort`, `skill.name`, `mcp_server.name`, ...) are separate series and are never merged. `model`, `token_type`, `query_source` and `session_id` are also stored as columns for querying. Resource attributes are not part of the identity: they describe the exporter, are constant for it, and include `user.*` values that are never trusted. The HMAC key is derived from the server's `APP_KEY` under its own context label, so a `series_hash` cannot be reversed, nor matched against guessed attributes such as a known `user.email`, without the server's secret.

**Idempotent.** Exporters retry. Each point is stored once per team, user, series and time window (`startTimeUnixNano`, `timeUnixNano`): a unique index on `(team_id, user_id, metric, series_hash, start_unix_nano, end_unix_nano)` plus `INSERT ... ON CONFLICT DO NOTHING` makes a retried or duplicated batch a no-op, so totals never double count. A different window of the same series is a new row, and the same export sent with tokens of two teams is stored once per team. Rotating `APP_KEY` changes the hashes of every later point, so a point retried across a key rotation could be stored twice; rotation is rare and this is accepted.

| Status | Meaning |
| --- | --- |
| `200` | `{}`, or `{"partialSuccess": {"rejectedDataPoints": "2", "errorMessage": "..."}}` when cumulative or invalid-value points were skipped (the rest of the request is stored) |
| `400` | Not valid JSON, or not an OTLP metrics request: a structural field has the wrong type (for example a non-string metric `name`, a JSON object where a list belongs or the reverse, even when empty, a non-string attribute `key`). Value problems of a data point are not a 400, see `partialSuccess` (`{"message": "..."}`) |
| `401` | Missing, invalid or expired token |
| `403` | The token lacks `usage:write`, or has no team |
| `413` | Body over 1 MB (checked before decoding), or more than 5000 data points of the two known metrics in one request, counting every one of them whether it is valid, cumulative, zero or invalid (nothing is stored) |
| `415` | Not `application/json` (for example protobuf) |
| `429` | Over 120 requests per minute per user |

**Claude Code setup.** Use the `http/json` protocol; protobuf is not accepted:

```sh
export CLAUDE_CODE_ENABLE_TELEMETRY=1
export OTEL_METRICS_EXPORTER=otlp
export OTEL_EXPORTER_OTLP_PROTOCOL=http/json
export OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=https://api.memry.com.mx/api/otlp/v1/metrics
export OTEL_EXPORTER_OTLP_METRICS_HEADERS="Authorization=Bearer <usage token>"
```

Per the OpenTelemetry spec, the per-signal `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT` is used **as is** (give it the full path above), while the generic `OTEL_EXPORTER_OTLP_ENDPOINT` gets `/v1/metrics` appended (it would need to be `https://<host>/api/otlp`). Prefer the per-signal variable.

Retention is not implemented yet: `usage_points` rows are kept until the user or team is deleted. A pruning job is a follow-up.

## Dashboard (session) authentication

The private dashboard (a single-page app on its own subdomain, for example `https://app.memry.com.mx` talking to `https://api.memry.com.mx`) signs in with the same emailed code as the CLI, but gets a session cookie instead of a token. Only requests whose `Origin` or `Referer` is a stateful domain (`SANCTUM_STATEFUL_DOMAINS`) use the session; CLI and MCP requests carry a bearer token, send no such header and behave exactly as before.

Server settings (see [Security](security.md#dashboard-sessions-cors-and-cookies)):

| Variable | Example | Meaning |
| --- | --- | --- |
| `MEMRY_DASHBOARD_URL` | `https://app.memry.com.mx` | Browser origin(s) allowed by CORS, comma-separated. Empty (the default) allows none |
| `SANCTUM_STATEFUL_DOMAINS` | `app.memry.com.mx` | Hosts whose requests use the session (include the port in development, for example `localhost:5173`) |
| `SESSION_DOMAIN` | `.memry.com.mx` | Lets the session and `XSRF-TOKEN` cookies be shared between the app and API subdomains |
| `SESSION_SECURE_COOKIE` | `true` | Send cookies over HTTPS only; set it in production |

The flow, from the SPA (every request with credentials: `fetch(..., {credentials: "include"})` or `withCredentials: true`):

```bash
# 1. Get the CSRF cookie. 204, sets XSRF-TOKEN and the session cookie.
curl -s -c jar -H "Origin: https://app.memry.com.mx" https://<api-host>/sanctum/csrf-cookie

# 2. Request the code (same endpoint as the CLI): 202
# 3. Open the session. Echo the XSRF-TOKEN cookie in the X-XSRF-TOKEN header (axios and most clients do it automatically).
curl -s -b jar -c jar -X POST https://<api-host>/api/session \
  -H "Origin: https://app.memry.com.mx" -H "X-XSRF-TOKEN: <XSRF-TOKEN cookie value>" \
  -H "Content-Type: application/json" -d '{"email":"ada@example.com","code":"042917"}'
# 204, no body, no token; the session cookie is replaced (the session id is regenerated)

# 4. Use the API with the cookie
curl -s -b jar -H "Origin: https://app.memry.com.mx" https://<api-host>/api/me
# 200 {"data":{"id":1,"email":"ada@example.com","name":"ada","team":{"id":1,"slug":"...","personal_team":true}}}

# 5. Sign out
curl -s -b jar -c jar -X DELETE https://<api-host>/api/session \
  -H "Origin: https://app.memry.com.mx" -H "X-XSRF-TOKEN: <XSRF-TOKEN cookie value>"
# 204
```

Every state-changing request (`POST`, `DELETE`) needs the `X-XSRF-TOKEN` header; without it the server answers `419`.

Axios only copies the `XSRF-TOKEN` cookie into `X-XSRF-TOKEN` automatically on same-origin requests. For a cross-origin SPA (app and API on different subdomains) set both `withCredentials: true` and `withXSRFToken: true` (Axios 1.6 or newer), or read the `XSRF-TOKEN` cookie yourself and send it in the `X-XSRF-TOKEN` header.

`POST /api/session` is only available to requests from a stateful domain: from any other origin (or without `Origin`/`Referer`) it answers `403 Session login is only available to the dashboard origin.` without consuming the code, so use `POST /api/auth/token` there.

| Endpoint | Status | When |
| --- | --- | --- |
| `GET /sanctum/csrf-cookie` | `204` | Sets the `XSRF-TOKEN` cookie |
| `POST /api/session` | `204` | The code is valid: the user is signed in (created on first login, with its personal team). No token is issued |
| | `422` | Validation errors, or `Invalid or expired code.` (same rules and message as `POST /api/auth/token`) |
| | `403` | The request is not from a stateful domain (`SANCTUM_STATEFUL_DOMAINS`); the code is not consumed |
| | `419` | Missing or wrong CSRF token |
| | `429` | Rate limit exceeded for the IP (shared with `POST /api/auth/token`) |
| `DELETE /api/session` | `204` | The session is ended and the CSRF token regenerated |
| | `401` | Not signed in |
| | `422` | The request used a bearer token: this endpoint never revokes tokens, use `DELETE /api/auth/token` |
| `GET /api/me` | `200` | `{"data": {"id", "email", "name", "team": {"id", "slug", "personal_team"}}}`. The team is the one the request acts on: the token's team with a bearer token, the personal team with a session. Works with both |
| | `401` | Not signed in and no valid token |

Token endpoints under a session:

- `GET /api/auth/tokens` lists the user's tokens; `current` is `false` for all of them, because a session is not a token.
- `DELETE /api/auth/tokens/{id}` revokes that token, as with a bearer token.
- `DELETE /api/auth/token` answers `422` (`This request is authenticated with a session, not a token. Use DELETE /api/session to sign out.`).
## Dashboard: reading memories

JSON read endpoints for the dashboard. They work with a dashboard session or with a bearer token, are scoped to the acting team and, for a restricted member, to the granted projects, and are limited to 120 requests per minute per user (`429` after that). Collections are `{"data": [...]}`. A caller with no team reads nothing: empty collections, and `404` for any id. Without credentials every endpoint answers `401`.

**Personal-scope privacy.** A memory with `scope = personal` is visible only to its author; `scope = project` memories are visible team-wide. The rule applies to these endpoints (list, detail, search and the project counts). The MCP tools (`get_memory`, `search_memory`, `get_context`) keep their current behavior and do not apply it yet.

Memory item (list and search):

```json
{"id": 12, "title": "Use Sanctum", "type": "decision", "project": "memry", "scope": "project", "topic_key": "auth/sanctum", "excerpt": "first ~200 characters of the content, whitespace collapsed", "created_at": "2026-10-14T10:00:00+00:00", "updated_at": "2026-10-14T10:00:00+00:00"}
```

The excerpt is the content with whitespace collapsed to single spaces, cut at 200 characters (an ellipsis marks a cut). The detail adds the full `content`.

| Endpoint | Parameters | Response |
| --- | --- | --- |
| `GET /api/projects` | none | `{"data": [{"id", "name", "memories_count", "last_activity_at"}]}`: only the projects the caller can see, most recently active first. `memories_count` counts only the memories the caller can read; `last_activity_at` is the latest `updated_at` of those (`null` when there are none, listed last) |
| `GET /api/memories` | `project` (name, optional), `type` (optional, for example `session_summary`), `cursor` (from `meta`), `per_page` (1 to 100, default 20) | `{"data": [item], "meta": {"next_cursor", "prev_cursor", "per_page"}}`, ordered by `updated_at` then `id`, both descending. `422` for an invalid `per_page` or a `cursor` this endpoint did not issue |
| `GET /api/memories/{id}` | none | `{"data": {item, "content"}}`. `404` with the same body for a missing id and for a memory the caller cannot read (another team, an ungranted project, another member's personal memory) |
| `GET /api/memories/search` | `q` (required, trimmed, 1 to 200 characters), `project` (optional), `limit` (1 to 50, default 20) | `{"data": [item]}` ranked by relevance (same full-text search as the MCP tool), with no cursor. `422` for an invalid `q` or `limit` |

Ids (memories, projects, tokens) are JSON numbers. They come from database sequences and stay far below 2^53, so JavaScript's `JSON.parse` reads them exactly; no string ids are needed. A move to non-sequential ids would be a new API version.

Pagination is cursor-based. `meta.next_cursor` is `null` on the last page and `meta.prev_cursor` is `null` on the first; pass either value back as `cursor` with the same filters to move. Cursors are opaque, tied to the ordering and signed with the server's `APP_KEY`: a built or edited cursor is rejected with `422`, and rotating `APP_KEY` invalidates the cursors already handed out. Rows with the same `updated_at` keep a stable order, so no row repeats or is skipped between pages.

```bash
curl -s -b jar -H "Origin: https://app.memry.com.mx" "https://<api-host>/api/memories?project=memry&type=session_summary&per_page=20"
curl -s -b jar -H "Origin: https://app.memry.com.mx" "https://<api-host>/api/memories?cursor=<meta.next_cursor>&project=memry&type=session_summary&per_page=20"
```

## Dashboard: stats

`GET /api/stats` feeds the dashboard overview charts. It reports activity derived from the memories that already exist (no new tracking). It works with a dashboard session or a bearer token, is limited to 120 requests per minute per user like the other dashboard reads, and answers `401` without credentials.

| Parameter | Rule |
| --- | --- |
| `days` | optional integer from 7 to 365, default 30. Anything else answers `422` |

```json
{"data": {
  "range": {"days": 30, "from": "2026-09-15", "to": "2026-10-14"},
  "totals": {"memories": 128, "projects": 6, "session_summaries": 31},
  "daily": [{"date": "2026-09-15", "memories": 0, "session_summaries": 0}],
  "by_type": [{"type": "decision", "count": 12}],
  "by_project": [{"project": "memry", "count": 20}]
}}
```

Rules:

- **Who can see what.** Every number counts only the memories the caller can read, with the same rules as the other dashboard reads: the acting team, the granted projects for a restricted member, and `personal`-scope memories only for their author (another member's personal memories are never counted). A caller with no team gets zeros and empty arrays.
- **Days are UTC calendar days**, taken from `created_at`. The range is the last `days` days ending today (UTC), today included, so `from` is `days - 1` days before `to`.
- **`totals`** are all time, not limited to the range. `totals.memories` counts every readable memory, session summaries included, the same way `daily[].memories` does; `session_summaries` is the subset of type `session_summary`. `projects` is the number of distinct projects among the readable memories.
- **`daily`** has one row per day of the range, oldest first, zero-filled.
- **`by_type`** counts the memories created within the range, ordered by count descending then type ascending.
- **`by_project`** counts the memories created within the range, top 10, ordered by count descending then name ascending. Memories without a project are not listed.

## Dashboard: token usage

`GET /api/usage` feeds the dashboard's "Token usage" chart with the caller's own Claude Code usage, read from the points stored by the [OTLP usage ingest](#otlp-usage-ingest). It works with a dashboard session or a full-access bearer token (the `usage:write`-only token answers `403`), is limited to 120 requests per minute per user like the other dashboard reads, and answers `401` without credentials.

| Parameter | Rule |
| --- | --- |
| `days` | optional integer from 1 to 90, default 30. Anything else answers `422` |

```json
{"data": {
  "days": 30, "from": "2026-09-15", "to": "2026-10-14",
  "daily": [{"date": "2026-09-15", "tokens": {"input": "0", "output": "0", "cacheRead": "0", "cacheCreation": "0"}, "cost_usd": "0.000000"}],
  "by_model": [{"model": "claude-sonnet-4-6", "tokens": "9025", "cost_usd": "0.310000"}],
  "totals": {"tokens": "9175", "cost_usd": "0.750000"}
}}
```

Rules:

- **Who can see what.** Only the caller's own usage on the acting team (the same team resolution as the other dashboard reads). Other members' usage and the caller's usage on other teams are never counted. A caller with no team gets zeros and an empty `by_model`.
- **Days are UTC calendar days**, taken from each point's end time (`timeUnixNano`). The window is the last `days` days ending today (UTC), today included, so `from` is `days - 1` days before `to`. Unlike `/api/stats`, `totals` are limited to the window too.
- **`daily`** has one row per day of the window, oldest first, zero-filled. `tokens` holds the four known Claude Code token types.
- **Unknown token types.** The ingest stores the `type` attribute as sent and currently drops token points whose type is not one of the four known ones, so today every stored token point is one of them. Should another type ever be stored, it is left out of `daily[].tokens` but still counted in `by_model[].tokens` and `totals.tokens`.
- **`by_model`** sums tokens (every type) and cost per model within the window, ordered by tokens descending then model ascending (on the numeric sums, never on their text). A model with only cost points appears with `"tokens": "0"`. It lists at most the **top 20** models (`QueryUsageRepository::TOP_MODELS`). When there are more, every model past the 20th is summed into **one remainder entry**, placed last: `{"model": null, "tokens": "<sum>", "cost_usd": "<sum>"}`. The model is `null`, not a name like `"other"`, because a real model could have any name. With the remainder, `by_model` still sums exactly to `totals`. There is no remainder entry when there are 20 models or fewer.
- **`cost_usd`** is in US dollars, as an **exact decimal string** with exactly 6 decimals (`"10000000000.000001"`, zero is `"0.000000"`), taken straight from the database's `numeric` sum rounded to 6 decimals. It is a string, not a JSON number, because money is never passed through binary floating point: a float cannot hold a value such as `10000000000.000001` and would silently change it. Clients parse it for display (for example with `Number(...)` for a chart, accepting that rounding there, or a decimal library where exactness matters).
- **One snapshot.** `daily`, `by_model` and `totals` come from a single SQL statement (`GROUP BY GROUPING SETS`), so they always agree: the per-type sums of `daily` plus any unknown types equal `totals.tokens`, and the `by_model` sums equal `totals`, even while new points arrive.
- **Exact strings, never JSON numbers.** Every count and every amount of money in this response (`daily[].tokens.*`, `by_model[].tokens`, `totals.tokens` and every `cost_usd`) is an exact decimal string taken straight from the database's `numeric` sum: tokens as whole numbers with no decimal part (zero is `"0"`), cost with exactly 6 decimals. JSON numbers are read as IEEE doubles in JavaScript and silently lose precision above 2^53 (`Number.MAX_SAFE_INTEGER`), and a PHP integer would stop at 2^63; a string is exact at any size. Clients parse the strings for display.

## Dashboard: deleting

`DELETE /api/memories/{id}` hard-deletes one memory (the row is removed; nothing references it, so there are no cascades). It works with a dashboard session or a bearer token and shares the `dashboard` rate limit (120 per minute per user). Under a session it needs the CSRF token like any state-changing request.

Visibility comes first. A memory the caller cannot read (a missing id, an id out of range, another team, an ungranted project, another member's `personal` memory, even for the owner) answers the same `404` as `GET /api/memories/{id}`, so a hidden memory cannot be told apart from a missing one. Only then are the permissions checked:

| Caller | Memory | Result |
| --- | --- | --- |
| Author | their own, any scope | `204` |
| Owner or admin | any `project`-scope memory they can read | `204` |
| Member | another member's `project`-scope memory | `403` `{"message": "You can only delete your own memories."}` |
| Anyone | not readable (see above) | `404` |

| Status | When |
| --- | --- |
| `204` | The memory is deleted |
| `401` | No credentials |
| `403` | The caller can read the memory but may only delete their own |
| `404` | Missing, out of range or not readable |
| `429` | Rate limit exceeded |

```bash
curl -s -X DELETE -b jar -H "Origin: https://app.memry.com.mx" -H "X-XSRF-TOKEN: <token>" https://<api-host>/api/memories/12
# 204
```

## Account deletion

`DELETE /api/account` deletes the authenticated user and all of their data: memories, prompts, every token (not only the one used for the request) and pending login codes. The body must repeat the account email, so a stray call cannot delete the account. The email is trimmed and lowercased, as in the login endpoints. It shares the rate limit of `/mcp/memory` and `/api/context`.

```bash
curl -s -X DELETE https://<your-host>/api/account \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" -d '{"email":"ada@example.com"}'
# 204
```

| Status | When |
| --- | --- |
| `204` | The account and all of its data are deleted |
| `401` | Missing or invalid token |
| `422` | `email` missing, not a string, or not the email of the account |
| `429` | Rate limit exceeded |

**Under a dashboard session** the same request works (with the CSRF token) and also ends the session: the user is signed out, the session is invalidated and its CSRF token regenerated, so the next call answers `401`. The response stays `204`. A mismatched `email` answers `422` and keeps the session. `DELETE /api/auth/tokens/{id}` also works under a session.
