# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Upgrade notes

- **Requires the teams release (0.19.0) first, with `memory:backfill-teams --check` reporting 0.** The new migrations `set_observations_team_id_not_null` and `set_user_prompts_team_id_not_null` make `team_id` NOT NULL on memories and prompts. If any row still has no team they stop before changing anything with `Run memory:backfill-teams first: <table> has N rows without team_id`; run `memory:backfill-teams`, confirm `--check` is 0, then migrate again. See "Upgrades" in `docs/self-hosting.md`

### Added

- **Dashboard session authentication.** The private dashboard (a SPA on its own subdomain) can sign in with Sanctum session cookies: `POST /api/session` verifies the emailed login code with the same rules as `POST /api/auth/token` (it issues no token and regenerates the session id), `DELETE /api/session` signs out (422 under a bearer token, which it never revokes), and `GET /api/me` returns the user and the acting team under a session or a bearer token. `GET /api/auth/tokens` marks no token as `current` under a session, and `DELETE /api/auth/token` answers 422 pointing to `DELETE /api/session`. State-changing session requests need the CSRF token from `GET /sanctum/csrf-cookie`. New `config/cors.php`: credentials on, and the only allowed origins are those in the new `MEMRY_DASHBOARD_URL` (empty by default, so no browser origin is allowed). Set `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN` and `SESSION_SECURE_COOKIE` for the dashboard (see `docs/api.md` and `docs/security.md`). CLI and MCP bearer clients are unchanged
### Changed

- **Every memory and prompt belongs to a team.** `observations.team_id` and `user_prompts.team_id` are NOT NULL, applied without blocking writes (a `CHECK ... NOT VALID`, then `VALIDATE`, then `SET NOT NULL`, and the temporary check is dropped; rolling back drops the NOT NULL only). A write from a context without a team (a user whose team cannot be resolved) is now refused with `A team is required to write.` instead of writing a row without a team, and the memory and prompt repositories throw the same error if handed no team; nothing is provisioned on write. `personal_access_tokens.team_id` stays nullable on purpose (a token whose user is gone, or a legacy token, resolves through the personal team). `memory:backfill-teams` and its `--check` remain

### Fixed

- **`install.sh --doctor` edge cases.** When `docker compose config` fails and the `COMPOSE_PROJECT_NAME` in `.env` is an interpolation expression (for example `${PROJECT_NAME:-memry}`), the doctor now says the name cannot be resolved, makes no volume or container claim (`containers: not checked`) and prints no `docker volume rm`, instead of searching for a literal volume. A `--dir` path that does not exist and contains a `.`, `..` or empty (`//`) segment (after one leading `./` and one trailing `/`) is now refused with a usage error before any Docker call, even on a host without Docker ("Pass the install directory as the installer printed it: an absolute path without . or .. segments."), because a symlink changes where `..` leads and the doctor could otherwise look at, and offer to delete, another install's volume. Existing directories are canonicalized as before

---

## [0.19.0] - 2026-10-14

### Upgrade notes

- **Run the migrations.** This release adds the team model (teams, memberships, projects, team columns on memories, prompts and tokens, and validated foreign keys). Restarting `app` does **not** migrate unless `AUTO_MIGRATE=true` (the default is `false`): run the installer again, or the one-off migrate service (`docker compose run --rm migrate`), as described in "Upgrades" in `docs/self-hosting.md`. Stop the app and the scheduler first, so rows written by the old app during the upgrade are not left without a team. The migration `backfill_teams_then_assert_complete` gives existing users a personal team and fills the team of their memories, prompts and tokens, then fails (`Run memory:backfill-teams first: N rows without team_id`) if any memory or prompt still has no team; the `app` container also runs `memory:backfill-teams` on start, before serving
- **Tokens unused for 30 days or more stop working.** A token is now idle-expired after `MEMRY_TOKEN_IDLE_DAYS` (default 30) without use and gets a 401; run `memry setup` to create a new one. Tokens in regular use are not affected
- **Check the result** with `php artisan memory:backfill-teams --check` (inside the container: `docker compose run --rm app php artisan memory:backfill-teams --check`): it writes nothing, lists what is still missing and exits 0 only when nothing is

### Added

