# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.18.1] - 2026-10-04

### Fixed

- **Release image build**: the Vite assets stage of the `Dockerfile` now runs on the build machine's own platform (`--platform=$BUILDPLATFORM`) instead of under QEMU emulation for `linux/arm64`; the built JavaScript and CSS are the same on every architecture. The 0.18.0 release run hit the 60-minute job timeout while emulating `npm ci` and the Vite build, so **the 0.18.0 image was never published**: use 0.18.1, which has the same features (the one-command `install.sh`)
  - The release job timeout is raised from 60 to 90 minutes as a safety margin
- `install.sh`, the README and `docs/self-hosting.md` point at 0.18.1

---

## [0.18.0] - 2026-10-04

Pairs with memry CLI 0.7.0, which reads the token from `MEMRY_TOKEN` in `memry setup --token`.

### Added

- **One-command install**: `install.sh` (POSIX sh, macOS and Linux) installs memry Community and connects your agents: `curl -fsSLo install.sh https://raw.githubusercontent.com/mrtheroi/memry-server/v0.18.0/install.sh && sh install.sh --email you@example.com`
  - Options: `--email` (required), `--dir` (default `~/memry-community`), `--port` (default 8000), `--agents` (passed to `memry setup`), `--no-cli` and `--help`
  - Checks for Docker, Docker Compose v2, curl and a running Docker daemon first, and links to Docker's install docs when one is missing; it never installs Docker
  - Pinned to a release: it downloads `docker-compose.yml` and `docker/community.env.example` from tag `v0.18.0` and uses `ghcr.io/mrtheroi/memry-server:0.18.0`; `MEMRY_VERSION` and `MEMRY_IMAGE` override them
  - Writes `.env` (mode 600) with a generated `APP_KEY`, a random database password, `APP_URL`, `APP_PORT` and `MEMRY_IMAGE`; on a later run an existing `.env` is kept and only empty required values are filled in, so a key or password in use is never regenerated
  - A new `.env` gets a `COMPOSE_PROJECT_NAME` unique to the install directory (its name plus a checksum of its absolute path), so two installs in directories with the same name do not share a database volume; an existing install without it keeps Compose's default project
  - Refuses to create a new `.env` when the directory's database volume already exists (under its own project or Compose's default one, named after the directory), and explains how to restore the old `.env` or remove the volume
  - Runs every Compose command with the variables of the Compose file and `.env`, and every `COMPOSE_*` variable, unset, so values exported in the calling shell never override `.env`; reads `.env` the way Compose does (quotes, inline ` #` comments) and takes the server URL from the port Compose published
  - Downloads go to a temporary file first, so a failed download never leaves a partial file behind
  - Pulls the images, migrates, starts `app` and `scheduler`, waits up to 120 seconds of wall-clock time for `/up`, then creates the user and a token with the entrypoint's `token <email>`
  - The token is kept in memory only and handed to `memry setup --url http://localhost:<port> --token` through `MEMRY_TOKEN`; memry is installed with Homebrew when missing and upgraded when older than 0.7.0. Without Homebrew, with `--no-cli` or when setup fails, the token is printed once with the setup command
  - Each run issues a new token; earlier tokens stay valid until revoked
- **Install script tests**: `tests/install/install_test.sh` unit tests the script with stubbed `docker`, `curl`, `memry` and `brew`, and checks that the pinned version matches the latest CHANGELOG release; `docker/install-e2e.sh` runs it twice against a real image. The `docker` workflow runs ShellCheck and the unit tests, and the end-to-end test against the image built in CI

---

## [0.17.0] - 2026-10-03

### Added

