#!/bin/sh
# Unit tests for install.sh. No Docker needed: docker, curl, memry, brew and
# sleep are replaced by stubs, and the script runs with a PATH that only holds
# those stubs plus the system tools it needs.
#
#   sh tests/install/install_test.sh
set -u

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="${ROOT}/install.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

passed=0
failed=0

# System tools install.sh may use, linked into one directory so a test can
# decide exactly which other commands exist.
SYSBIN="${WORK}/sysbin"
mkdir -p "$SYSBIN"
for tool in sh awk base64 basename cat chmod cksum cp cut date dirname env grep head \
    mkdir mktemp mv od openssl printf rm sed sort tail tr uname wc; do
    path="$(command -v "$tool" 2>/dev/null || true)"
    case "$path" in /*) ln -s "$path" "${SYSBIN}/${tool}" ;; esac
done

# --- stubs -----------------------------------------------------------------

write_stub() {
    cat > "${STUBS}/$1"
    chmod +x "${STUBS}/$1"
}

stub_docker() {
    write_stub docker <<'STUB'
#!/bin/sh
# Compose calls carry --project-directory and --env-file; they are logged on
# their own line and dropped, so the cases below match the plain commands.
# Compose runs with a minimal environment, so the STUB_* settings reach this
# stub through a file written by run_install.
[ ! -f "$(dirname "$0")/docker.env" ] || . "$(dirname "$0")/docker.env"
if [ "${1:-}" = compose ]; then
    shift
    flags=""
    while :; do
        case "${1:-}" in
            --project-directory | --env-file) flags="${flags} $1 $2"; shift 2 ;;
            *) break ;;
        esac
    done
    set -- compose "$@"
    echo "compose flags:${flags}" >> "$STUB_LOG"
    # `compose version` (the preflight) reads no project.
    [ "${2:-}" = version ] || echo "compose env: COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME-unset} DB_PASSWORD=${DB_PASSWORD-unset} APP_PORT=${APP_PORT-unset} REGISTRY=${REGISTRY-unset} DOCKER_CONTEXT=${DOCKER_CONTEXT-unset} AWS_PROFILE=${AWS_PROFILE-unset} MEMRY_IMAGE=${MEMRY_IMAGE-unset}" >> "$STUB_LOG"
fi
echo "docker $* @ $(pwd)" >> "$STUB_LOG"
case "$*" in
    "info") exit "${STUB_DAEMON_STATUS:-0}" ;;
    "compose version --short") echo "${STUB_COMPOSE_VERSION:-2.29.1}"; exit "${STUB_COMPOSE_STATUS:-0}" ;;
    "pull "*)
        # A plain docker pull runs with the caller's environment.
        echo "pull env: AWS_PROFILE=${AWS_PROFILE-unset} REGISTRY=${REGISTRY-unset}" >> "$STUB_LOG"
        exit "${STUB_PULL_STATUS:-0}"
        ;;
    "image inspect --format {{.Id}}"*)
        # The image ID: STUB_IMAGE_ID.
        echo "${STUB_IMAGE_ID:-sha256:0123456789abcdef0123456789abcdef}"
        exit 0
        ;;
    "image inspect --format"*)
        # Digest references of the local image: STUB_REPO_DIGESTS, space
        # separated, one per line (none: a locally built image).
        for digest in ${STUB_REPO_DIGESTS:-}; do echo "$digest"; done
        exit 0
        ;;
    "image inspect"*)
        if [ -n "${STUB_LOCAL_IMAGE:-}" ]; then [ "${3:-}" = "$STUB_LOCAL_IMAGE" ] || [ "${3:-}" = postgres:16-alpine ]; exit $?; fi
        exit "${STUB_IMAGE_STATUS:-0}"
        ;;
    "tag "*) exit "${STUB_TAG_STATUS:-0}" ;;
    "compose config --images")
        # The images Compose resolved: STUB_APP_IMAGE, or MEMRY_IMAGE in .env,
        # then PostgreSQL.
        echo "${STUB_APP_IMAGE:-$(sed -n 's/^MEMRY_IMAGE="*\([^" ]*\).*/\1/p' .env | tail -n 1)}"
        echo "postgres:16-alpine"
        ;;
    "compose port app 8000")
        # The port Compose published: STUB_PUBLISHED_PORT, or the digits of
        # APP_PORT in .env.
        port="${STUB_PUBLISHED_PORT:-$(sed -n 's/^APP_PORT="*\([0-9]*\).*/\1/p' .env | tail -n 1)}"
        echo "0.0.0.0:${port:-8000}"
        ;;
    "context inspect"*)
        # A named context ("context inspect NAME --format ...") reads the file
        # "${STUB_LOG}.context.NAME"; without a name, the active context.
        case "${3:-}" in
            --*) echo "${STUB_CONTEXT_HOST:-}" ;;
            *) cat "${STUB_LOG}.context.${3}" 2>/dev/null || exit 1 ;;
        esac
        ;;
    "volume ls "*)
        # The volumes that exist: STUB_VOLUMES, space separated. STUB_VOLUME_LS_STATUS
        # makes the command fail.
        [ -z "${STUB_VOLUME_LS_STATUS:-}" ] || exit "$STUB_VOLUME_LS_STATUS"
        for volume in ${STUB_VOLUMES:-}; do echo "$volume"; done
        ;;
    "volume inspect --format"*)
        # The creation date (STUB_CREATED_AT) or the Compose project label of a
        # volume: its name without the volume key, empty for the volumes in
        # STUB_UNLABELED_VOLUMES.
        case "${4:-}" in *CreatedAt*) echo "${STUB_CREATED_AT:-2025-01-02T03:04:05Z}"; exit 0 ;; esac
        case " ${STUB_UNLABELED_VOLUMES:-} " in *" ${5} "*) echo "" ;; *) echo "${5%_postgres-data}" ;; esac
        ;;
    "ps -a --filter"*)
        # One line (image=<image> dir=<working_dir label>) per container of the
        # project asked for in the filter. STUB_CONTAINERS: project=directory,
        # space separated. The image is the project's entry in STUB_CONTAINER_IMAGES
        # (project=image), a memry-server image by default. STUB_PS_STATUS makes
        # the command fail.
        [ -z "${STUB_PS_STATUS:-}" ] || exit "$STUB_PS_STATUS"
        project="${4#label=com.docker.compose.project=}"
        image="ghcr.io/mrtheroi/memry-server:0.18.1"
        for entry in ${STUB_CONTAINER_IMAGES:-}; do
            [ "${entry%%=*}" = "$project" ] && image="${entry#*=}"
        done
        for entry in ${STUB_CONTAINERS:-}; do
            [ "${entry%%=*}" = "$project" ] && echo "image=${image} dir=${entry#*=}"
        done
        ;;
    "volume inspect"*)
        [ "${3:-}" = "${STUB_EXISTING_VOLUME:-}" ] && exit 0
        exit "${STUB_VOLUME_STATUS:-1}"
        ;;
    *" token "*)
        echo "MEMRY_TOKEN in env: ${MEMRY_TOKEN:-unset}" >> "$STUB_LOG"
        [ -n "${STUB_NO_TOKEN:-}" ] || printf 'Token: %s\r\n' "${STUB_TOKEN:-7|AbCdEf123}"
        ;;
