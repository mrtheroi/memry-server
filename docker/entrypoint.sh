#!/bin/sh
# memry Community container entrypoint.
#
#   serve            cache config/routes/views, then start FrankenPHP (default)
#   migrate          run database migrations (safe to run from several containers)
#   scheduler        run the Laravel scheduler in the foreground
#   token <email>    create the user if needed and print a new API token
#   key              print a fresh APP_KEY (no configuration required)
#   php|sh|bash|...  executed as-is
#   anything else    passed to `php artisan`
set -eu

cd /app

fail() {
    echo "memry: $*" >&2
    exit 1
}

require_configuration() {
    [ -n "${APP_KEY:-}" ] || fail "APP_KEY is not set. Generate one with: docker run --rm <image> key"

    if [ "${DB_CONNECTION:-}" != "pgsql" ]; then
        fail "DB_CONNECTION must be 'pgsql' (got '${DB_CONNECTION:-}'). memry Community requires PostgreSQL 14+."
    fi
}

migrate() {
    # --isolated takes its lock in the database cache store, whose cache_locks
    # table does not exist on a fresh database: create it first (no-op later).
    php artisan migrate --force --path=database/migrations/0001_01_01_000001_create_cache_table.php
    php artisan migrate --force --isolated
}

# Self-healing: an older app still running during an upgrade can write rows
# without a team after the migration. Idempotent; exits 0 when nothing is left.
backfill_teams() {
    php artisan memory:backfill-teams --no-interaction
}

is_true() {
    case "$(echo "${1:-}" | tr '[:upper:]' '[:lower:]')" in
        1 | true | yes | on) return 0 ;;
        *) return 1 ;;
    esac
}

command="${1:-serve}"
[ $# -gt 0 ] && shift

case "$command" in
    key)
        exec php artisan key:generate --show --no-interaction
        ;;
    serve)
        require_configuration
        if is_true "${AUTO_MIGRATE:-false}"; then
            migrate
        fi
        backfill_teams
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        exec frankenphp php-server --listen ":${SERVER_PORT:-8000}" --root /app/public
        ;;
    migrate)
        require_configuration
        migrate
        ;;
    scheduler)
        require_configuration
        exec php artisan schedule:work
        ;;
    token)
        require_configuration
        [ $# -eq 1 ] || fail "usage: token <email>"
        exec php artisan memory:token "$1" --create --no-interaction
        ;;
    php | sh | bash | frankenphp)
        exec "$command" "$@"
        ;;
    *)
        require_configuration
        exec php artisan "$command" "$@"
        ;;
esac
