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
| `GET /api/auth/tokens` | `200` | `{"data": [{"id", "name", "created_at", "last_used_at", "expires_at", "current"}]}` for the caller's tokens only. `expires_at` is when the token expires from inactivity; `current` marks the token used for the request. Hashes and plaintext are never returned |
| `DELETE /api/auth/tokens/{id}` | `204` | That token is revoked; the others stay valid |
| | `404` | Unknown id or a token of another user (indistinguishable) |
| | `429` | Rate limit exceeded for the user |

Tokens expire after 30 days without use (`MEMRY_TOKEN_IDLE_DAYS`); an expired token answers `401 {"message": "Unauthenticated."}`, like any invalid token. `php artisan memory:prune-tokens` (scheduled daily) removes them.

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