esac
exit 0
STUB
}

stub_curl() {
    write_stub curl <<'STUB'
#!/bin/sh
echo "curl $*" >> "$STUB_LOG"
out=""
url=""
while [ $# -gt 0 ]; do
    case "$1" in
        -o | -fsSLo) out="$2"; shift ;;
        -w) printf '%s' "${STUB_UP_STATUS:-200}"; exit 0 ;;
        http*) url="$1" ;;
    esac
    shift
done
case "$url" in
    *"${STUB_CURL_FAIL:-no-failure}")
        printf 'partial' > "$out"
        exit 22
        ;;
    */docker-compose.yml) cp "${STUB_SOURCE}/docker-compose.yml" "$out" ;;
    */community.env.example) cp "${STUB_SOURCE}/docker/community.env.example" "$out" ;;
    *) exit 22 ;;
esac
STUB
}

stub_memry() {
    write_stub memry <<'STUB'
#!/bin/sh
case "$1" in
    --version) printf 'Memry %s\n' "${STUB_MEMRY_VERSION:-0.7.0}" ;;
    setup)
        echo "memry $*" >> "$STUB_LOG"
        echo "memry MEMRY_TOKEN=${MEMRY_TOKEN:-unset}" >> "$STUB_LOG"
        exit "${STUB_SETUP_STATUS:-0}"
        ;;
esac
STUB
}

stub_brew() {
    write_stub brew <<'STUB'
#!/bin/sh
echo "brew $*" >> "$STUB_LOG"
exit 0
STUB
}

# A fake clock: every `date +%s` call moves it STUB_CLOCK_STEP seconds forward
# (2 by default, like one sleep between probes).
stub_date() {
    write_stub date <<'STUB'
#!/bin/sh
clock="${STUB_LOG}.clock"
[ -f "$clock" ] || echo 1000 > "$clock"
now="$(cat "$clock")"
echo $((now + ${STUB_CLOCK_STEP:-2})) > "$clock"
echo "$now"
STUB
}

stub_sleep() {
    write_stub sleep <<'STUB'
#!/bin/sh
exit 0
STUB
}

# Fresh stubs, log and install directory for each test.
setup_test() {
    T="${WORK}/t$((passed + failed))"
    STUBS="${T}/stubs"
    mkdir -p "$STUBS"
    STUB_LOG="${T}/log"
    : > "$STUB_LOG"
    DIR="${T}/memry"
    OUT="${T}/out"
    export STUB_LOG STUB_SOURCE="$ROOT"
    unset STUB_DAEMON_STATUS STUB_COMPOSE_VERSION STUB_COMPOSE_STATUS STUB_PULL_STATUS \
        STUB_IMAGE_STATUS STUB_TOKEN STUB_UP_STATUS STUB_MEMRY_VERSION STUB_SETUP_STATUS \
        STUB_CURL_FAIL STUB_VOLUME_STATUS STUB_EXISTING_VOLUME STUB_CLOCK_STEP STUB_PUBLISHED_PORT \
        STUB_LOCAL_IMAGE STUB_APP_IMAGE STUB_NO_TOKEN STUB_CONTEXT_HOST STUB_REPO_DIGESTS STUB_IMAGE_ID STUB_TAG_STATUS \
        STUB_VOLUMES STUB_CONTAINERS STUB_UNLABELED_VOLUMES \
        STUB_CONTAINER_IMAGES STUB_VOLUME_LS_STATUS STUB_PS_STATUS STUB_CREATED_AT
    stub_docker
    stub_curl
    stub_memry
    stub_brew
    stub_sleep
    stub_date
}

# Runs install.sh with only the stubs and the system tools on PATH.
run_install() {
    stub_value=""
    for stub_var in STUB_LOG STUB_SOURCE STUB_DAEMON_STATUS STUB_COMPOSE_VERSION STUB_COMPOSE_STATUS \
        STUB_PULL_STATUS STUB_IMAGE_STATUS STUB_TOKEN STUB_NO_TOKEN STUB_VOLUME_STATUS \
        STUB_EXISTING_VOLUME STUB_PUBLISHED_PORT STUB_LOCAL_IMAGE STUB_APP_IMAGE STUB_CONTEXT_HOST STUB_REPO_DIGESTS STUB_IMAGE_ID STUB_TAG_STATUS STUB_VOLUMES STUB_CONTAINERS STUB_UNLABELED_VOLUMES \
        STUB_CONTAINER_IMAGES STUB_VOLUME_LS_STATUS STUB_PS_STATUS STUB_CREATED_AT; do
        eval "stub_value=\${$stub_var-}"
        printf "%s='%s'; export %s\n" "$stub_var" "$stub_value" "$stub_var"
    done > "${STUBS}/docker.env"
    # AMBIENT_ENV holds NAME=value words: split on purpose.
    # shellcheck disable=SC2086
    env -i HOME="$T" PATH="${STUBS}:${SYSBIN}" STUB_LOG="$STUB_LOG" STUB_SOURCE="$STUB_SOURCE" \
        ${STUB_DAEMON_STATUS:+STUB_DAEMON_STATUS="$STUB_DAEMON_STATUS"} \
        ${STUB_COMPOSE_VERSION:+STUB_COMPOSE_VERSION="$STUB_COMPOSE_VERSION"} \
        ${STUB_COMPOSE_STATUS:+STUB_COMPOSE_STATUS="$STUB_COMPOSE_STATUS"} \
        ${STUB_PULL_STATUS:+STUB_PULL_STATUS="$STUB_PULL_STATUS"} \
        ${STUB_IMAGE_STATUS:+STUB_IMAGE_STATUS="$STUB_IMAGE_STATUS"} \
        ${STUB_TOKEN:+STUB_TOKEN="$STUB_TOKEN"} \
        ${STUB_UP_STATUS:+STUB_UP_STATUS="$STUB_UP_STATUS"} \
        ${STUB_MEMRY_VERSION:+STUB_MEMRY_VERSION="$STUB_MEMRY_VERSION"} \
        ${STUB_SETUP_STATUS:+STUB_SETUP_STATUS="$STUB_SETUP_STATUS"} \
        ${STUB_CURL_FAIL:+STUB_CURL_FAIL="$STUB_CURL_FAIL"} \
        ${STUB_VOLUME_STATUS:+STUB_VOLUME_STATUS="$STUB_VOLUME_STATUS"} \
        ${STUB_EXISTING_VOLUME:+STUB_EXISTING_VOLUME="$STUB_EXISTING_VOLUME"} \
        ${STUB_PUBLISHED_PORT:+STUB_PUBLISHED_PORT="$STUB_PUBLISHED_PORT"} \
        ${STUB_LOCAL_IMAGE:+STUB_LOCAL_IMAGE="$STUB_LOCAL_IMAGE"} \
        ${STUB_APP_IMAGE:+STUB_APP_IMAGE="$STUB_APP_IMAGE"} \
        ${STUB_NO_TOKEN:+STUB_NO_TOKEN="$STUB_NO_TOKEN"} \
        ${STUB_REPO_DIGESTS:+STUB_REPO_DIGESTS="$STUB_REPO_DIGESTS"} \
        ${STUB_IMAGE_ID:+STUB_IMAGE_ID="$STUB_IMAGE_ID"} \
        ${STUB_TAG_STATUS:+STUB_TAG_STATUS="$STUB_TAG_STATUS"} \
        ${STUB_VOLUMES:+STUB_VOLUMES="$STUB_VOLUMES"} \
        ${STUB_CONTAINERS:+STUB_CONTAINERS="$STUB_CONTAINERS"} \
        ${STUB_UNLABELED_VOLUMES:+STUB_UNLABELED_VOLUMES="$STUB_UNLABELED_VOLUMES"} \
        ${STUB_CONTAINER_IMAGES:+STUB_CONTAINER_IMAGES="$STUB_CONTAINER_IMAGES"} \
        ${STUB_VOLUME_LS_STATUS:+STUB_VOLUME_LS_STATUS="$STUB_VOLUME_LS_STATUS"} \
        ${STUB_PS_STATUS:+STUB_PS_STATUS="$STUB_PS_STATUS"} \
        ${STUB_CREATED_AT:+STUB_CREATED_AT="$STUB_CREATED_AT"} \
        ${STUB_CONTEXT_HOST:+STUB_CONTEXT_HOST="$STUB_CONTEXT_HOST"} \
        ${STUB_CLOCK_STEP:+STUB_CLOCK_STEP="$STUB_CLOCK_STEP"} \
        ${MEMRY_VERSION_OVERRIDE:+MEMRY_VERSION="$MEMRY_VERSION_OVERRIDE"} \
        ${MEMRY_IMAGE_OVERRIDE:+MEMRY_IMAGE="$MEMRY_IMAGE_OVERRIDE"} \
        ${MEMRY_SOURCE_DIR_OVERRIDE:+MEMRY_SOURCE_DIR="$MEMRY_SOURCE_DIR_OVERRIDE"} \
        ${MEMRY_UP_TIMEOUT_OVERRIDE:+MEMRY_UP_TIMEOUT="$MEMRY_UP_TIMEOUT_OVERRIDE"} \
        ${AMBIENT_ENV-} \
        sh "$SCRIPT" "$@" > "$OUT" 2>&1
    STATUS=$?
}

