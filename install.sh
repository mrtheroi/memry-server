#!/bin/sh
# memry Community installer: runs a self-hosted memry server with Docker and
# connects your agents to it.
#
#   sh install.sh --email you@example.com
#
# It downloads docker-compose.yml and the example .env of one release into a
# directory (default ~/memry-community), generates APP_KEY and a database
# password, starts PostgreSQL and memry, creates your user and token, then
# runs `memry setup` (installing the memry CLI with Homebrew if needed).
# Running it again keeps the existing .env: a key or password in use is never
# regenerated. Run `sh install.sh --help` for the options.
#
# Overrides (mainly for testing): MEMRY_VERSION (release), MEMRY_IMAGE (image),
# MEMRY_SOURCE_DIR (read the files from a checkout instead of GitHub) and
# MEMRY_UP_TIMEOUT (seconds to wait for the server, default 120).
set -eu

# The release this script installs: the raw GitHub files of tag v${MEMRY_VERSION}
# and the image ghcr.io/mrtheroi/memry-server:${MEMRY_VERSION}.
MEMRY_VERSION="${MEMRY_VERSION:-0.18.0}"
MIN_CLI_VERSION="0.7.0"
DEFAULT_IMAGE="ghcr.io/mrtheroi/memry-server:${MEMRY_VERSION}"
RAW_BASE="https://raw.githubusercontent.com/mrtheroi/memry-server/v${MEMRY_VERSION}"

usage() {
    cat <<'USAGE'
Usage: sh install.sh --email you@example.com [options]

Installs memry Community (a self-hosted memry server) with Docker and connects
your agents to it.

Options:
  --email EMAIL      Email of your memry user (required)
  --dir DIR          Install directory (default: ~/memry-community)
  --port PORT        Host port of the server (default: 8000)
  --agents LIST      Agents to connect, comma-separated (e.g. claude-code,codex);
                     without it, memry setup asks
  --no-cli           Do not install or run the memry CLI; print the token instead
  -h, --help         Show this help

Requires Docker with Compose v2 (https://docs.docker.com/get-docker/) and curl.
USAGE
}

# .env holds the database password and APP_KEY: everything written here is
# private to the current user.
umask 077

say() { printf '%s\n' "$*"; }
die() { printf 'memry install: %s\n' "$*" >&2; exit 1; }
usage_error() { printf 'memry install: %s\n\nRun with --help for usage.\n' "$*" >&2; exit 2; }

parse_args() {
    EMAIL=""
    DIR="${HOME}/memry-community"
    PORT=""
    AGENTS=""
    INSTALL_CLI=1
    while [ $# -gt 0 ]; do
        case "$1" in
            -h | --help) usage; exit 0 ;;
            --email) [ $# -ge 2 ] || usage_error "--email needs a value"; EMAIL="$2"; shift ;;
            --email=*) EMAIL="${1#*=}" ;;
            --dir) [ $# -ge 2 ] || usage_error "--dir needs a value"; DIR="$2"; shift ;;
            --dir=*) DIR="${1#*=}" ;;
            --port) [ $# -ge 2 ] || usage_error "--port needs a value"; PORT="$2"; shift ;;
            --port=*) PORT="${1#*=}" ;;
            --agents) [ $# -ge 2 ] || usage_error "--agents needs a value"; AGENTS="$2"; shift ;;
            --agents=*) AGENTS="${1#*=}" ;;
            --no-cli) INSTALL_CLI=0 ;;
            *) usage_error "unknown option: $1" ;;
        esac
        shift
    done
    [ -n "$PORT" ] || PORT=8000
    case "$PORT" in
        # At most 5 digits, so the range check below cannot overflow.
        '' | *[!0-9]* | ??????*) usage_error "--port must be a number between 1 and 65535" ;;
    esac
    if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
        usage_error "--port must be a number between 1 and 65535"
    fi
    [ -n "$EMAIL" ] || usage_error "--email is required"
    printf '%s' "$EMAIL" | grep -Eq '^[^[:space:]@]+@[^[:space:]@.]+(\.[^[:space:]@.]+)+$' \
        || usage_error "--email is not a valid email address: $EMAIL"
}

DOCKER_DOCS="https://docs.docker.com/get-docker/"

