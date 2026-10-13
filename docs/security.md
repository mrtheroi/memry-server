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
- Sanctum updates `last_used_at` on every authenticated request (one small UPDATE per request).

## Login codes

- Codes are stored only as HMAC-SHA256 hashes.
- They expire after 5 minutes, work once, are replaced by any newer code for the same email, and are burned after 5 wrong attempts.
- They are deleted a day after they expire by the daily `model:prune` job (the scheduler must run, see [Development](development.md#scheduler)).
- `POST /api/auth/code` answers the same whether or not the email has an account.

## Rate limits

Beyond these limits the server returns 429.

| Scope | Limit |
| --- | --- |
| `/mcp/memory`, `/api/context` and `DELETE /api/account` (shared) | 60 requests per minute per user |
| `POST /api/auth/code` | 3 requests per 10 minutes per email, and 10 per hour per IP |
| `POST /api/auth/token` | 20 requests per minute per IP |
| `GET /api/auth/tokens`, `DELETE /api/auth/tokens/{id}` and `DELETE /api/auth/token` (shared) | 10 requests per minute per user |