# --- assertions -------------------------------------------------------------

fail() {
    echo "    $*"
    TEST_OK=0
}

assert_status() {
    [ "$STATUS" -eq "$1" ] || fail "expected exit status $1, got $STATUS"
}

assert_output_contains() {
    grep -qF -- "$1" "$OUT" || fail "output does not contain: $1"
}

assert_output_not_contains() {
    if grep -qF -- "$1" "$OUT"; then fail "output contains: $1"; fi
}

assert_log_contains() {
    grep -qF -- "$1" "$STUB_LOG" || fail "log does not contain: $1"
}

assert_log_not_contains() {
    if grep -qF -- "$1" "$STUB_LOG"; then fail "log contains: $1"; fi
}

assert_env_is_private() {
    # shellcheck disable=SC2012 # one known file name
    perms="$(ls -l "${DIR}/.env" | cut -c1-10)"
    [ "$perms" = "-rw-------" ] || fail ".env permissions are ${perms}"
}

env_value() {
    sed -n "s/^$1=//p" "${DIR}/.env" | tail -n 1
}

run_test() {
    TEST_OK=1
    setup_test
    unset MEMRY_VERSION_OVERRIDE MEMRY_IMAGE_OVERRIDE MEMRY_SOURCE_DIR_OVERRIDE AMBIENT_ENV \
        MEMRY_UP_TIMEOUT_OVERRIDE
    "$1"
    if [ "$TEST_OK" = 1 ]; then
        passed=$((passed + 1))
        echo "ok   $1"
    else
        failed=$((failed + 1))
        echo "FAIL $1"
        sed 's/^/    | /' "$OUT" 2>/dev/null | tail -n 20
    fi
}

# --- tests ------------------------------------------------------------------

test_help_prints_usage() {
    run_install --help
    assert_status 0
    assert_output_contains "Usage: sh install.sh --email"
}

test_email_is_required() {
    run_install
    assert_status 2
    assert_output_contains "--email is required"
}

test_invalid_email_is_rejected() {
    for email in "not-an-email" "a@b" "a b@example.com" "@example.com"; do
        run_install --email "$email"
        assert_status 2
        assert_output_contains "not a valid email"
    done
}

test_preflight_fails_without_docker() {
    rm "${STUBS}/docker"
    run_install --email you@example.com
    assert_status 1
    assert_output_contains "Docker is not installed"
    assert_output_contains "https://docs.docker.com/get-docker/"
}

test_preflight_fails_without_compose_v2() {
    STUB_COMPOSE_STATUS=1
    run_install --email you@example.com
    assert_status 1
    assert_output_contains "Docker Compose v2 is required"
    assert_output_contains "https://docs.docker.com/get-docker/"

    STUB_COMPOSE_STATUS=0 STUB_COMPOSE_VERSION=1.29.2
    run_install --email you@example.com
    assert_status 1
    assert_output_contains "Docker Compose v2 is required"
}

test_preflight_fails_without_curl() {
    rm "${STUBS}/curl"
    run_install --email you@example.com
    assert_status 1
    assert_output_contains "curl is required"
}

test_preflight_fails_when_the_docker_daemon_is_not_running() {
    STUB_DAEMON_STATUS=1
    run_install --email you@example.com
    assert_status 1
    assert_output_contains "Docker daemon is not running"
    assert_output_contains "https://docs.docker.com/get-docker/"
}

# A release must not ship a script that installs the previous release.
test_pinned_version_matches_the_latest_changelog_release() {
    # shellcheck disable=SC2016 # matches a literal ${MEMRY_VERSION:-...}
    pinned="$(sed -n 's/^MEMRY_VERSION="\${MEMRY_VERSION:-\([0-9.]*\)}"$/\1/p' "$SCRIPT")"
    latest="$(sed -n 's/^## \[\([0-9][0-9.]*\)\].*/\1/p' "${ROOT}/CHANGELOG.md" | head -n 1)"
    [ -n "$pinned" ] || fail "no MEMRY_VERSION=\"\${MEMRY_VERSION:-X.Y.Z}\" line in install.sh"
    [ "$pinned" = "$latest" ] || fail "install.sh pins ${pinned:-nothing}, the latest CHANGELOG release is ${latest}"
}

test_downloads_the_release_files_into_the_directory() {
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "https://raw.githubusercontent.com/mrtheroi/memry-server/v0.18.1/docker-compose.yml"
    assert_log_contains "https://raw.githubusercontent.com/mrtheroi/memry-server/v0.18.1/docker/community.env.example"
    cmp -s "${DIR}/docker-compose.yml" "${ROOT}/docker-compose.yml" || fail "docker-compose.yml not downloaded"
    [ -f "${DIR}/.env" ] || fail ".env not created"
}