preflight() {
    command -v docker >/dev/null 2>&1 \
        || die "Docker is not installed. Install Docker (with Compose v2) first: ${DOCKER_DOCS}"
    compose_version="$(docker compose version --short 2>/dev/null)" || compose_version=""
    compose_major="$(printf '%s' "$compose_version" | sed -n 's/^v\{0,1\}\([0-9][0-9]*\)\..*/\1/p')"
    if [ -z "$compose_major" ] || [ "$compose_major" -lt 2 ]; then
        die "Docker Compose v2 is required (the \`docker compose\` command). See ${DOCKER_DOCS}"
    fi
    command -v curl >/dev/null 2>&1 || die "curl is required. Install it with your package manager."
    docker info >/dev/null 2>&1 \
        || die "the Docker daemon is not running (or this user cannot reach it). Start Docker and try again. Help: ${DOCKER_DOCS}"
}

# fetch <path in the repository> <destination>: the file goes to a temporary
# name next to the destination and is moved into place only when complete, so
# a failed download never leaves a partial file that later runs would keep.
fetch() {
    part="$2.part"
    if [ -n "${MEMRY_SOURCE_DIR:-}" ]; then
        cp "${MEMRY_SOURCE_DIR}/$1" "$part" || { rm -f "$part"; die "could not copy ${MEMRY_SOURCE_DIR}/$1"; }
    else
        curl -fsSL -o "$part" "${RAW_BASE}/$1" || { rm -f "$part"; die "could not download ${RAW_BASE}/$1"; }
    fi
    mv "$part" "$2"
}

download_files() {
    # A literal ~ (as in --dir=~/memry) is not expanded by the shell.
    # shellcheck disable=SC2088
    case "$DIR" in
        "~") DIR="$HOME" ;;
        "~/"*) DIR="${HOME}/${DIR#"~/"}" ;;
    esac
    mkdir -p "$DIR"
    DIR="$(cd "$DIR" && pwd)"
    [ -f "${DIR}/docker-compose.yml" ] || fetch docker-compose.yml "${DIR}/docker-compose.yml"
}

# env_get <key> <file>: the last value assigned to key (empty when unset),
# without surrounding quotes.
env_get() {
    sed -n "s/^$1=//p" "$2" | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

# env_has <key> <file>: the key has a non-empty value.
env_has() {
    [ -n "$(env_get "$1" "$2")" ]
}

# env_set <key> <value> <file>: replaces the key's line, or appends one.
env_set() {
    ENV_SET_VALUE="$2" awk -v key="$1" '
        BEGIN { value = ENVIRON["ENV_SET_VALUE"]; done = 0 }
        index($0, key "=") == 1 { if (!done) print key "=" value; done = 1; next }
        { print }
        END { if (!done) print key "=" value }
    ' "$3" > "$3.tmp"
    mv "$3.tmp" "$3"
}

random_base64() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 "$1" | tr -d '\n'
    else
        head -c "$1" /dev/urandom | base64 | tr -d '\n'
    fi
}

generate_app_key() {
    printf 'base64:%s' "$(random_base64 32)"
}

# Letters and digits only: safe in .env files and database URLs.
generate_password() {
    LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 40
}

# A Compose project name unique to the install directory: its sanitized name
# plus a checksum of its absolute path. Two installs in directories with the
# same name would otherwise share a project and its database volume.
project_name() {
    base="$(basename "$DIR" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9\n' '-' | tr -s '-' | sed -e 's/^-*//' -e 's/-*$//')"
    printf '%s-%s' "${base:-memry}" "$(printf '%s' "$DIR" | cksum | cut -d ' ' -f 1)"
}

# Compose's own default project name for the directory: lowercase, only
# letters, digits, "-" and "_", starting with a letter or digit.
default_project_name() {
    basename "$DIR" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-' | sed 's/^[^a-z0-9]*//'
}