- **memry Community Docker image**: a `Dockerfile` builds a self-hostable image of the server, served by FrankenPHP on PHP 8.4, with PostgreSQL as the only required dependency
  - Multi-stage build (Vite assets, production-only Composer dependencies); the container runs as a non-root `memry` user on port 8000, logs to stderr and has a healthcheck on `/up`
  - The `memry` entrypoint takes `serve` (default: caches config, routes and views, then starts FrankenPHP), `migrate`, `scheduler` (`schedule:work`), `token <email>` (creates the user if needed and prints a new token) and `key` (prints a new `APP_KEY`); `php`, `sh`, `bash` and `frankenphp` run as-is, and any other command is passed to `php artisan`
  - Every command except `key` and the raw `php`, `sh`, `bash` and `frankenphp` commands refuses to start when `APP_KEY` is empty or `DB_CONNECTION` is not `pgsql`
  - `migrate` runs `migrate --force --isolated`, so once the database is initialised several containers starting at once do not run migrations twice; `AUTO_MIGRATE=true` makes `serve` migrate before it starts
  - On a brand-new database the cache table that holds that lock is first created without it, so concurrent first migrations can race: for the first deployment, run the one-off `migrate` service once before starting several app containers with `AUTO_MIGRATE`
- **Docker Compose setup**: `docker-compose.yml` with `app`, `scheduler`, `migrate` and `postgres` (PostgreSQL 16) services, configured from `docker/community.env.example`
  - `MEMRY_IMAGE` selects the image: a local build (`memry-server:local`, the default) or a published release (`ghcr.io/mrtheroi/memry-server:<version>`)
- **Published image on GHCR**: pushing a `vX.Y.Z` tag publishes a `linux/amd64` and `linux/arm64` image to `ghcr.io/mrtheroi/memry-server`, tagged `X.Y.Z` (no floating `X.Y` tags)
  - The image is pushed only after the smoke test passes
  - `latest` is moved at the end of the run, and only when the tag is the highest stable `vX.Y.Z` tag at that moment, so an older or backport tag normally does not take it over
  - One race window remains: if a higher tag is pushed between that check and a lower tag's `latest` update, and the higher tag's run finishes first, `latest` can end up on the older release; re-running the higher tag's workflow run moves it back (see `docs/self-hosting.md`)
- **Docker smoke test and CI**: `docker/smoke.sh` builds the image, starts PostgreSQL, migrates, starts the app, issues a token through the entrypoint and checks that `/mcp/memory` lists the six memory tools; the `docker` workflow runs it on every pull request and push to `main`
- **`TRUSTED_PROXIES`**: a new `config/trustedproxy.php` reads `TRUSTED_PROXIES` (`*` for the calling proxy, or a comma separated list of addresses / CIDR ranges), so `X-Forwarded-*` headers are honoured behind a TLS-terminating reverse proxy
  - Unset by default: forwarded headers are ignored, and Laravel Cloud keeps working as before
- **Self-hosting guide**: `docs/self-hosting.md`, linked from the README, covers the published image and source builds, entrypoint commands, an existing PostgreSQL, migrations, users and tokens, connecting agents (memry CLI 0.6.0+ token prompt or `claude mcp add`), email login, reverse proxy and TLS, upgrades, backups, the smoke test and the release process

---

## [0.16.0] - 2026-09-29

### Added

- **Account deletion**: `DELETE /api/account` deletes the authenticated user and everything tied to them, and answers 204
  - The body must repeat the account email (`{"email": "..."}`, trimmed and lowercased like the login endpoints); any other value answers 422 and deletes nothing
  - Memories, prompts, every token of the user (not only the current one), login codes, password reset tokens and sessions are removed in one database transaction
  - It shares the per-user rate limit of `/mcp/memory` and `/api/context` (60 requests per minute)

---

## [0.15.3] - 2026-09-29

### Removed

- **`GET /api/user`**: the unused Laravel scaffold route is gone, so the API no longer returns the raw user model to any token holder
  - The endpoint now answers 404; no client (memry-cli, hooks, MCP tools) called it

---

## [0.15.2] - 2026-09-29

### Changed

- **Renamed to memry-server**: the GitHub repository moved from `mrtheroi/db-mcp` to `mrtheroi/memry-server`, and the docs follow the new name
  - The README title and the example hook path now say `memry-server`, and the Claude Code MCP server is registered as `memry` (matching memry-cli)
  - The legacy `hooks/claude-code/session-start.sh` protocol block now points at the `memry` MCP tools
  - `package.json` and `package-lock.json` are named `memry-server`