- **Team model** (#37-#45): every user now belongs to a team, the foundation for sharing memories. This release changes no response and no command output; the characterization goldens pass untouched
  - **Tables** (#37): `teams` (unique `slug` of up to 32 characters, `personal_team`, `plan`, `seats`; at most one personal team per owner, enforced by a partial unique index; a user without one is reported by `memory:backfill-teams --check`), `team_user` (role `owner`, `admin` or `member`, enforced by a `CHECK`; at most one owner membership per team, which must match `teams.owner_id`; a team without one fails closed and is reported by `memory:backfill-teams --check`), `projects` (name unique per team, so the same name can exist in different teams) and `project_grants` (schema only). The `App\Team\Domain\Role` enum models the roles, with no permissions package
  - **Team columns** (#38): nullable `team_id`, `project_id`, `created_by` and `updated_by` on `observations`, `team_id` and `project_id` on `user_prompts`, and `team_id` on `personal_access_tokens`, with their indexes. The migrations are additive and safe on a live database: columns are added nullable, foreign keys start `NOT VALID`, the column and foreign key changes wait at most 5 seconds for a lock (`lock_timeout`), and indexes are built `CONCURRENTLY` (a failed build is retried cleanly; these builds have no lock timeout and wait for long-running transactions). Deleting a team deletes its rows; deleting a project or a creator sets the reference to NULL
  - **Personal teams** (#39): logging in or creating a token with `memory:token` gives every user, new or existing, a personal team and an owner membership in the same transaction as the user and the token. It is idempotent and heals a personal team without its owner row. The team slug is 16 random hex characters and is never derived from the email
  - **`memory:backfill-teams {--chunk=500} {--check}`** (#40): gives every user a personal team and fills `team_id`, `project_id`, `created_by` and `updated_by` on existing memories, `team_id` and `project_id` on existing prompts, and the team on legacy tokens, which keep authenticating. Rows are processed in keyset chunks, one transaction each; it is idempotent and resumable and never writes `user_id` or `updated_at`. A user who signs up while it runs may be missed by that run; they get a personal team at login, and the `app` container runs the backfill again on every start. Project names are normalized (case and whitespace variants become one project; a blank name gets no project). `--check` writes nothing, prints the rows and tokens without a team and the teams without an owner, and exits 1 while any remain
  - **Access context** (#41): `AccessContext` (team, project access, or the `system()` and `denied()` contexts) resolved from the request's token. It fails closed: no user, no team, or no membership in the token's team means `denied()`, with no fallback to a wider team. Owners and admins can access every project of the team; members none until grants exist. Tokens are now bound to a team (`createTeamToken`, issued by login and `memory:token` with the user's personal team); legacy tokens without a team resolve to the user's personal team. Revocation is unchanged
- **Token lifecycle** (#46, part of the security hardening in #31):
  - `GET /api/auth/tokens` lists the caller's own tokens as `{"data":[{id, name, created_at, last_used_at, expires_at, current}]}`; `expires_at` is the computed idle expiry and the hash and plaintext are never returned
  - `DELETE /api/auth/tokens/{id}` revokes one of the caller's tokens with 204; another user's id and a missing id get the same 404
  - `memory:prune-tokens`, scheduled daily, deletes idle-expired tokens and tokens past an absolute expiry; it is idempotent
  - Documented in `docs/api.md` and `docs/security.md`

### Changed

- **Tokens expire after 30 idle days** (#46): a token stops authenticating after `MEMRY_TOKEN_IDLE_DAYS` (default 30) without use, measured from `last_used_at` (or `created_at`). It applies to every bearer route (`/mcp/memory` and `/api/*`) and an expired token gets exactly the usual 401 (`{"message":"Unauthenticated."}`). A token in regular use never expires. Sanctum's optional absolute `expiration` (minutes, `null` by default) composes with it
- **Rate limit** (#46): the new `auth-tokens` limiter (10 requests per minute per user) applies to `GET /api/auth/tokens`, `DELETE /api/auth/tokens/{id}` and the existing `DELETE /api/auth/token`, which had no throttle; its 204 behavior is unchanged
- **Team-scoped reads** (#43): `get-memory` and `search-memory` only see the request's team (and, for a restricted member, only the allowed projects; rows without a project are excluded). A memory of another team answers exactly `Memory not found.`, the same as a missing id. The migration `backfill_teams_then_assert_complete` first backfills teams (it creates personal teams and projects and fills the team of memories, prompts and tokens, like `memory:backfill-teams`), then throws `Run memory:backfill-teams first: N rows without team_id` if any memory or prompt still has no team; it runs no DDL
- **Dual write** (#42): `save-memory`, `session-summary` and `save-prompt` also write `team_id`, `project_id` (created on demand) and `created_by` (only on create) / `updated_by`. An identical re-save does not touch `updated_at`. A user without a team writes exactly as before
- **`memory:merge-projects` and team-wide topic keys** (#42, #44): projects are merged per team, each moved row pointing at its own team's destination project and the source project row kept; `--email` merges within that user's personal team and no `--email` merges across all teams. A `topic_key` is resolved by team, project and scope: for `scope: project` the last write by any member wins (`created_by` stays, `updated_by` is the writer), and `scope: personal` also requires the same user. Output and exit codes are unchanged
- **Validated foreign keys** (#45): the migration `validate_team_foreign_keys` validates the nine team foreign keys added `NOT VALID` in #38 (`VALIDATE CONSTRAINT` scans existing rows without blocking writes); if a row violates a key it fails and a re-run is idempotent
- **Docs** (#33): the install command in the README and `docs/self-hosting.md` downloads to a temporary file (`f=$(mktemp) && curl -fsSLo "$f" ... && sh "$f" --email YOUR_EMAIL`), so it never overwrites an `install.sh` in the current directory and runs only after a successful download; `YOUR_EMAIL` pasted unchanged is rejected by the installer. It also shows how to read the script first
- **Internal** (#34-#36): characterization tests and HTTP goldens for the existing behavior, and CI now runs Pest

### Security

- **`install.sh --doctor`** (#31): a read-only diagnostic that deletes nothing, pulls nothing, starts nothing and needs no email. Without options it lists the database volumes that may be memry's (Compose label `com.docker.compose.volume=postgres-data`, or named `*_postgres-data`) with their Compose project, creation date, the containers of the project and a status: `memry install at <dir>` (a container runs a `memry-server` image), `stopped or unconfirmed (no containers)` (for example after `docker compose down`, which keeps the volume) or `not memry`; it never prints a delete command there. With `--dir <directory>` (run it as `curl -fsSL <install.sh URL> | sh -s -- --doctor --dir <directory>`) it proves ownership through the installer's own project names and reports a healthy (the `app` container is running), stopped (no running `app` container, even if postgres runs or stopped containers exist; `docker compose up -d`) or lost-`.env` install. It asks Compose for the project name (falling back to `.env` and saying so) and canonicalizes a relative `--dir` like the installer. For a lost `.env` it prints `docker volume rm` with a data-loss warning only for the path-hashed volume; a legacy directory-name volume is reported as possibly this install's, with no delete advice because ownership cannot be proven. A failing `docker volume ls`, `docker volume inspect` or `docker ps` exits with an error instead of reporting nothing found
- **No token printed by `install.sh`** (#31): the installer now creates your user and token only when the memry CLI is ready to use it (`memry setup` reads it from `MEMRY_TOKEN`). With `--no-cli`, without Homebrew, or if the CLI install fails it creates and prints no token; it prints the command to create one on request (`docker compose run --rm app token <email>`) and the `memry setup` command to paste it into. If `memry setup` fails, the token is not printed either; it expires after 30 days of inactivity or can be revoked
- **Attested releases** (#31): on a release tag the image is pushed with SBOM and max-detail provenance attestations, and the image digest and the installer files (`install.sh`, `docker-compose.yml`, `docker/community.env.example`) get GitHub artifact attestations (SLSA build provenance, Sigstore keyless), verifiable with `gh attestation verify`. See "Verifying releases" in `docs/self-hosting.md`

### Fixed

- **Concurrent first migrations**: the container `migrate` step now runs `php artisan memory:migrate`, which takes a PostgreSQL advisory lock (`pg_advisory_lock`) around `migrate --force` and always releases it, so several containers starting on a brand-new database queue up instead of racing. This replaces the `--isolated` flag and the cache-table pre-step, which ran without a lock (#31). The command records the backend pid after locking and fails if it changed during the run or if the unlock reports the lock was not held, which happens behind a transaction or statement pooler (for example PgBouncer in transaction mode); migrations need a direct or session-pooled connection, documented in `docs/self-hosting.md`
- **`install.sh` hardening** (#31): Docker Compose now runs with a minimal environment (`env -i` plus an allow-list), so variables exported in the caller's shell, even those referenced only inside `.env` values, cannot change what it runs (Compose only resolves image names with `config --images`; the resolved images are pulled with plain `docker pull` in the caller's environment, so registry credential helpers such as ECR keep their variables, and, after pulling, the installer passes the app image to Compose as its digest reference (`repo@sha256:...`, from `docker image inspect`; a locally built image without digest is tagged `memry-server:pinned-<12 hex chars of its image ID>` and run by that immutable local tag), so Compose runs exactly the image the installer pulled and never re-pulls it, even a `:latest` one; no `--pull` flag is passed, so Compose v2 releases before 2.32.2 keep working); the local `/up` probe ignores proxy variables (`curl --noproxy '*'`); a remote Docker daemon (`DOCKER_CONTEXT`, then `DOCKER_HOST`, then the active context, as in the docker CLI: `ssh://` or non-local `tcp://`) is refused with the versioned GitHub URL of the manual deployment guide (`docs/self-hosting.md`; the script runs from a temp file, so a relative path would not exist), since the installer only installs on the local daemon (there is no `--url` option); the email in the printed token recovery command is shell-quoted
- **Team backfill migration**: it no longer runs inside one wrapping transaction (`$withinTransaction = false`), so the per-user backfill commits as it goes, holds short locks and can be resumed after a failure
- **Upgrades from a release before teams**: the `app` container now runs `memory:backfill-teams` on start, before serving, so rows written by the old app during the upgrade get their team; it is idempotent and exits 0 when nothing is left. `docs/self-hosting.md` recommends stopping the app before migrating

---

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
