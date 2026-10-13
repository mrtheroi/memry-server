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