---

## [0.15.1] - 2026-09-29

### Changed

- **Shorter login code lifetime**: email login codes now expire 5 minutes after they are issued, down from 10
  - The lifetime is defined once, in `LoginCode::TTL_MINUTES`, and both the expiry and the email text (HTML body, preheader and plain-text part) read it, so they cannot drift apart
  - Pruning is unchanged: codes are still deleted a day after they expire

---

## [0.15.0] - 2026-09-29

### Added

- **Branded login email**: the login code email now has an HTML part with the memry logo, sent next to the existing plain-text part
  - The code sits in its own large, letter-spaced monospace block, so it is easy to read and copy
  - A hidden preheader ("Your memry login code expires in 10 minutes.") keeps the code out of inbox and lock-screen previews
  - The logo is served from `public/images/memry-logo-horizontal.png` through `asset()`, so it points at the host that received `POST /api/auth/code`

---

## [0.14.0] - 2026-09-29

### Added

- **Login code pruning**: a daily scheduled `model:prune` deletes login codes that expired more than a day ago
  - Every code expires 10 minutes after it is issued, so used and burned codes are deleted too
  - Codes are kept for one day after they expire, for debugging
  - Needs the Laravel scheduler running (on Laravel Cloud, enable it on the App compute cluster)

---

## [0.13.1] - 2026-09-29

### Fixed

- **Email case in artisan commands**: `memory:token`, `memory:revoke` and `memory:merge-projects --email` now trim and lowercase the email, like the login endpoints already did
  - `Me@Example.com` finds the existing user `me@example.com` instead of reporting it as missing
  - `memory:token --create` stores the new user's email lowercased, so it can no longer create a second user that differs only in case

---

## [0.13.0] - 2026-09-29

### Added

- **Token revocation endpoint**: `DELETE /api/auth/token` with a Sanctum Bearer token revokes only the token used for the request, so the `memry` CLI can log out from the terminal
  - Answers `204` with no body; the other tokens of the same user stay valid
  - Without a token, or with an invalid or already revoked token, answers `401` with `{"message": "Unauthenticated."}`

---

## [0.12.0] - 2026-09-28

### Added

- **`repo` argument for `session-summary`**: an optional string of at most 255 characters (trimmed, kept as written, not normalized) naming the repository the session worked in, for products that span several repositories
  - With a `repo`, the summary title becomes `Session summary: {project} ({repo})`; without it (or blank) it stays `Session summary: {project}`
  - Stored in the title only: no schema change
- **`memory:merge-projects` command**: `php artisan memory:merge-projects {from} {to} [--email=]` moves the observations and prompts of project `from` into project `to` (both normalized like `project`), for every user or only the user of `--email`
  - Non-interactive: no confirmation prompt; prints how many observations and prompts were moved and how many `topic_key` collisions (same user and `topic_key` in both projects) are left to resolve
  - Collisions are never deleted: both rows end up in `to` and must be resolved by hand
  - Moved rows keep their timestamps, so `get-context` keeps its order, and stay searchable under `to` (`search_vector` only covers title and content)
  - Fails without moving anything when `from` and `to` are the same after normalization, when a name is blank, or when the `--email` user does not exist
  - Runs in a single transaction

### Changed

- **`get-context` shows `## Recent sessions` instead of `## Latest session`**, so parallel sessions in different repositories of the same project are all visible (also in `GET /api/context`)
  - The last 3 `session_summary` memories of the project, most recently updated first: the newest in full, the other two as one line each with their `updated_at` date (`Y-m-d H:i`, UTC) and a 300-character preview of their content
  - Every other layer and bound is unchanged

---

## [0.11.1] - 2026-09-28

### Fixed

- Login code emails failed with `Class "Resend" not found` (HTTP 500) when `MAIL_MAILER=resend`: the `resend/resend-php` package required by Laravel's Resend transport is now installed

---

## [0.11.0] - 2026-09-28

### Added