test_configures_a_new_env_file() {
    run_install --email you@example.com --dir "$DIR" --port 8123 --no-cli
    assert_status 0
    key="$(env_value APP_KEY)"
    case "$key" in base64:*) ;; *) fail "APP_KEY is not base64:...: $key" ;; esac
    bytes="$(printf '%s' "${key#base64:}" | base64 -d 2>/dev/null | wc -c | tr -d ' ')"
    [ "$bytes" = 32 ] || fail "APP_KEY decodes to ${bytes} bytes, expected 32"
    password="$(env_value DB_PASSWORD)"
    printf '%s' "$password" | grep -Eq '^[A-Za-z0-9]{32,}$' || fail "weak or unsafe DB_PASSWORD: $password"
    [ "$(env_value APP_URL)" = "http://localhost:8123" ] || fail "APP_URL is $(env_value APP_URL)"
    [ "$(env_value APP_PORT)" = "8123" ] || fail "APP_PORT is $(env_value APP_PORT)"
    [ "$(env_value MEMRY_IMAGE)" = "ghcr.io/mrtheroi/memry-server:0.18.1" ] || fail "MEMRY_IMAGE is $(env_value MEMRY_IMAGE)"
    [ "$(grep -c '^APP_KEY=' "${DIR}/.env")" = 1 ] || fail "APP_KEY appears more than once"
    assert_env_is_private
}

test_second_run_keeps_the_existing_env_values() {
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    cp "${DIR}/.env" "${T}/first.env"
    run_install --email you@example.com --dir "$DIR" --port 9000 --no-cli
    assert_status 0
    cmp -s "${DIR}/.env" "${T}/first.env" || fail ".env changed on the second run"
}

test_existing_env_only_gets_its_empty_required_values_filled() {
    mkdir -p "$DIR"
    printf 'APP_KEY=\nDB_PASSWORD=kept-password\nAPP_URL=https://memry.example.com\nMAIL_MAILER=smtp\n' > "${DIR}/.env"
    chmod 644 "${DIR}/.env"
    run_install --email you@example.com --dir "$DIR" --port 8123 --no-cli
    assert_status 0
    case "$(env_value APP_KEY)" in base64:?*) ;; *) fail "empty APP_KEY was not generated" ;; esac
    [ "$(env_value DB_PASSWORD)" = "kept-password" ] || fail "DB_PASSWORD was replaced"
    [ "$(env_value APP_URL)" = "https://memry.example.com" ] || fail "APP_URL was replaced"
    [ "$(env_value MAIL_MAILER)" = "smtp" ] || fail "MAIL_MAILER was replaced"
    [ "$(env_value APP_PORT)" = "8123" ] || fail "missing APP_PORT was not added"
    [ "$(env_value MEMRY_IMAGE)" = "ghcr.io/mrtheroi/memry-server:0.18.1" ] || fail "missing MEMRY_IMAGE was not added"
    assert_env_is_private
}

test_version_image_and_source_can_be_overridden() {
    MEMRY_VERSION_OVERRIDE=9.8.7
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "https://raw.githubusercontent.com/mrtheroi/memry-server/v9.8.7/docker-compose.yml"
    [ "$(env_value MEMRY_IMAGE)" = "ghcr.io/mrtheroi/memry-server:9.8.7" ] || fail "MEMRY_IMAGE is $(env_value MEMRY_IMAGE)"

    DIR="${T}/second"
    : > "$STUB_LOG"
    MEMRY_IMAGE_OVERRIDE=memry-server:ci MEMRY_SOURCE_DIR_OVERRIDE="$ROOT"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_not_contains "raw.githubusercontent.com"
    [ "$(env_value MEMRY_IMAGE)" = "memry-server:ci" ] || fail "MEMRY_IMAGE is $(env_value MEMRY_IMAGE)"
}

test_starts_the_server_in_the_directory() {
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "docker pull ghcr.io/mrtheroi/memry-server:0.18.1 @ ${DIR}"
    assert_log_contains "docker pull postgres:16-alpine @ ${DIR}"
    assert_log_contains "docker compose run --rm migrate @ ${DIR}"
    assert_log_contains "docker compose up -d app scheduler @ ${DIR}"
    assert_log_contains "http://localhost:8000/up"
    order="$(grep -n -e 'docker pull' -e 'run --rm migrate' -e 'up -d app' -e '/up' "$STUB_LOG" | cut -d: -f1 | xargs)"
    [ "$order" = "$(echo "$order" | tr ' ' '\n' | sort -n | xargs)" ] || fail "steps out of order: $order"
}

test_pulls_resolved_images_with_the_callers_environment() {
    mkdir -p "$DIR"
    # shellcheck disable=SC2016 # a literal Compose interpolation
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw\nAPP_PORT=8000\nMEMRY_IMAGE=${REGISTRY:-ghcr.io/acme}/memry-server:1\n' > "${DIR}/.env"
    AMBIENT_ENV="AWS_PROFILE=ecr REGISTRY=evil.example"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "docker compose config --images @ ${DIR}"
    assert_log_contains "AWS_PROFILE=unset"
    assert_log_contains "pull env: AWS_PROFILE=ecr"
    assert_log_not_contains "docker pull evil.example"
    assert_log_not_contains "compose pull"
    assert_log_contains "docker compose up"
    assert_log_not_contains " token "
}

# `docker compose run --pull` only exists since Compose v2.32.2 (`up --pull`
# since ~v2.10): the installer never passes it.
test_compose_commands_work_on_older_compose_v2() {
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_not_contains "--pull"
}

# The env line of every Compose `up`/`run` call (the stub logs it just before
# the command).
compose_env_of_up_and_run() {
    awk '/^compose env:/ { env = $0; next } /^docker compose (up|run) / { print env }' "$STUB_LOG"
}

# Compose re-pulls an untagged or :latest image even with the `missing` policy,
# so after pulling, the installer runs Compose with the digest it pulled: a
# digest reference is local and never "latest".
test_compose_runs_the_image_digest_it_pulled() {
    STUB_REPO_DIGESTS="ghcr.io/mrtheroi/memry-server@sha256:aaa111"
    run_install --email you@example.com --dir "$DIR"
    assert_status 0
    calls="$(compose_env_of_up_and_run)"
    [ "$(echo "$calls" | grep -c .)" -ge 3 ] || fail "expected migrate, up and token calls: $calls"
    ! echo "$calls" | grep -qv 'MEMRY_IMAGE=ghcr.io/mrtheroi/memry-server@sha256:aaa111' \
        || fail "a Compose call did not get the digest: $calls"
}

test_a_latest_image_in_env_is_pinned_to_its_digest() {
    mkdir -p "$DIR"
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw\nAPP_PORT=8000\nAPP_URL=http://localhost:8000\nMEMRY_IMAGE=ghcr.io/acme/memry-server:latest\n' > "${DIR}/.env"
    cp "${DIR}/.env" "${T}/before.env"
    STUB_REPO_DIGESTS="ghcr.io/acme/memry-server@sha256:bbb222"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "MEMRY_IMAGE=ghcr.io/acme/memry-server@sha256:bbb222"
    assert_log_not_contains "MEMRY_IMAGE=ghcr.io/acme/memry-server:latest"
    cmp -s "${DIR}/.env" "${T}/before.env" || fail ".env changed on disk"
}

