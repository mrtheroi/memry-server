#!/usr/bin/env bash
# End-to-end test for install.sh against a real Docker daemon.
#
# Runs install.sh (with --no-cli) twice in a temporary directory, reading
# docker-compose.yml and the example .env from this checkout. After each run it
# checks that /up answers 200 and that the printed token lists the six memory
# tools at /mcp/memory; the second run must keep APP_KEY and DB_PASSWORD.
# Everything is torn down (including volumes) on exit unless KEEP=1.
#
#   MEMRY_IMAGE=memry-server:ci docker/install-e2e.sh          # image built in CI
#   MEMRY_IMAGE=ghcr.io/mrtheroi/memry-server:0.17.0 docker/install-e2e.sh
#
# Requires: docker (with compose), curl, jq.
set -euo pipefail

cd "$(dirname "$0")/.."
root="$(pwd)"

: "${MEMRY_IMAGE:?Set MEMRY_IMAGE to the image to test}"
PORT="${E2E_PORT:-18001}"
BASE_URL="http://127.0.0.1:${PORT}"
EXPECTED_TOOLS="get-context get-memory save-memory save-prompt search-memory session-summary"

work_dir="$(mktemp -d)"
# The directory name is the Compose project name.
install_dir="${work_dir}/memry-install-e2e"

step() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nINSTALL E2E FAILED: %s\n' "$*" >&2; exit 1; }

cleanup() {
    status=$?
    if [ -f "${install_dir}/.env" ]; then
        if [ "$status" -ne 0 ]; then
            (cd "$install_dir" && docker compose logs --no-color --tail 50 app migrate 2>/dev/null) || true
        fi
        if [ "${KEEP:-0}" != "1" ]; then
            (cd "$install_dir" && docker compose down -v --remove-orphans >/dev/null 2>&1) || true
        fi
    fi
    rm -rf "$work_dir"
    exit "$status"
}
trap cleanup EXIT

env_value() {
    sed -n "s/^$1=//p" "${install_dir}/.env" | tail -n 1
}

# Runs install.sh and sets $token from its output. The output is shown with
# the token redacted.
install() {
    local out="${work_dir}/install.out"
    if ! MEMRY_SOURCE_DIR="$root" MEMRY_IMAGE="$MEMRY_IMAGE" \
        sh install.sh --email e2e@example.com --dir "$install_dir" --port "$PORT" --no-cli > "$out" 2>&1; then
        cat "$out"
        fail "install.sh exited with an error"
    fi
    token="$(awk '/^Your memry token/ { getline; getline; sub(/^ +/, ""); print; exit }' "$out")"
    [ -n "$token" ] || { cat "$out"; fail "install.sh printed no token"; }
    sed "s#${token}#<token>#g" "$out"
}

check_server() {
    step "Checking ${BASE_URL}/up"
    status="$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/up")"
    [ "$status" = "200" ] || fail "/up returned ${status}"
    echo "/up returned 200"

    step "Listing the MCP tools with the token"
    local headers="${work_dir}/headers" session_id
    mcp() {
        curl -sS -D "$headers" -X POST "${BASE_URL}/mcp/memory" \
            -H "Authorization: Bearer ${token}" \
            -H 'Content-Type: application/json' \
            -H 'Accept: application/json, text/event-stream' \
            ${session_id:+-H "Mcp-Session-Id: ${session_id}"} \
            -d "$1"
    }
    session_id=""
    init="$(mcp '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"memry-install-e2e","version":"1.0"}}}')"
    [ -n "$(jq -r '.result.serverInfo.name // empty' <<<"$init")" ] || fail "initialize failed: ${init}"
    session_id="$(grep -i '^mcp-session-id:' "$headers" | awk '{print $2}' | tr -d '\r' || true)"
    mcp '{"jsonrpc":"2.0","method":"notifications/initialized"}' >/dev/null
    tools="$(mcp '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | jq -r '.result.tools[].name' | sort | xargs)"
    echo "tools: ${tools}"
    [ "$tools" = "$EXPECTED_TOOLS" ] || fail "expected tools '${EXPECTED_TOOLS}', got '${tools}'"
}

step "First run of install.sh (${MEMRY_IMAGE})"
install
[ "$(stat -c %a "${install_dir}/.env" 2>/dev/null || stat -f %Lp "${install_dir}/.env")" = "600" ] \
    || fail ".env is not mode 600"
app_key="$(env_value APP_KEY)"
db_password="$(env_value DB_PASSWORD)"
[[ "$app_key" == base64:* ]] || fail "APP_KEY was not generated"
check_server

step "Second run of install.sh (idempotency)"
install
[ "$(env_value APP_KEY)" = "$app_key" ] || fail "APP_KEY changed on the second run"
[ "$(env_value DB_PASSWORD)" = "$db_password" ] || fail "DB_PASSWORD changed on the second run"
echo "APP_KEY and DB_PASSWORD kept"
check_server

printf '\nINSTALL E2E PASSED\n'
