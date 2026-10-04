# Self-hosting (memry Community)

Run your own memry server with Docker. The image bundles the Laravel app served by FrankenPHP; you bring PostgreSQL.

## Requirements

- Docker with Docker Compose v2 ([get Docker](https://docs.docker.com/get-docker/))
- PostgreSQL 14 or newer. The example Compose file starts one for you (`postgres:16-alpine`); full-text search relies on PostgreSQL, so other databases are not supported.

The server sends no telemetry. It only talks to your database and, if you configure it, your SMTP server.

## Quick start

### One-command install

With Docker and Compose v2 installed ([get Docker](https://docs.docker.com/get-docker/)), one script starts memry Community and connects your agents:

```bash
curl -fsSLo install.sh https://raw.githubusercontent.com/mrtheroi/memry-server/v0.18.1/install.sh && sh install.sh --email you@example.com
```

Read `install.sh` before running it (piping it straight into `sh` skips that). It is pinned to its release and:

1. checks for Docker, Compose v2, curl and a running Docker daemon (it does not install Docker);
2. downloads that release's `docker-compose.yml` and example `.env` into `~/memry-community`;
3. writes `.env` (mode 600) with a generated `APP_KEY`, a random `DB_PASSWORD`, `APP_URL`, `APP_PORT` and `MEMRY_IMAGE`;
4. pulls the images, runs migrations, starts `app` and `scheduler` and waits for `/up`;
5. creates your user and a token (kept in memory, never written to disk);
6. installs the [memry CLI](https://github.com/mrtheroi/memry-cli) with Homebrew if it is missing or older than 0.7.0, and runs `memry setup --url http://localhost:<port> --token`, which reads the token from `MEMRY_TOKEN`.

Without Homebrew, with `--no-cli`, or if setup fails, the script prints the token once, with the `memry setup` command to run yourself. Store the token like a password.

| Option | Default | |
| --- | --- | --- |
| `--email` | required | Email of your memry user |
| `--dir` | `~/memry-community` | Install directory |
| `--port` | `8000` | Host port; `APP_URL` is `http://localhost:<port>` |
| `--agents` | asked by `memry setup` | Agents to connect, e.g. `claude-code,codex` |
| `--no-cli` | | Skip the memry CLI and print the token |

A new `.env` also gets `COMPOSE_PROJECT_NAME`: the directory name plus a checksum of its absolute path (for example `memry-community-1234567890`), so two installs in directories with the same name never share containers or the database volume (`<project>_postgres-data`). It stays the same on every run, and plain `docker compose` commands in the directory use it too. An existing `.env` without it keeps Compose's default project, the directory name, so its volume is not orphaned.

If `.env` is missing but a database volume of that directory still exists (`<project>_postgres-data`, or `<directory>_postgres-data` from an install without `COMPOSE_PROJECT_NAME`), the script stops instead of generating a new `DB_PASSWORD` the database would reject. Restore the old `.env` (back it up: it holds `APP_KEY` and `DB_PASSWORD`), or delete the volume and all its data with `docker volume rm <project>_postgres-data`, then run the script again. If the volume belongs to another installation in a directory with the same name, install into a directory with another name.

Running the script again is safe: an existing `.env` is kept (only empty required values are filled in), so `APP_KEY` and `DB_PASSWORD` never change. Each run issues a new token; revoke old ones with `memory:revoke` (see [Users and tokens](#users-and-tokens)). To upgrade later, follow [Upgrades](#upgrades). Put a TLS proxy in front before exposing the server beyond your machine ([Reverse proxy and TLS](#reverse-proxy-and-tls)).

### Manual setup

There are two ways to set the server up by hand:

- **Published image**: download two files and pull `ghcr.io/mrtheroi/memry-server`. No clone, no local build.
- **Build from source**: clone the repository and build the image yourself.

Either way, use a directory dedicated to the server. Compose reads the `.env` next to `docker-compose.yml`, so do not run it from a development checkout that already has its own `.env`.

#### Using the published image

An image is published to `ghcr.io/mrtheroi/memry-server` for `linux/amd64` and `linux/arm64` when a server release is tagged (`vX.Y.Z`). Each release is tagged `X.Y.Z`; `latest` points to the newest stable release. Pin a release in production; the [tags](https://github.com/mrtheroi/memry-server/tags) and the [CHANGELOG](../CHANGELOG.md) list them.

Download the Compose file and the example configuration from the same release tag (replace `<version>` with a release tag without the `v`):

```bash
mkdir memry-community && cd memry-community
VERSION=<version>
curl -fsSLo docker-compose.yml "https://raw.githubusercontent.com/mrtheroi/memry-server/v${VERSION}/docker-compose.yml"
curl -fsSLo .env "https://raw.githubusercontent.com/mrtheroi/memry-server/v${VERSION}/docker/community.env.example"
```

Then:

```bash
sed -i.bak "s|^MEMRY_IMAGE=.*|MEMRY_IMAGE=ghcr.io/mrtheroi/memry-server:${VERSION}|" .env
docker compose pull app                               # pulls the image set in MEMRY_IMAGE
docker compose run --rm --no-deps app key             # prints an APP_KEY
# edit .env: paste APP_KEY, set APP_URL and a strong DB_PASSWORD
docker compose run --rm migrate
docker compose up -d app scheduler
curl http://localhost:8000/up                         # 200 when healthy
docker compose run --rm app token you@example.com     # prints "Token: ..." once
```

`docker compose pull` (without `app`) also pulls PostgreSQL. Only `docker-compose.yml` and `.env` are needed; the `build: .` entry in the Compose file is unused while the image is present.

#### Building from source

Clone the repository into a dedicated checkout:

```bash
git clone https://github.com/mrtheroi/memry-server.git memry-community
cd memry-community
```

Then:

```bash
cp -n docker/community.env.example .env               # -n never overwrites an existing .env
docker compose build                                  # builds memry-server:local (MEMRY_IMAGE)
docker compose run --rm --no-deps app key             # prints an APP_KEY
# edit .env: paste APP_KEY, set APP_URL and a strong DB_PASSWORD
docker compose run --rm migrate
docker compose up -d app scheduler
curl http://localhost:8000/up                         # 200 when healthy
```

Services in `docker-compose.yml`:

| Service | What it does |
| --- | --- |
| `app` | Serves HTTP on container port 8000 (published on `APP_PORT`, default 8000) |
| `scheduler` | Runs `schedule:work` (daily pruning of expired login codes) |
| `migrate` | One-off: runs migrations and exits |
| `postgres` | PostgreSQL 16 with the `postgres-data` volume |

The container runs as a non-root user and logs to stderr (`docker compose logs app`).

### Entrypoint commands

The image entrypoint accepts these commands (`docker compose run --rm app <command>`):

| Command | Effect |
| --- | --- |
| `serve` (default) | Caches config, routes and views, then starts FrankenPHP |
| `migrate` | `php artisan migrate --force --isolated` |
| `scheduler` | `php artisan schedule:work` |
| `token <email>` | Creates the user if needed and prints a new token |
| `key` | Prints a new `APP_KEY` |
| `php`, `sh`, `bash`, `frankenphp` | Run as-is, without `php artisan` (for example `sh` for a shell) |
| anything else | Passed to `php artisan` (for example `memory:revoke you@example.com`) |

Every command except `key` and the raw `php`, `sh`, `bash` and `frankenphp` commands refuses to start when `APP_KEY` is empty or `DB_CONNECTION` is not `pgsql`.

## Using an existing PostgreSQL

Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (and `DB_SSLMODE`, for example `require`) in `.env`, then remove the `postgres` service and the `depends_on` block from your copy of `docker-compose.yml`. The database must exist; migrations create the tables.

## Migrations

Run migrations after the first install and after every upgrade:

```bash
docker compose run --rm migrate
```

Alternatively set `AUTO_MIGRATE=true` so `app` migrates before it starts serving. Once the database is initialised, migrations take a database lock, so several containers starting at once do not run them twice. On a brand-new database the cache table that holds that lock is created first, without the lock, so concurrent first migrations can race: for the first deployment, run `docker compose run --rm migrate` once before starting several `app` containers with `AUTO_MIGRATE=true`.

## Users and tokens

There is no sign-up page. Create a user and token from the server:

```bash
docker compose run --rm app token you@example.com     # prints "Token: ..." once
docker compose run --rm app memory:revoke you@example.com
```

Tokens are shown once; store them like passwords.

## Connecting agents

The one-command install does this for you. By hand, with the [memry CLI](https://github.com/mrtheroi/memry-cli) 0.6.0 or newer:

```bash
memry setup --url https://memry.example.com --token   # asks for the token, hidden
```

`--url` is required with `--token`, so the token is only sent to your server. For scripts, memry CLI 0.7.0 or newer reads the token from the `MEMRY_TOKEN` environment variable when `--token` has no value, which keeps it out of the process list. With older versions, `--token="$MEMRY_TOKEN"` skips the prompt. Keep the quotes: tokens contain a `|`, which the shell would otherwise read as a pipe. A token typed on the command line ends up in the shell history.

Or add the MCP server to Claude Code directly:

```bash
claude mcp add --transport http memry https://memry.example.com/mcp/memory \
  --header "Authorization: Bearer <token>"
```

## Email login (optional)

Users can also get a token themselves through the emailed login code flow (`POST /api/auth/code`, then `POST /api/auth/token`). By default `MAIL_MAILER=log`: codes are only written to the container logs, which is fine for a single-user setup. To deliver them by email, configure SMTP in `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=memry@example.com
```

## Reverse proxy and TLS

The container speaks plain HTTP. Put it behind a TLS-terminating reverse proxy (Caddy, nginx, Traefik, a cloud load balancer) and:

- set `APP_URL` to the public `https://` URL;
- set `TRUSTED_PROXIES` so forwarded headers are honoured: `*` trusts the proxy calling the container, or list proxy addresses / CIDR ranges separated by commas.

Agents send bearer tokens on every request, so do not expose the server over plain HTTP on the internet.

## Upgrades

Read the [CHANGELOG](../CHANGELOG.md) first, then run migrations after updating the image.

With the published image, set `MEMRY_IMAGE` in `.env` to the new release and pull it. Download that release's `docker-compose.yml` and `community.env.example` again and compare them with your copies, since new settings can appear:

```bash
# edit .env: MEMRY_IMAGE=ghcr.io/mrtheroi/memry-server:<new-version>
docker compose pull app
docker compose run --rm migrate
docker compose up -d app scheduler
```

From a source checkout:

```bash
git pull
docker compose build
docker compose run --rm migrate
docker compose up -d app scheduler
```

## Backups

All state lives in PostgreSQL:

```bash
docker compose exec -T postgres pg_dump -U memry -Fc memry > memry-$(date +%F).dump
# restore into an empty database:
docker compose exec -T postgres pg_restore -U memry -d memry --clean --if-exists < memry-2026-01-01.dump
```

Keep `APP_KEY` stable across restores and upgrades: login codes are signed with it (tokens are not).

## Smoke test

`docker/smoke.sh` builds the image, starts PostgreSQL, migrates, starts the app, issues a token and checks that `/mcp/memory` lists the six memory tools. It removes its containers and volumes when done. CI runs the same script on every pull request and push to `main` (`.github/workflows/docker.yml`); on a release tag the image is published to GHCR only after the smoke test passes.

The install script has two more tests, both run by the same workflow:

- `sh tests/install/install_test.sh` unit tests `install.sh` with stubbed `docker`, `curl`, `memry` and `brew` (no Docker needed). It also fails when the version pinned in `install.sh` is not the latest release in the CHANGELOG, so bump both together.
- `MEMRY_IMAGE=<image> docker/install-e2e.sh` runs `install.sh --no-cli` twice against a real image, with the Compose and env files of the checkout (`MEMRY_SOURCE_DIR`), and checks `/up`, the MCP tools that `APP_KEY` and `DB_PASSWORD` survive the second run, and that a run without `.env` refuses to reuse the existing database volume.

## Releasing (maintainers)

Bump `MEMRY_VERSION` in `install.sh` with `config/api.php`, the README and the CHANGELOG; the unit tests fail otherwise. The install command in the docs points at the tag, so it works once the tag and its image are published.

Pushing a `vX.Y.Z` tag runs `.github/workflows/docker.yml`: it runs the install script checks, smoke tests the image, runs the install script end to end, then publishes the immutable `X.Y.Z` tag to `ghcr.io/mrtheroi/memry-server`. There are no floating `X.Y` tags. Every tag gets its own run; runs for different tags are neither queued behind each other nor cancelled.

`latest` is moved by the last step of the run, after `X.Y.Z` is pushed. That step lists the repository's tags again and points `latest` at `X.Y.Z` only if it is the highest stable `vX.Y.Z` tag at that moment, so an older or backport tag normally does not take it over. If the smoke test or the push fails, `latest` stays where it was.

One small window remains: if a higher tag is pushed in the seconds between that check and the `latest` update of a lower tag's run, and the higher tag's run finishes first, the lower run can still point `latest` at the older release. To recover, re-run the higher tag's workflow run (it moves `latest` back), or avoid pushing release tags in quick succession.

New GHCR packages are private, and the workflow cannot change that: GitHub offers no API for package visibility, so it is a one-time manual step. After the first tagged release:

1. Open the package (repository → Packages → `memry-server`) → Package settings → Change visibility → Public.
2. In the same settings, check that the package is connected to the `mrtheroi/memry-server` repository (Connect repository if it is not).
3. Before announcing the image, check that an anonymous pull works, for example `docker logout ghcr.io && docker pull ghcr.io/mrtheroi/memry-server:<version>`.
