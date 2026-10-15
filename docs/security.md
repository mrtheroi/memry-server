# Security

Security notes for memry-server. See [API](api.md) for the endpoints they apply to.

## Authentication and data access

- `/mcp/memory` and `/api/context` require a Sanctum token and return 401 without one.
- The user and the team come from the token, never from tool arguments. Every read and write is scoped to the request's team: the token's team, or the user's personal team for older tokens.
- Owners and admins see all the team's projects; members see nothing until project grants exist. Memories without a project are visible only to owners and admins.
- `get-memory` answers `Memory not found.` for another team's id, the same as for a missing id, so it does not reveal that the memory exists. Every id listed by `get-context` or `search-memory` can be read with `get-memory` under the same token.
- Self-hosted upgrades backfill teams automatically during `migrate`; the migration refuses to finish if any memory or prompt is left without a team.
- `DELETE /api/account` requires the account email in the body and removes all of the user's data (memories, prompts, tokens and login codes).
- Every tool validates its input on the server, including maximum lengths (255 characters for identifiers, 20,000 for content).

## Tokens

- Only the SHA-256 hash of each token is stored.
- A leaked token is revoked with `php artisan memory:revoke <email>` (every token of the user); a client can revoke its own token with `DELETE /api/auth/token`, or any one of its user's tokens with `DELETE /api/auth/tokens/{id}` (`GET /api/auth/tokens` lists them without secrets).
- A token expires after 30 days without use (`MEMRY_TOKEN_IDLE_DAYS`, default 30); a token used regularly never expires. The window counts from `last_used_at`, or from creation if it was never used. An expired token gets the same `401 {"message":"Unauthenticated."}` as an invalid one.
- Optional absolute lifetime since creation: set `expiration` (minutes) in `config/sanctum.php`; it is `null` (off) by default and combines with the idle window (the earliest wins).
- `memory:prune-tokens` deletes expired tokens and runs daily from the scheduler.
- **Abilities.** Every token-authenticated route (the API under `auth:sanctum` and the MCP endpoint) requires the full-access ability (`abilities:*`), declared once on the route group, so a new route inherits it. A test walks the registered routes and fails if any `auth:sanctum` route, other than the OTLP ingest, accepts a token limited to `usage:write`.
- **Usage tokens.** The OpenTelemetry exporter of Claude Code needs a bearer token in plaintext in `~/.claude/settings.json`. For that, `POST /api/auth/usage-token` issues a dedicated `memry-usage` token whose only ability is `usage:write`: it cannot read or write memories, list or revoke tokens, delete the account or issue tokens (all answer `403`). It can only call the OTLP usage ingest endpoint, `POST /api/otlp/v1/metrics`. Leaking it exposes, at most, the ability to submit usage numbers for that user; revoke it with `DELETE /api/auth/tokens/{id}` or by rotating it. A user has at most one; it expires after 30 days without use like any token.
- Sanctum updates `last_used_at` on every authenticated request (one small UPDATE per request).

- **Usage ingest.** `POST /api/otlp/v1/metrics` (`usage:write`, JSON only, body capped at 1 MB and 5000 points) stores only numbers: per user, team, metric, model, token type, query source, Claude session id and time window, plus the value. No prompt text, tool names, file paths or other attributes are stored, and the user and team come from the token, never from payload attributes such as `user.email`. A retried export cannot double count (unique per series and window). Rows are removed with the user or team; time-based retention is not implemented yet.

## Login codes

- Codes are stored only as HMAC-SHA256 hashes.
- They expire after 5 minutes, work once, are replaced by any newer code for the same email, and are burned after 5 wrong attempts.
- They are deleted a day after they expire by the daily `model:prune` job (the scheduler must run, see [Development](development.md#scheduler)).
- `POST /api/auth/code` answers the same whether or not the email has an account.

## Dashboard sessions, CORS and cookies

The dashboard signs in with a Sanctum session cookie (`POST /api/session`, see [API](api.md#dashboard-session-authentication)). Bearer-token clients are unaffected.

- **CORS** (`config/cors.php`): applies to `api/*` and `sanctum/csrf-cookie`. The only allowed origins are the ones in `MEMRY_DASHBOARD_URL` (comma-separated, exact match, no wildcard); it is empty by default, so no browser origin is allowed until you set it. Credentials are allowed (`Access-Control-Allow-Credentials: true`), which is why a wildcard origin is never used. An origin that is not listed gets no `Access-Control-Allow-Origin` header.
- **Stateful domains**: only requests whose `Origin` or `Referer` host is in `SANCTUM_STATEFUL_DOMAINS` use the session; everything else is authenticated by bearer token only. Set it to the dashboard host (`app.memry.com.mx`).
- **Session cookie**: `HttpOnly`, `SameSite=Lax` (the dashboard and the API are the same site, on subdomains of one registrable domain), stored in the database. In production set `SESSION_DOMAIN=.memry.com.mx` and `SESSION_SECURE_COOKIE=true` (HTTPS only). Do not host the dashboard on a different registrable domain: `SameSite=Lax` would stop the browser sending the cookie.
- **CSRF**: every state-changing session request needs the `X-XSRF-TOKEN` header, obtained from `GET /sanctum/csrf-cookie`; otherwise the server answers `419`. Bearer-token requests are not subject to it.
- **Session fixation**: the session id is regenerated when a session is opened, and ended and the CSRF token regenerated on `DELETE /api/session`.
- **Login**: `POST /api/session` verifies the emailed code with the same rules as `POST /api/auth/token` (see Login codes) and shares its rate limit; it issues no token. `DELETE /api/session` shares the limit of the token management endpoints, and `GET /api/me` that of `/mcp/memory`.
## Rate limits

Beyond these limits the server returns 429.

| Scope | Limit |
| --- | --- |
| `/mcp/memory`, `/api/context`, `GET /api/me` and `DELETE /api/account` (shared) | 60 requests per minute per user |
| `POST /api/auth/code` | 3 requests per 10 minutes per email, and 10 per hour per IP |
| `POST /api/auth/token` and `POST /api/session` (shared) | 20 requests per minute per IP |
| `POST /api/otlp/v1/metrics` | 120 requests per minute per user |
| `GET /api/auth/tokens`, `DELETE /api/auth/tokens/{id}`, `DELETE /api/auth/token` and `DELETE /api/session` (shared) | 10 requests per minute per user |