- **Passwordless email login**: a one-time code sent by email is exchanged for a Sanctum token, so the `memry` CLI can log in entirely from the terminal
  - `POST /api/auth/code` with `{email}` (required string email of at most 255 characters, trimmed and lowercased) emails a random 6-digit code valid for 10 minutes and always answers `202` with `{"message": "If the email is valid, a login code has been sent."}`
  - Issuing a new code invalidates the previous unused codes of the email; only an HMAC-SHA256 hash of the code (keyed with the app key) is stored, in the new `login_codes` table
  - `POST /api/auth/token` with `{email, code}` (`code` is a 6-digit string) answers `200` with `{"token": "..."}`, a Sanctum token named `memry-cli` that authenticates the MCP route and `GET /api/context`
  - Signup is open: an unknown email creates the user (name from the email local part, random password, email marked as verified); a known email reuses its user
  - A wrong, expired, already used or superseded code answers `422` with `{"message": "Invalid or expired code."}`; each wrong code counts as an attempt and the 5th wrong attempt burns the code, so even the right code fails afterwards
  - Rate limited: `auth-code` allows 3 requests per 10 minutes per email and 10 per hour per IP, `auth-token` allows 20 requests per minute per IP (`429` when exceeded)

---

## [0.10.0] - 2026-09-28

### Added

- **`hooks/claude-code/session-start.sh`**: a Claude Code `SessionStart` hook that loads the memry context of the current project into the session through `GET /api/context`
  - Reads `url` and `token` from `${MEMRY_CONFIG:-~/.config/memry/config.json}`; the token is only sent in the `Authorization` header, passed to curl through stdin (`-H @-`) so it never appears in the process list
  - The project is the basename of the git top-level of the session `cwd` (the basename of `cwd` outside git, the working directory when `cwd` is empty), URL-encoded and normalized by the server
  - Prints a short protocol block for the `db-memory` MCP tools followed by the endpoint body
  - Fails silently: a missing or incomplete config, a curl error, an HTTP error or the 3-second timeout print nothing, and the script always exits `0`

---

## [0.9.0] - 2026-09-28

### Added

- **`GET /api/context?project={name}`**: a plain HTTP endpoint that returns exactly the text of the `get-context` tool, so a shell hook (e.g. a Claude Code `SessionStart` hook using `curl`) can load the project context without speaking MCP JSON-RPC
  - Authenticated with the same Sanctum bearer tokens as the MCP route (`401` without a valid token) and scoped to the authenticated user
  - Shares the `mcp` rate limiter (60 requests per minute per user, counted together with MCP requests)
  - `project` is a required string of at most 255 characters (`422` with the validation errors otherwise) and is normalized like in `get-context`
  - Responds `200` with `Content-Type: text/plain; charset=UTF-8`, including `No context found for project {project}.` when there is nothing to show

---

## [0.8.0] - 2026-09-28

### Changed

- **`get-context` returns a bounded, layered context** instead of the 20 most recent memories in full, so its size stays predictable when injected at every session start
  - `## Latest session`: only the most recent `session_summary` of the project, in full; older summaries are omitted because each summary is cumulative
  - `## Project knowledge`: up to 20 memories with a `topic_key`, most recently updated first, each as one line with a 300-character preview of its content (newlines collapsed, `…` when truncated)
  - `## Recent memories`: up to 10 other memories, most recently updated first, title only
  - Empty sections are omitted, and the output ends with a hint to call `get-memory` with an id to read a memory in full
  - Still scoped to the authenticated user and the normalized project; `No context found for project {project}.` when there is nothing to show

---

## [0.7.0] - 2026-09-28

### Changed

- **Project names are normalized**: every `project` is trimmed, lowercased, and has repeated `--` collapsed to `-` and `__` to `_`, so `dbmcp`, `dbMcp` and ` DbMcp ` are the same project
  - Applied on write: `save-memory`, `session-summary` and `save-prompt` store the normalized name; an empty or whitespace-only `project` is stored as no project
  - Applied on query: `get-context` and the `project` filter of `search-memory` normalize the argument, so `DbMcp` finds memories saved as `dbmcp`
  - The `topic_key` upsert matches across spellings: saving with `project: DbMcp` updates the existing `dbmcp` memory instead of creating a new one
  - **Note**: existing rows are not migrated; names stored before this version keep their original spelling

