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
f=$(mktemp) && curl -fsSLo "$f" https://raw.githubusercontent.com/mrtheroi/memry-server/v0.19.0/install.sh && sh "$f" --email YOUR_EMAIL
```

Replace `YOUR_EMAIL` with your email. The command downloads the script to a new temporary file (never overwriting a file in the current directory) and runs it only if the download succeeded. Read it before running it: `curl -fsSL https://raw.githubusercontent.com/mrtheroi/memry-server/v0.19.0/install.sh | less`. It is pinned to its release and:

1. checks for Docker, Compose v2, curl and a running Docker daemon (it does not install Docker);
2. downloads that release's `docker-compose.yml` and example `.env` into `~/memry-community`;
3. writes `.env` (mode 600) with a generated `APP_KEY`, a random `DB_PASSWORD`, `APP_URL`, `APP_PORT` and `MEMRY_IMAGE`;
4. pulls the images (`docker pull` in your environment, so credential helpers work for a private `MEMRY_IMAGE`), runs migrations, starts `app` and `scheduler` and waits for `/up`;
   Compose then runs the app image by its digest (`repo@sha256:...`, set by the script, whatever your shell exports), so it runs exactly the image the script pulled and never re-pulls a `:latest` or untagged one without your registry credentials; a locally built image without a digest is tagged `memry-server:pinned-<12 hex chars of its image ID>` and run by that tag; `.env` is not changed;