test_the_digest_of_the_pulled_repository_wins_among_several() {
    STUB_REPO_DIGESTS="docker.io/other/mirror@sha256:ccc333 ghcr.io/mrtheroi/memry-server@sha256:ddd444"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "MEMRY_IMAGE=ghcr.io/mrtheroi/memry-server@sha256:ddd444"
    assert_log_not_contains "sha256:ccc333"
}

test_the_first_digest_is_used_when_none_matches_the_repository() {
    STUB_REPO_DIGESTS="docker.io/other/mirror@sha256:ccc333 quay.io/x/y@sha256:eee555"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "MEMRY_IMAGE=docker.io/other/mirror@sha256:ccc333"
}

# A locally built or loaded image has no digest, and an untagged or :latest
# reference would be pulled by Compose: it gets an immutable local tag derived
# from its image ID, which is local and never "latest".
test_a_digestless_local_image_is_pinned_with_a_tag_from_its_id() {
    mkdir -p "$DIR"
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw\nAPP_PORT=8000\nAPP_URL=http://localhost:8000\nMEMRY_IMAGE=memry-server:latest\n' > "${DIR}/.env"
    cp "${DIR}/.env" "${T}/before.env"
    STUB_IMAGE_ID="sha256:abcdef0123456789ffff"
    run_install --email you@example.com --dir "$DIR"
    assert_status 0
    assert_log_contains "docker tag memry-server:latest memry-server:pinned-abcdef012345"
    calls="$(compose_env_of_up_and_run)"
    [ "$(echo "$calls" | grep -c .)" -ge 3 ] || fail "expected migrate, up and token calls: $calls"
    ! echo "$calls" | grep -qv 'MEMRY_IMAGE=memry-server:pinned-abcdef012345' || fail "a Compose call did not get the pinned tag: $calls"
    assert_log_not_contains "--pull"
    cmp -s "${DIR}/.env" "${T}/before.env" || fail ".env changed on disk"
}

test_a_digested_image_is_not_tagged() {
    STUB_REPO_DIGESTS="ghcr.io/mrtheroi/memry-server@sha256:aaa111"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_not_contains "docker tag "
}

test_fails_clearly_when_the_local_pin_tag_cannot_be_created() {
    STUB_TAG_STATUS=1
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    assert_output_contains "could not tag"
}

test_the_callers_memry_image_cannot_change_compose() {
    mkdir -p "$DIR"
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw\nAPP_PORT=8000\nMEMRY_IMAGE=img:1\n' > "${DIR}/.env"
    AMBIENT_ENV="MEMRY_IMAGE=evil.example/memry:1"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_log_not_contains "MEMRY_IMAGE=evil.example"
    assert_status 0
    assert_log_not_contains "docker pull evil.example"
}

test_fails_with_a_hint_when_the_server_never_comes_up() {
    STUB_UP_STATUS=503
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    assert_output_contains "did not answer http://localhost:8000/up"
    assert_output_contains "docker compose logs app"
    assert_log_not_contains " token "
}

test_falls_back_to_a_local_image_only_when_it_exists() {
    STUB_PULL_STATUS=1
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_output_contains "using the local copy"

    DIR="${T}/second"
    STUB_IMAGE_STATUS=1
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    assert_output_contains "could not pull ghcr.io/mrtheroi/memry-server:0.18.1"
}

test_no_cli_issues_no_token_and_prints_the_on_request_command() {
    STUB_TOKEN='12|Secr3tTokenValue'
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_not_contains " token "
    assert_output_not_contains "Secr3tTokenValue"
    assert_output_contains "cd ${DIR} && docker compose run --rm app token 'you@example.com'"
    assert_output_contains "store it like a password"
    assert_output_contains "memry setup --url http://localhost:8000 --token"
}

test_connects_agents_with_the_token_in_the_environment() {
    STUB_TOKEN='12|Secr3tTokenValue'
    run_install --email you@example.com --dir "$DIR" --agents claude-code,codex
    assert_status 0
    assert_log_contains "memry setup --url http://localhost:8000 --token --agents=claude-code,codex"
    assert_log_contains "memry MEMRY_TOKEN=12|Secr3tTokenValue"
    assert_log_not_contains "--token 12|"
    assert_log_not_contains "--token=12|"
    assert_output_not_contains "Secr3tTokenValue"
    assert_log_not_contains "brew "
}

test_does_not_print_the_token_when_memry_setup_fails() {
    STUB_TOKEN='12|Secr3tTokenValue' STUB_SETUP_STATUS=1
    run_install --email you@example.com --dir "$DIR"
    assert_status 1
    assert_output_contains "memry setup failed"
    assert_output_not_contains "Secr3tTokenValue"
    assert_output_contains "expires after 30 days"
    assert_output_contains "docker compose run --rm app token 'you@example.com'"
    assert_output_contains "memry setup --url http://localhost:8000 --token"
}

test_installs_the_cli_with_brew_when_it_is_missing() {
    mv "${STUBS}/memry" "${T}/memry-stub"
    write_stub brew <<STUB
#!/bin/sh
echo "brew \$*" >> "\$STUB_LOG"
mv "${T}/memry-stub" "${STUBS}/memry"
STUB
    run_install --email you@example.com --dir "$DIR"
    assert_status 0
    assert_log_contains "brew install mrtheroi/tap/memry"
    assert_log_contains "memry setup --url http://localhost:8000 --token"
}

test_without_brew_prints_instructions_and_issues_no_token() {
    rm "${STUBS}/memry" "${STUBS}/brew"
    STUB_TOKEN='12|Secr3tTokenValue'
    run_install --email you@example.com --dir "$DIR" --agents codex
    assert_status 0
    assert_output_contains "brew install mrtheroi/tap/memry"
    assert_log_not_contains " token "
    assert_output_not_contains "Secr3tTokenValue"
    assert_output_contains "docker compose run --rm app token 'you@example.com'"
    assert_output_contains "memry setup --url http://localhost:8000 --token --agents=codex"
}

test_upgrades_a_cli_older_than_0_7_0() {
    STUB_MEMRY_VERSION=0.6.9
    write_stub brew <<STUB
#!/bin/sh
echo "brew \$*" >> "\$STUB_LOG"
sed -i.bak 's/STUB_MEMRY_VERSION:-0.7.0/STUB_MEMRY_VERSION_AFTER:-0.7.1/' "${STUBS}/memry"
STUB
    run_install --email you@example.com --dir "$DIR"
    assert_status 0
    assert_log_contains "brew upgrade memry"
    assert_log_contains "memry setup --url http://localhost:8000 --token"
}

test_does_not_upgrade_a_recent_cli() {
    for version in 0.7.0 0.10.0 1.0.0 "$(printf '\033[32m0.8.2\033[0m')"; do
        : > "$STUB_LOG"
        STUB_MEMRY_VERSION="$version"
        run_install --email you@example.com --dir "$DIR"
        assert_status 0
        assert_log_not_contains "brew upgrade"
    done
}

test_finishes_with_a_summary() {
    run_install --email you@example.com --dir "$DIR"
    assert_status 0
    assert_output_contains "memry is running at http://localhost:8000"
    assert_output_contains "cd ${DIR}"
    assert_output_contains "docker compose stop"
    assert_output_contains "docker compose up -d app scheduler"
    assert_output_contains "docker compose run --rm migrate"
    assert_output_contains "https://github.com/mrtheroi/memry-server/blob/v0.18.1/docs/self-hosting.md"
}