# A new .env starts from the release's example with the values this script
# owns cleared. An existing .env is kept as is: only empty or missing required
# values are filled in, so an APP_KEY or DB_PASSWORD already in use is never
# replaced. Only a new .env gets COMPOSE_PROJECT_NAME: an existing install
# without it keeps Compose's default project (the directory name) and with it
# its database volume.
configure_env() {
    env_file="${DIR}/.env"
    if [ ! -f "$env_file" ]; then
        project="$(project_name)"
        # A database created by an earlier install of this directory still
        # expects that install's DB_PASSWORD: never generate a new one over it.
        # An install made before COMPOSE_PROJECT_NAME existed uses Compose's
        # default project, named after the directory.
        for volume in "${project}_postgres-data" "$(default_project_name)_postgres-data"; do
            if docker volume inspect "$volume" >/dev/null 2>&1; then
                die "${env_file} is missing, but the database volume ${volume} already exists and uses the password of the old .env. Restore that .env (it holds APP_KEY and DB_PASSWORD) and run this script again. If the volume belongs to another installation in a directory with the same name, install into a directory with another name. To delete the volume and every memory in it instead, run: docker volume rm ${volume}"
            fi
        done
        fetch docker/community.env.example "${env_file}.new"
        for key in MEMRY_IMAGE APP_KEY APP_URL APP_PORT DB_PASSWORD; do
            env_set "$key" "" "${env_file}.new"
        done
        env_set COMPOSE_PROJECT_NAME "$project" "${env_file}.new"
        mv "${env_file}.new" "$env_file"
    fi
    chmod 600 "$env_file"

    env_has MEMRY_IMAGE "$env_file" || env_set MEMRY_IMAGE "${MEMRY_IMAGE:-$DEFAULT_IMAGE}" "$env_file"
    env_has APP_KEY "$env_file" || env_set APP_KEY "$(generate_app_key)" "$env_file"
    env_has APP_PORT "$env_file" || env_set APP_PORT "$PORT" "$env_file"
    env_has APP_URL "$env_file" || env_set APP_URL "http://localhost:$(env_get APP_PORT "$env_file")" "$env_file"
    env_has DB_PASSWORD "$env_file" || env_set DB_PASSWORD "$(generate_password)" "$env_file"
}

step() { printf '\n==> %s\n' "$*"; }

# compose <args>: docker compose for this install only. Variables exported in
# the calling shell take precedence over .env in Compose, so every variable the
# Compose file or .env uses, and every COMPOSE_* one, is unset for the call;
# the project directory and .env are passed explicitly.
compose() {
    (
        for name in $(compose_variable_names); do unset "$name"; done
        exec docker compose --project-directory "$DIR" --env-file "${DIR}/.env" "$@"
    )
}

compose_variable_names() {
    {
        sed -n 's/^[[:space:]]*\([A-Za-z_][A-Za-z0-9_]*\)=.*/\1/p' "${DIR}/.env"
        # shellcheck disable=SC2016 # a literal ${NAME reference in the Compose file
        grep -o '\${[A-Za-z_][A-Za-z0-9_]*' "${DIR}/docker-compose.yml" | sed 's/^\${//'
        env | sed -n 's/^\(COMPOSE_[A-Za-z0-9_]*\)=.*/\1/p'
    } | sort -u
}

start_server() {
    cd "$DIR"
    image="$(env_get MEMRY_IMAGE .env)"
    step "Pulling ${image} and PostgreSQL"
    if ! compose pull; then
        docker image inspect "$image" >/dev/null 2>&1 \
            || die "could not pull ${image}. Check your network and the MEMRY_IMAGE value in ${DIR}/.env."
        say "Could not pull ${image}; using the local copy."
    fi

    step "Running database migrations"
    compose run --rm migrate || die "migrations failed. See the output above, or run \`docker compose logs postgres\` in ${DIR}."

    step "Starting memry"
    compose up -d app scheduler || die "could not start memry. Run \`docker compose logs app\` in ${DIR}."
    wait_until_up
}

# Waits for /up until a wall-clock deadline: slow probes count against the
# timeout too, and no probe runs past it.
wait_until_up() {
    URL="http://localhost:$(env_get APP_PORT .env)"
    timeout="${MEMRY_UP_TIMEOUT:-120}"
    deadline=$(($(date +%s) + timeout))
    printf 'Waiting for %s/up' "$URL"
    while :; do
        remaining=$((deadline - $(date +%s)))
        [ "$remaining" -gt 0 ] || break
        [ "$remaining" -le 5 ] || remaining=5
        if [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time "$remaining" "${URL}/up" || true)" = "200" ]; then
            say " ready."
            return 0
        fi
        printf '.'
        sleep 2
    done
    say ""
    die "memry did not answer ${URL}/up within ${timeout}s. Check the logs with: cd ${DIR} && docker compose logs app"
}

# parse_token: reads the entrypoint's `token <email>` output ("Token: <token>").
parse_token() {
    tr -d '\r' | sed -n 's/^Token: //p' | tail -n 1
}

# The token only lives in the TOKEN variable: it is never written to disk.
issue_token() {
    step "Creating a token for ${EMAIL}"
    token_output="$(compose run --rm -T app token "$EMAIL")" \
        || die "could not create the user and token. Check the logs with: cd ${DIR} && docker compose logs app"
    TOKEN="$(printf '%s\n' "$token_output" | parse_token)"
    token_output=""
    [ -n "$TOKEN" ] || die "the server did not print a token. Run it by hand: cd ${DIR} && docker compose run --rm app token ${EMAIL}"
    say "Token created."
}