5. installs the [memry CLI](https://github.com/mrtheroi/memry-cli) with Homebrew if it is missing or older than 0.7.0;
6. once the CLI is ready, creates your user and a token (kept in memory, never written to disk or printed) and runs `memry setup --url http://localhost:<port> --token`, which reads the token from `MEMRY_TOKEN`.

Without Homebrew, with `--no-cli`, or if the CLI install fails, the script creates no token and prints none: it shows the command to create one when you need it (`cd <dir> && docker compose run --rm app token <email>`, which prints the token once and also creates your user) and the `memry setup` command to paste it into. Store the token like a password. If `memry setup` fails after the token was created, the token is not printed; it expires after 30 days of inactivity, or you can revoke it with `memory:revoke`.

| Option | Default | |
| --- | --- | --- |
| `--email` | required | Email of your memry user |
| `--dir` | `~/memry-community` | Install directory |
| `--port` | `8000` | Host port; `APP_URL` is `http://localhost:<port>` |
| `--agents` | asked by `memry setup` | Agents to connect, e.g. `claude-code,codex` |
| `--no-cli` | | Skip the memry CLI; the token is not created, the script prints how to create one |

The script only installs on the local Docker daemon. It refuses to run against a remote one (`DOCKER_CONTEXT`, `DOCKER_HOST` or the active `docker context` pointing to `tcp://` somewhere other than localhost, or to `ssh://`; `DOCKER_CONTEXT` wins over `DOCKER_HOST`, as in the docker CLI), because memry would start on that machine while the script looked for it on `localhost`. To deploy on a server, follow [Manual setup](#manual-setup) and [Reverse proxy and TLS](#reverse-proxy-and-tls). Docker Compose runs with a minimal environment (`PATH`, `HOME`, `DOCKER_*`, locale, ssh agent), so variables exported in your shell, including ones referenced inside `.env` values, never change what it starts.

A new `.env` also gets `COMPOSE_PROJECT_NAME`: the directory name plus a checksum of its absolute path (for example `memry-community-1234567890`), so two installs in directories with the same name never share containers or the database volume (`<project>_postgres-data`). It stays the same on every run, and plain `docker compose` commands in the directory use it too. An existing `.env` without it keeps Compose's default project, the directory name, so its volume is not orphaned.

Run the `docker compose` commands in this guide from the install directory, in a shell that does not export `COMPOSE_PROJECT_NAME` or any variable from memry's `.env` (`MEMRY_IMAGE`, `DB_PASSWORD`, `APP_KEY`, ...). Compose gives variables exported in your shell precedence over `.env`, so they would point the command at another project, database or image. The installer itself is not affected: it runs Compose with a cleared environment.

If `.env` is missing but a database volume of that directory still exists (`<project>_postgres-data`, or `<directory>_postgres-data` from an install without `COMPOSE_PROJECT_NAME`), the script stops instead of generating a new `DB_PASSWORD` the database would reject. Restore the old `.env` (back it up: it holds `APP_KEY` and `DB_PASSWORD`), or delete the volume and all its data with `docker volume rm <project>_postgres-data`, then run the script again. If the volume belongs to another installation in a directory with the same name, install into a directory with another name.

The installer also has a read-only diagnostic, `--doctor`. It pulls, starts, writes and deletes nothing, needs no email and takes no option except `--dir`. The quick start script is downloaded to a temporary file, so there is no `install.sh` in your install directory: run it the same way, pinned to the same release.

To check one install directory, which is the case when `.env` was lost:

```bash
curl -fsSL https://raw.githubusercontent.com/mrtheroi/memry-server/v0.19.0/install.sh | sh -s -- --doctor --dir <directory>
```

It finds the database volumes of that install with the installer's own rules (the project name Compose itself resolves from its `.env`, falling back to the `COMPOSE_PROJECT_NAME` in it and saying so when Compose cannot confirm it; without a `.env`, the project name with the directory checksum and the plain directory-name project), then reports whether `.env`, the volume and containers exist:

- healthy: nothing to do (the `app` container is running);
- stopped: the data is intact, and no `app` container of the install is running (PostgreSQL may still run, or the containers may exist stopped, for example after `docker compose stop`); start it with `cd <directory> && docker compose up -d`;
- `.env` missing but the volume exists: restore the old `.env` (it holds `APP_KEY` and `DB_PASSWORD`) and run the installer again, or, only if you do not want the data, `docker volume rm <volume>`, which removes every memory in it and cannot be undone. The delete command is printed only for the volume named after the directory path (with its checksum), which is proven to belong to that directory. A volume named only after the directory name may be this install's, but the same name can belong to another install, so the doctor reports it, offers the restore, and suggests no deletion because ownership cannot be proven;
- directory path not usable: if `--dir` does not exist and contains a `.`, `..` or empty (`//`) segment (a single leading `./` and a single trailing `/` are fine), the doctor exits with an error before calling Docker and asks for the path as the installer printed it, an absolute path without those segments, because it cannot tell where a `..` leads through symlinks;
- project name not resolvable: when Compose cannot read the install (for example `docker-compose.yml` is gone) and the `COMPOSE_PROJECT_NAME` in `.env` is an expression such as `${PROJECT_NAME:-memry}`, the doctor does not guess. It says the name uses variable interpolation and cannot be resolved, makes no claim about the volume or the containers (`containers: not checked`) and prints no `docker volume rm`; restore `docker-compose.yml` from the release and run the doctor again;
- no volume for that install: it says so.

Without `--dir` the doctor only lists, for information:

```bash
curl -fsSL https://raw.githubusercontent.com/mrtheroi/memry-server/v0.19.0/install.sh | sh -s -- --doctor
```

For each volume labelled by Compose as `postgres-data`, or named `*_postgres-data`, it prints the Compose project, the creation date, the containers of that project with their working directory, and a status:

- `memry install at <directory>`: a container of the project runs a `memry-server` image (it also says when that directory no longer exists);
- `stopped or unconfirmed (no containers)`: the project has no containers. It may be a memry install stopped with `docker compose down` (which keeps the volume) or the database of another project, so nothing is proven;
- `not memry (containers use other images)`: the containers of the project do not run a `memry-server` image.

The volume carries no memry label, so a volume alone never proves ownership, and this mode never prints a delete command. The doctor never deletes anything; if a Docker command it needs fails, it exits with an error instead of reporting an empty result.

Running the script again is safe: an existing `.env` is kept (only empty required values are filled in), so `APP_KEY` and `DB_PASSWORD` never change. Each run that connects your agents issues a new token; revoke old ones with `memory:revoke` (see [Users and tokens](#users-and-tokens)). To upgrade later, follow [Upgrades](#upgrades). Put a TLS proxy in front before exposing the server beyond your machine ([Reverse proxy and TLS](#reverse-proxy-and-tls)).

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
| `migrate` | `php artisan memory:migrate` (migrations under a PostgreSQL advisory lock) |
| `scheduler` | `php artisan schedule:work` |
| `token <email>` | Creates the user if needed and prints a new token |
| `key` | Prints a new `APP_KEY` |
| `php`, `sh`, `bash`, `frankenphp` | Run as-is, without `php artisan` (for example `sh` for a shell) |
| anything else | Passed to `php artisan` (for example `memory:revoke you@example.com`) |

Every command except `key` and the raw `php`, `sh`, `bash` and `frankenphp` commands refuses to start when `APP_KEY` is empty or `DB_CONNECTION` is not `pgsql`.

## Using an existing PostgreSQL

Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (and `DB_SSLMODE`, for example `require`) in `.env`, then remove the `postgres` service and the `depends_on` block from your copy of `docker-compose.yml`. The database must exist; migrations create the tables.

Migrations need a direct PostgreSQL connection or a session-pooled one. Transaction or statement pooling (for example PgBouncer in transaction mode, or the Supabase/Neon pooler URLs) is not supported: `memory:migrate` relies on a session advisory lock, which such poolers cannot keep on one backend session, and it fails with an error when it detects that. Use the provider's direct connection string for `DB_HOST`.

## Migrations

Run migrations after the first install and after every upgrade:

```bash
docker compose run --rm migrate
```

Alternatively set `AUTO_MIGRATE=true` so `app` migrates before it starts serving. Migrations run under a PostgreSQL advisory lock (`php artisan memory:migrate`): containers starting at once wait for the first one to finish and then find nothing left to migrate, including on a brand-new database.

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

Read the [CHANGELOG](../CHANGELOG.md) first, then run migrations after updating the image. Upgrading from a release before teams, the migration also creates a personal team for every user and assigns it to their existing memories, prompts and tokens; no separate step is needed. When upgrading from a release before teams, stop the app (`docker compose stop app scheduler`) before running the migration, so the old version does not keep writing while it runs. The new `app` container also runs `php artisan memory:backfill-teams` on start, before it serves requests, so any rows the old app wrote during the upgrade still get their team. To verify, run `docker compose run --rm app memory:backfill-teams --check`: it exits 0 when nothing is missing.

The release after teams (see the CHANGELOG) makes `team_id` NOT NULL on `observations` and `user_prompts`. Upgrade to the teams release first and let it settle, then check before migrating: `docker compose run --rm app memory:backfill-teams --check` must exit 0 (every count 0). If a row still has no team, the migration stops before changing anything, with `Run memory:backfill-teams first: observations has N rows without team_id` (or `user_prompts`); run `docker compose run --rm app memory:backfill-teams`, confirm `--check` reports 0, and run the migration again. It is safe to re-run. If it reports a `check constraint ... is violated`, a row was written without a team while it ran: run the backfill and migrate again. Tokens keep an optional `team_id`.

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

## Verifying releases

The image and the installer files (`install.sh`, `docker-compose.yml` and `docker/community.env.example`) carry [GitHub artifact attestations](https://docs.github.com/en/actions/security-for-github-actions/using-artifact-attestations/using-artifact-attestations-to-establish-provenance-for-builds): SLSA build provenance, signed keyless with Sigstore and recorded in the public transparency log. They are made by the `docker.yml` run of the release tag, not by a key.

Verifying needs the [GitHub CLI](https://cli.github.com/) (`gh`) **2.102.0 or later**, logged in to any GitHub account (`gh auth login`, or `GH_TOKEN` in CI). Older versions either lack `--source-ref` or match `--signer-workflow` as a prefix ([GHSA-wjmr-j3rp-mh2g](https://github.com/cli/cli/security/advisories/GHSA-wjmr-j3rp-mh2g)).

Every check pins the exact tag you are installing (`--source-ref`) and the workflow that built it (`--signer-workflow`), so an older release or another workflow cannot be passed off as the requested one. The install then uses exactly what was verified: the downloaded files (`MEMRY_SOURCE_DIR`) and the image by digest (`MEMRY_IMAGE=...@sha256:...`), never the tag again, so a tag moved after the check cannot change what runs. Each step runs only if the previous one succeeded:

```sh
VERSION=0.19.0
verify() {
  gh attestation verify "$1" \
    --repo mrtheroi/memry-server \
    --source-ref "refs/tags/v${VERSION}" \
    --signer-workflow mrtheroi/memry-server/.github/workflows/docker.yml
}

mkdir -p memry-verify/docker && cd memry-verify &&
base="https://raw.githubusercontent.com/mrtheroi/memry-server/v${VERSION}" &&
curl -fsSL -o install.sh "$base/install.sh" &&
curl -fsSL -o docker-compose.yml "$base/docker-compose.yml" &&
curl -fsSL -o docker/community.env.example "$base/docker/community.env.example" &&
verify install.sh && verify docker-compose.yml && verify docker/community.env.example &&
DIGEST="$(docker buildx imagetools inspect "ghcr.io/mrtheroi/memry-server:${VERSION}" | awk '/^Digest:/ {print $2}')" &&
[ -n "$DIGEST" ] &&
verify "oci://ghcr.io/mrtheroi/memry-server@${DIGEST}" &&
echo "memry ${VERSION}: verified (image ${DIGEST})" &&
MEMRY_SOURCE_DIR="$PWD" MEMRY_IMAGE="ghcr.io/mrtheroi/memry-server@${DIGEST}" sh ./install.sh
```

On a first install the installer writes that digest to `.env` (an existing `.env` keeps its `MEMRY_IMAGE`). For a verified upgrade, run the same `verify` checks for the new version and set `MEMRY_IMAGE=ghcr.io/mrtheroi/memry-server@<verified digest>` in `.env` instead of the tag.

## Releasing (maintainers)

Bump `MEMRY_VERSION` in `install.sh` with `config/api.php`, the README and the CHANGELOG; the unit tests fail otherwise. The install command in the docs points at the tag, so it works once the tag and its image are published.

Pushing a `vX.Y.Z` tag runs `.github/workflows/docker.yml`: it runs the install script checks, smoke tests the image, runs the install script end to end, then publishes the immutable `X.Y.Z` tag to `ghcr.io/mrtheroi/memry-server`, attests the image digest, and attests the installer files (`install.sh`, `docker-compose.yml`, `docker/community.env.example`). The attestations are GitHub build provenance (SLSA, Sigstore keyless); they appear under the repository's Attestations tab and need no GitHub release. There are no floating `X.Y` tags. Every tag gets its own run; runs for different tags are neither queued behind each other nor cancelled.

`latest` is moved by the last step of the run, after `X.Y.Z` is pushed. That step lists the repository's tags again and points `latest` at `X.Y.Z` only if it is the highest stable `vX.Y.Z` tag at that moment, so an older or backport tag normally does not take it over. If the smoke test or the push fails, `latest` stays where it was.

One small window remains: if a higher tag is pushed in the seconds between that check and the `latest` update of a lower tag's run, and the higher tag's run finishes first, the lower run can still point `latest` at the older release. To recover, re-run the higher tag's workflow run (it moves `latest` back), or avoid pushing release tags in quick succession.

New GHCR packages are private, and the workflow cannot change that: GitHub offers no API for package visibility, so it is a one-time manual step. After the first tagged release:

1. Open the package (repository → Packages → `memry-server`) → Package settings → Change visibility → Public.
2. In the same settings, check that the package is connected to the `mrtheroi/memry-server` repository (Connect repository if it is not).
3. Before announcing the image, check that an anonymous pull works, for example `docker logout ghcr.io && docker pull ghcr.io/mrtheroi/memry-server:<version>`.