test_rejects_bad_options() {
    for port in abc 0 70000 80a 99999999999999999999 000008000; do
        run_install --email you@example.com --port "$port"
        assert_status 2
        assert_output_contains "--port must be"
    done
    run_install --email you@example.com --bogus
    assert_status 2
    assert_output_contains "unknown option: --bogus"
}

test_reports_a_failed_cli_install() {
    rm "${STUBS}/memry"
    write_stub brew <<'STUB'
#!/bin/sh
exit 1
STUB
    run_install --email you@example.com --dir "$DIR"
    assert_status 1
    assert_output_contains "could not install the memry CLI"
    assert_log_not_contains " token "
    assert_output_contains "docker compose run --rm app token 'you@example.com'"
    assert_output_contains "store it like a password"
}

test_relative_and_tilde_directories_become_absolute() {
    mkdir -p "${T}/cwd"
    (cd "${T}/cwd" && run_install --email you@example.com --dir rel --no-cli)
    assert_log_contains "docker compose up -d app scheduler @ $(cd "${T}/cwd" && pwd -P)/rel"
    # shellcheck disable=SC2088 # the literal ~ is what is being tested
    run_install --email you@example.com --dir '~/tilde' --no-cli
    assert_status 0
    assert_log_contains "docker compose up -d app scheduler @ ${T}/tilde"
}

test_reads_quoted_values_from_an_existing_env() {
    mkdir -p "$DIR"
    printf 'APP_KEY="base64:abc"\nDB_PASSWORD="pw"\nAPP_PORT="8124"\nMEMRY_IMAGE="img:1"\n' > "${DIR}/.env"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "http://localhost:8124/up"
    assert_output_contains "memry setup --url http://localhost:8124 --token"
}

test_a_failed_download_leaves_no_partial_file() {
    for file in docker-compose.yml community.env.example; do
        DIR="${T}/${file}"
        STUB_CURL_FAIL="/${file}"
        run_install --email you@example.com --dir "$DIR" --no-cli
        assert_status 1
        assert_output_contains "could not download"
        leftovers="$(ls -A "$DIR")"
        [ -z "$leftovers" ] || [ "$leftovers" = "docker-compose.yml" ] || fail "files left in ${DIR}: ${leftovers}"
        if [ "$file" = docker-compose.yml ] && [ -n "$leftovers" ]; then fail "partial docker-compose.yml kept"; fi
    done
    unset STUB_CURL_FAIL
    DIR="${T}/docker-compose.yml"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    cmp -s "${DIR}/docker-compose.yml" "${ROOT}/docker-compose.yml" || fail "docker-compose.yml not downloaded on the next run"
}

test_the_up_timeout_is_wall_clock_time_including_the_probes() {
    STUB_UP_STATUS=503 STUB_CLOCK_STEP=59
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    assert_output_contains "within 120s"
    probes="$(grep -c '/up' "$STUB_LOG")"
    [ "$probes" = 2 ] || fail "expected 2 probes in 120s with 59s per probe, got ${probes}"
    assert_log_contains "--max-time 2 "
}

test_new_installs_get_a_project_name_unique_to_their_directory() {
    DIR="${T}/a/memry-community"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    first="$(env_value COMPOSE_PROJECT_NAME)"
    printf '%s' "$first" | grep -Eq '^memry-community-[0-9]+$' || fail "unexpected project name: ${first}"

    DIR="${T}/b/memry-community"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    second="$(env_value COMPOSE_PROJECT_NAME)"
    if [ -z "$second" ] || [ "$second" = "$first" ]; then fail "same basename, same project name: ${first}"; fi

    DIR="${T}/c/My Memry.Server"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    printf '%s' "$(env_value COMPOSE_PROJECT_NAME)" | grep -Eq '^my-memry-server-[0-9]+$' \
        || fail "unexpected project name: $(env_value COMPOSE_PROJECT_NAME)"
}

test_existing_installs_keep_their_project_name() {
    mkdir -p "$DIR"
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw\n' > "${DIR}/.env"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    if grep -q '^COMPOSE_PROJECT_NAME=' "${DIR}/.env"; then
        fail "COMPOSE_PROJECT_NAME added to an existing install (would orphan its volume)"
    fi

    printf 'COMPOSE_PROJECT_NAME=custom\n' >> "${DIR}/.env"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    [ "$(env_value COMPOSE_PROJECT_NAME)" = custom ] || fail "COMPOSE_PROJECT_NAME was replaced"
}

test_refuses_a_new_env_when_the_database_volume_exists() {
    STUB_VOLUME_STATUS=0
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    project="$(printf 'memry-%s' "$(printf '%s' "$DIR" | cksum | cut -d ' ' -f 1)")"
    assert_log_contains "docker volume inspect ${project}_postgres-data"
    assert_output_contains "${project}_postgres-data already exists"
    assert_output_contains "docker volume rm ${project}_postgres-data"
    [ ! -e "${DIR}/.env" ] || fail ".env was created"
    for left in "$DIR"/* "$DIR"/.[!.]*; do
        case "$left" in */docker-compose.yml | *'/*' | *'/.[!.]*') ;; *) fail "file left: ${left}" ;; esac
    done
    assert_log_not_contains "docker pull"
}

test_refuses_a_new_env_when_the_legacy_default_volume_exists() {
    DIR="${T}/Memry_Community"
    STUB_EXISTING_VOLUME=memry_community_postgres-data
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    assert_output_contains "memry_community_postgres-data already exists"
    assert_output_contains "install into a directory with another name"
    [ ! -e "${DIR}/.env" ] || fail ".env was created"
    assert_log_not_contains "docker pull"
}

test_compose_ignores_variables_exported_in_the_calling_shell() {
    AMBIENT_ENV="COMPOSE_PROJECT_NAME=other DB_PASSWORD=ambient APP_PORT=1234 COMPOSE_FILE=other.yml"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "compose env: COMPOSE_PROJECT_NAME=unset DB_PASSWORD=unset APP_PORT=unset"
    assert_log_not_contains "=other"
    assert_log_not_contains "=ambient"
    assert_log_contains "compose flags: --project-directory ${DIR} --env-file ${DIR}/.env"
    [ "$(env_value APP_PORT)" = 8000 ] || fail "APP_PORT came from the calling shell"
}

test_compose_ignores_variables_referenced_in_env_values() {
    mkdir -p "$DIR"
    # shellcheck disable=SC2016 # a literal Compose interpolation
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw\nAPP_PORT=8000\nMEMRY_IMAGE=${REGISTRY:-ghcr.io/acme}/memry-server:1\n' > "${DIR}/.env"
    AMBIENT_ENV="REGISTRY=evil.example DOCKER_CONTEXT=desktop"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "REGISTRY=unset"
    assert_log_not_contains "evil.example/memry-server"
    assert_log_contains "DOCKER_CONTEXT=desktop"
}