setup_command() {
    printf 'memry setup --url %s --token' "$URL"
    [ -z "$AGENTS" ] || printf ' --agents=%s' "$AGENTS"
}

# Shown only when the CLI step is skipped or fails.
print_token() {
    say ""
    say "Your memry token (shown once; store it like a password):"
    say ""
    say "    ${TOKEN}"
    say ""
    say "To connect your agents, install the memry CLI ${MIN_CLI_VERSION} or newer, run the"
    say "command below and paste the token when it asks for it:"
    say ""
    say "    $(setup_command)"
}

# ensure_cli: 0 when memry ${MIN_CLI_VERSION}+ is ready, 1 when installing or
# upgrading it failed, 2 when it cannot be installed here (no Homebrew).
ensure_cli() {
    if ! command -v memry >/dev/null 2>&1; then
        command -v brew >/dev/null 2>&1 || { print_cli_instructions; return 2; }
        step "Installing the memry CLI"
        brew install mrtheroi/tap/memry || return 1
    fi
    version_at_least "$(cli_version)" "$MIN_CLI_VERSION" && return 0

    command -v brew >/dev/null 2>&1 || { print_cli_instructions; return 2; }
    step "Upgrading the memry CLI to ${MIN_CLI_VERSION} or newer"
    brew upgrade memry || return 1
    version_at_least "$(cli_version)" "$MIN_CLI_VERSION" || {
        say "memry $(cli_version) is still older than ${MIN_CLI_VERSION}."
        return 1
    }
}

# The first X.Y.Z in `memry --version` (which may be colored).
cli_version() {
    memry --version 2>/dev/null | tr -c '0-9.\n' ' ' \
        | awk '{ for (i = 1; i <= NF; i++) if ($i ~ /^[0-9]+\.[0-9]+\.[0-9]+$/) { print $i; exit } }'
}

# version_at_least <version> <minimum>
version_at_least() {
    awk -v v="$1" -v min="$2" 'BEGIN {
        if (v == "") exit 1
        split(v, a, "."); split(min, b, ".")
        for (i = 1; i <= 3; i++) {
            if (a[i] + 0 > b[i] + 0) exit 0
            if (a[i] + 0 < b[i] + 0) exit 1
        }
        exit 0
    }'
}

print_cli_instructions() {
    say ""
    say "The memry CLI ${MIN_CLI_VERSION} or newer is needed to connect your agents, and it"
    say "installs with Homebrew (https://brew.sh). Once Homebrew is installed, run:"
    say ""
    say "    brew install mrtheroi/tap/memry"
}

# memry CLI 0.7.0+ reads the token from MEMRY_TOKEN when --token has no value,
# so the token never appears in the process list or the shell history.
connect_agents() {
    step "Connecting your agents"
    set -- setup --url "$URL" --token
    [ -z "$AGENTS" ] || set -- "$@" "--agents=${AGENTS}"
    MEMRY_TOKEN="$TOKEN" memry "$@"
}

print_summary() {
    cat <<SUMMARY

memry is running at ${URL}

Directory: ${DIR}
.env there holds your APP_KEY and database password: keep it private and back
it up. Manage the server from that directory (cd ${DIR}):

    docker compose stop                       # stop
    docker compose up -d app scheduler        # start
    docker compose logs app                   # logs

Upgrade: set MEMRY_IMAGE in .env to the new release
(ghcr.io/mrtheroi/memry-server:<version>), then run
    docker compose pull app
    docker compose run --rm migrate
    docker compose up -d app scheduler

Docs: https://github.com/mrtheroi/memry-server/blob/v${MEMRY_VERSION}/docs/self-hosting.md
SUMMARY
}

main() {
    parse_args "$@"
    preflight
    download_files
    configure_env
    start_server
    issue_token
    cli_status=0
    if [ "$INSTALL_CLI" = 1 ]; then
        ensure_cli || cli_status=$?
    fi
    if [ "$INSTALL_CLI" = 0 ] || [ "$cli_status" = 2 ]; then
        print_token
    elif [ "$cli_status" != 0 ]; then
        not_connected "could not install the memry CLI ${MIN_CLI_VERSION}+"
    elif ! connect_agents; then
        not_connected "memry setup failed"
    fi
    print_summary
}

# not_connected <reason>: the server runs but the agents are not connected.
not_connected() {
    printf '\nmemry install: %s; the server is running, but your agents are not connected yet.\n' "$1" >&2
    print_token
    print_summary
    exit 1
}

main "$@"