---

## [0.6.0] - 2026-09-25

### Added

- **`get-memory` tool**: returns the full content of one memory by its `id`, in the same `#id [type] title` format as `search-memory`, so agents can read a memory in full on demand
  - Validates `id` as a required integer of at least 1

### Security

- **Users can only read their own memories**: an id that belongs to another user returns the same `Memory not found.` as an id that does not exist, so the existence of other users' memories is not leaked

---

## [0.5.0] - 2026-09-25

### Added

- **`project` filter on `search-memory`**: an optional `project` argument returns only the memories of that project; without it, the search still covers all of the user's projects
  - Validated with `max:255`, like the other identifiers

---

## [0.4.0] - 2026-09-25

### Added

- **`memory:revoke` command**: `php artisan memory:revoke {email}` revokes every Sanctum token of a user and reports how many were revoked
  - Fails with a clear message when the user does not exist

### Security

- **Token revocation without `tinker`**: a leaked token can be invalidated in one command; the user's other agents are disconnected too, and other users' tokens are untouched

---

## [0.3.1] - 2026-09-25

### Fixed

- **Long values no longer crash the tools**: values longer than a `varchar(255)` column used to fail in Postgres and return "An internal server error occurred."; tools now return a validation message instead
- **Session summaries with long project names**: the generated title `Session summary: {project}` is cut to 255 characters so a 255-character project name is accepted; the full name stays in `project`

### Security

- **Maximum input sizes**: every tool validates `max:255` for identifiers (`session_id`, `type`, `title`, `project`, `topic_key`, `query`) and `max:20000` for `content`, so a single call cannot store megabytes

---

## [0.3.0] - 2026-09-25

### Security

- **Rate limit on `/mcp/memory`**: each user can make at most 60 requests per minute; beyond that the server returns `429 Too Many Requests`
  - Named limiter `mcp` in `AppServiceProvider`, keyed by the authenticated user, applied after `auth:sanctum`
  - Protects against agents stuck in a loop and leaked tokens

---

## [0.2.0] - 2026-09-25

### Added

- **`--create` option for `memory:token`**: `php artisan memory:token {email} --create` creates a missing user without asking
  - Needed in non-interactive consoles (such as the Laravel Cloud command runner), where the confirmation defaults to "no"

---

## [0.1.0] - 2026-09-25

### Added

- **Memory MCP server**: `MemoryServer` exposed over HTTP at `POST /mcp/memory` with `laravel/mcp`
  - Agent instructions: recall before working, save after deciding, never store secrets
- **`save-memory` tool**: saves observations (decisions, bug fixes, discoveries, conventions)
  - Upsert by `topic_key`: the same key in the same project and scope updates instead of duplicating (`SaveObservation` use case)
  - Server-side validation of `session_id`, `type`, `title` and `content`
- **`search-memory` tool**: Postgres full-text search
  - Generated `search_vector` column (`tsvector`, `english` configuration) with a GIN index
  - Ranking with `ts_rank`: the title weighs more than the content
  - `limit` validated between 1 and 20 (default 10), and "No memories found." when nothing matches
- **`session-summary` tool**: saves the session summary as an observation of type `session_summary`
- **`get-context` tool**: returns the 20 most recently updated memories of a project
- **`save-prompt` tool**: stores the user prompt verbatim in the `user_prompts` table
- **`memory:token` command**: `php artisan memory:token {email}` issues a Sanctum token and creates the user only after confirmation
- **Hexagonal architecture**: `app/Memory/{Domain,Application,Infrastructure}` with `MemoryRepository` and `PromptRepository` ports
- **Test suite**: 34 Pest tests against a local Postgres 17 in Docker
- **Versioning**: server version in `config/api.php`

### Security

- **Authentication**: `/mcp/memory` requires a Sanctum token (`auth:sanctum`) and returns 401 without a valid one
- **User isolation**: `user_id` comes from the token, never from tool arguments, and every query is scoped to it
- **Tokens**: only the SHA-256 hash is stored; the plain-text token is shown once