test_refuses_a_remote_docker_host() {
    AMBIENT_ENV="DOCKER_HOST=tcp://10.0.0.5:2376"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    assert_output_contains "remote Docker daemon (tcp://10.0.0.5:2376)"
    assert_output_contains "https://github.com/mrtheroi/memry-server/blob/v0.18.1/docs/self-hosting.md"
    assert_output_contains "Manual setup"
    assert_log_not_contains "docker pull"
    [ ! -e "${DIR}/.env" ] || fail ".env was created"
}

test_refuses_a_remote_docker_context() {
    STUB_CONTEXT_HOST="ssh://deploy@build.example.com"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    assert_output_contains "remote Docker daemon (ssh://deploy@build.example.com)"
    assert_output_contains "https://github.com/mrtheroi/memry-server/blob/v0.18.1/docs/self-hosting.md"
    assert_log_contains "docker context inspect --format {{.Endpoints.docker.Host}}"
    assert_log_not_contains "docker pull"
}

test_docker_context_overrides_docker_host_when_remote() {
    printf 'ssh://deploy@build.example.com\n' > "${STUB_LOG}.context.remote"
    AMBIENT_ENV="DOCKER_HOST=unix:///var/run/docker.sock DOCKER_CONTEXT=remote"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    assert_output_contains "remote Docker daemon (ssh://deploy@build.example.com)"
    assert_log_not_contains "docker pull"
}

test_docker_context_overrides_docker_host_when_local() {
    printf 'unix:///var/run/docker.sock\n' > "${STUB_LOG}.context.local"
    AMBIENT_ENV="DOCKER_HOST=tcp://10.0.0.5:2376 DOCKER_CONTEXT=local"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
}

test_local_docker_endpoints_are_accepted() {
    for host in unix:///var/run/docker.sock npipe:////./pipe/docker_engine tcp://localhost:2375 tcp://127.0.0.1:2375; do
        AMBIENT_ENV="DOCKER_HOST=${host}"
        run_install --email you@example.com --dir "$DIR" --no-cli
        assert_status 0
    done
    unset AMBIENT_ENV
    STUB_CONTEXT_HOST="unix:///Users/me/.docker/run/docker.sock"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
}

test_url_option_is_not_supported() {
    run_install --email you@example.com --url http://10.0.0.5:8000
    assert_status 2
    assert_output_contains "unknown option: --url"
}

test_reads_env_values_with_inline_comments_like_compose() {
    mkdir -p "$DIR"
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw  # db\nAPP_PORT=8124 # forwarded port\nAPP_URL=\nMEMRY_IMAGE="img:1" # pinned\n' > "${DIR}/.env"
    STUB_PULL_STATUS=1 STUB_IMAGE_STATUS=1
    run_install --email you@example.com --dir "$DIR" --no-cli
    [ "$(env_value APP_URL)" = "http://localhost:8124" ] || fail "APP_URL is $(env_value APP_URL)"
    assert_output_contains "could not pull img:1."
}

test_uses_the_port_compose_published() {
    mkdir -p "$DIR"
    # shellcheck disable=SC2016 # a literal Compose interpolation
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw\nAPP_PORT=${CUSTOM_PORT:-9100}\nAPP_URL=http://localhost:9100\nMEMRY_IMAGE=img:1\n' > "${DIR}/.env"
    STUB_PUBLISHED_PORT=9100
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "docker compose port app 8000"
    assert_log_contains "http://localhost:9100/up"
    assert_output_contains "memry setup --url http://localhost:9100 --token"
}

test_falls_back_to_the_local_image_compose_resolved() {
    mkdir -p "$DIR"
    # shellcheck disable=SC2016 # a literal Compose interpolation
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw\nAPP_PORT=8000\nMEMRY_IMAGE=${REGISTRY:-ghcr.io/acme}/memry-server:1\n' > "${DIR}/.env"
    STUB_PULL_STATUS=1 STUB_APP_IMAGE=ghcr.io/acme/memry-server:1 STUB_LOCAL_IMAGE=ghcr.io/acme/memry-server:1
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "docker image inspect ghcr.io/acme/memry-server:1"
    assert_output_contains "using the local copy"
}

test_keeps_path_when_the_env_file_defines_it() {
    mkdir -p "$DIR"
    printf 'APP_KEY=base64:abc\nDB_PASSWORD=pw\nAPP_PORT=8000\nMEMRY_IMAGE=img:1\nPATH=/nowhere\nHOME=/nowhere\n' > "${DIR}/.env"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "docker compose run --rm migrate"
}

test_keeps_values_assigned_with_spaces_or_a_colon() {
    mkdir -p "$DIR"
    printf 'APP_KEY: base64:abc\nDB_PASSWORD = old-password\nAPP_PORT=8000\nMEMRY_IMAGE=img:1\n' > "${DIR}/.env"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    [ "$(grep -c 'DB_PASSWORD' "${DIR}/.env")" = 1 ] || fail "DB_PASSWORD was added again: $(grep DB_PASSWORD "${DIR}/.env" | tr '\n' '|')"
    [ "$(grep -c 'APP_KEY' "${DIR}/.env")" = 1 ] || fail "APP_KEY was added again"
    grep -qx 'DB_PASSWORD = old-password' "${DIR}/.env" || fail "the existing DB_PASSWORD line changed"
}

test_quotes_the_directory_in_recovery_commands() {
    DIR="${T}/Memry Community"
    STUB_UP_STATUS=000 MEMRY_UP_TIMEOUT_OVERRIDE=4
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 1
    assert_output_contains "cd '${DIR}' && docker compose logs app"
}

test_quotes_the_email_in_the_token_recovery_command() {
    STUB_NO_TOKEN=1
    run_install --email "o'reilly@example.com" --dir "$DIR"
    assert_status 1
    assert_output_contains "docker compose run --rm app token 'o'\\''reilly@example.com'"
}

test_the_local_readiness_probe_ignores_proxy_variables() {
    AMBIENT_ENV="http_proxy=http://proxy:3128 ALL_PROXY=http://proxy:3128"
    run_install --email you@example.com --dir "$DIR" --no-cli
    assert_status 0
    assert_log_contains "--noproxy * "
    assert_log_contains "http://localhost:8000/up"
}

test_doctor_reports_when_there_are_no_memry_volumes() {
    run_install --doctor
    assert_status 0
    assert_output_contains "No memry database volumes found."
    assert_log_not_contains "compose up"
    assert_log_not_contains "docker pull"
    assert_log_not_contains "compose run"
}

test_doctor_lists_a_volume_whose_project_runs_a_memry_image_as_a_memry_install() {
    mkdir -p "${T}/work"
    STUB_VOLUMES="memry-abc_postgres-data" STUB_CONTAINERS="memry-abc=${T}/work"
    run_install --doctor
    assert_status 0
    assert_output_contains "memry-abc_postgres-data"
    assert_output_contains "project: memry-abc"
    assert_output_contains "created: 2025-01-02T03:04:05Z"
    assert_output_contains "status: memry install at ${T}/work"
    assert_output_not_contains "no longer exists"
    assert_log_not_contains "volume rm"
}

test_doctor_lists_a_volume_without_containers_as_stopped_or_unconfirmed_and_never_prints_rm() {
    STUB_VOLUMES="old-1234_postgres-data"
    run_install --doctor
    assert_status 0
    assert_output_contains "old-1234_postgres-data"
    assert_output_contains "status: stopped or unconfirmed (no containers)"
    assert_output_contains "docker compose down"
    assert_output_not_contains "memry install at"
    assert_output_not_contains "docker volume rm"
    assert_output_not_contains "orphaned"
}

test_doctor_marks_a_volume_whose_containers_use_other_images_as_not_memry() {
    STUB_VOLUMES="shop_postgres-data" STUB_CONTAINERS="shop=${T}/shop"
    STUB_CONTAINER_IMAGES="shop=postgres:16-alpine"
    run_install --doctor
    assert_status 0
    assert_output_contains "status: not memry (containers use other images)"
    assert_output_not_contains "memry install at"
    assert_output_not_contains "docker volume rm"
}

test_doctor_says_when_the_directory_of_a_memry_install_no_longer_exists() {
    STUB_VOLUMES="gone-9_postgres-data" STUB_CONTAINERS="gone-9=${T}/deleted"
    run_install --doctor
    assert_status 0
    assert_output_contains "status: memry install at ${T}/deleted (the directory no longer exists)"
}

test_doctor_lists_the_containers_of_each_project_and_ends_with_the_dir_hint() {
    STUB_VOLUMES="shop_postgres-data" STUB_CONTAINERS="shop=${T}/shop"
    STUB_CONTAINER_IMAGES="shop=postgres:16-alpine"
    run_install --doctor
    assert_status 0
    assert_output_contains "container: postgres:16-alpine in ${T}/shop"
    assert_output_contains "To check an install directory, run the doctor with --dir <directory>."
}

test_doctor_fails_when_docker_volume_ls_fails() {
    STUB_VOLUME_LS_STATUS=1 STUB_VOLUMES="memry-abc_postgres-data"
    run_install --doctor
    assert_status 1
    assert_output_contains "could not list the Docker volumes"
    assert_output_not_contains "No memry database volumes found."
}

test_doctor_fails_when_docker_ps_fails() {
    STUB_PS_STATUS=1 STUB_VOLUMES="memry-abc_postgres-data"
    run_install --doctor
    assert_status 1
    assert_output_contains "could not list the containers of the project memry-abc"
}

# An install directory whose .env names the Compose project.
make_install_dir() {
    mkdir -p "$DIR"
    printf 'COMPOSE_PROJECT_NAME=%s\nDB_PASSWORD=secret\n' "$1" > "${DIR}/.env"
}

test_doctor_dir_reports_a_stopped_install_with_its_data_intact_and_how_to_start_it() {
    make_install_dir myproj-1
    STUB_VOLUMES="myproj-1_postgres-data other_postgres-data"
    run_install --doctor --dir "$DIR"
    assert_status 0
    assert_output_contains ".env: present"
    assert_output_contains "myproj-1_postgres-data"
    assert_output_not_contains "other_postgres-data"
    assert_output_contains "Stopped: the data is intact."
    assert_output_contains "cd ${DIR} && docker compose up -d"
    assert_output_not_contains "docker volume rm"
    assert_log_not_contains "volume rm"
}

test_doctor_dir_with_a_missing_env_and_the_hashed_volume_offers_restore_or_rm_with_a_warning() {
    mkdir -p "$DIR"
    hashed="$(cd "$DIR" && pwd)"
    base="$(basename "$hashed" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9\n' '-' | tr -s '-' | sed -e 's/^-*//' -e 's/-*$//')"
    project="${base}-$(printf '%s' "$hashed" | cksum | cut -d ' ' -f 1)"
    STUB_VOLUMES="${project}_postgres-data other_postgres-data"
    run_install --doctor --dir "$DIR"
    assert_status 0
    assert_output_contains ".env: missing"
    assert_output_contains "volume: ${project}_postgres-data"
    assert_output_contains "Restore that .env"
    assert_output_contains "docker volume rm ${project}_postgres-data"
    assert_output_contains "removes every memory in it and cannot be undone"
    assert_output_not_contains "other_postgres-data"
    assert_output_not_contains "docker compose up -d"
    assert_log_not_contains "volume rm"
}

test_doctor_dir_finds_a_legacy_volume_named_after_the_default_directory_project() {
    mkdir -p "$DIR"
    STUB_VOLUMES="memry_postgres-data"
    run_install --doctor --dir "$DIR"
    assert_status 0
    assert_output_contains ".env: missing"
    assert_output_contains "volume: memry_postgres-data"
    assert_output_contains "docker volume rm memry_postgres-data"
    assert_log_not_contains "volume rm"
}

test_doctor_dir_says_when_this_install_has_no_volume() {
    STUB_VOLUMES="other_postgres-data"
    run_install --doctor --dir "$DIR"
    assert_status 0
    assert_output_contains "No database volume of this install exists"
    assert_output_not_contains "docker volume rm"
    assert_output_not_contains "other_postgres-data"
    assert_log_not_contains "volume rm"
    [ ! -e "$DIR" ] || fail "the doctor created the directory"
}

test_doctor_dir_reports_a_healthy_install() {
    make_install_dir myproj-1
    STUB_VOLUMES="myproj-1_postgres-data" STUB_CONTAINERS="myproj-1=${DIR}"
    run_install --doctor --dir "$DIR"
    assert_status 0
    assert_output_contains "Healthy"
    assert_output_not_contains "docker volume rm"
    assert_log_not_contains "volume rm"
}

test_doctor_dir_still_refuses_the_other_install_options() {
    run_install --doctor --dir "$DIR" --port 9000
    assert_status 2
    assert_output_contains "--doctor cannot be combined with --port"
}

test_doctor_never_removes_a_volume() {
    STUB_VOLUMES="old-1234_postgres-data gone-9_postgres-data" STUB_CONTAINERS="gone-9=${T}/deleted"
    run_install --doctor
    assert_status 0
    assert_log_not_contains "volume rm"
    assert_log_not_contains "compose down"
}

test_doctor_cannot_be_combined_with_install_options() {
    run_install --doctor --email you@example.com
    assert_status 2
    assert_output_contains "--doctor cannot be combined with --email"
    assert_log_not_contains "docker"
}

test_help_documents_doctor() {
    run_install --help
    assert_status 0
    assert_output_contains "--doctor"
}

test_doctor_needs_neither_curl_nor_an_email() {
    rm -f "${STUBS}/curl"
    run_install --doctor
    assert_status 0
}

test_doctor_derives_the_project_of_an_unlabeled_volume_from_its_name() {
    mkdir -p "${T}/work"
    STUB_VOLUMES="legacy_postgres-data" STUB_UNLABELED_VOLUMES="legacy_postgres-data" STUB_CONTAINERS="=${T}/work"
    run_install --doctor
    assert_status 0
    assert_output_contains "project: legacy"
    assert_output_contains "status: stopped or unconfirmed (no containers)"
    assert_output_not_contains "memry install at"
    assert_log_not_contains "com.docker.compose.project= "
}

# --- run --------------------------------------------------------------------

# shellcheck disable=SC2013 # one test name per line
for t in $(sed -n 's/^\(test_[a-z0-9_]*\)() {$/\1/p' "$0"); do
    run_test "$t"
done

echo
echo "${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
