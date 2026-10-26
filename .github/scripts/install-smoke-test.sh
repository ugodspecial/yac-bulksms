#!/usr/bin/env bash
#
# End-to-end check of the browser installer against a *real* HTTP server that
# starts with no .env file, no application key and no database.
#
# Usage: BASE=http://127.0.0.1:8080 bash .github/scripts/install-smoke-test.sh
set -uo pipefail

BASE=${BASE:-http://127.0.0.1:8080}
JAR=$(mktemp)
BODY=$(mktemp)
DB_PATH="$(pwd)/database/installer-smoke.sqlite"
STATUS=0

cleanup() {
    rm -f "$JAR" "$BODY"
}
trap cleanup EXIT

pass() { printf '  \033[32mPASS\033[0m %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$*"; STATUS=1; }

status() { # method path [curl args...]
    local method=$1 path=$2
    shift 2
    curl -s -o "$BODY" -w '%{http_code}' -X "$method" -c "$JAR" -b "$JAR" "$@" "$BASE$path"
}

location() { # path
    curl -s -o /dev/null -w '%{redirect_url}' -c "$JAR" -b "$JAR" "$BASE$1"
}

db_count() { # "<sql>" -> number (-1 on failure)
    php .github/scripts/db-count.php "$DB_PATH" "$1" 2>/dev/null || echo -1
}

csrf_token() { # path -> token, retrying while the dev server restarts
    local path=$1 attempt=0 html=""

    while [ $attempt -lt 30 ]; do
        html=$(curl -s -c "$JAR" -b "$JAR" "$BASE$path" || true)
        token=$(printf '%s' "$html" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed -e 's/.*value="//' -e 's/"$//')

        if [ -n "$token" ]; then
            printf '%s' "$token"
            return 0
        fi

        attempt=$((attempt + 1))
        sleep 1
    done

    return 1
}

echo "Installer smoke test against $BASE"

# ---------------------------------------------------------------- before install
loc=$(location /)
if [ "${loc%/}" = "$BASE/install" ]; then
    pass "GET / redirects to the installer"
else
    fail "GET / redirected to '$loc' instead of $BASE/install"
fi

code=$(status GET /install)
if [ "$code" = "200" ] && grep -qi 'Server requirements' "$BODY"; then
    pass "GET /install renders the requirements screen without a database"
else
    fail "GET /install returned $code"
fi

code=$(status GET /login)
if [ "${code}" = "302" ]; then
    pass "GET /login redirects to the installer before installation"
else
    fail "GET /login returned $code (expected a redirect)"
fi

# ------------------------------------------------------------------- step 2: db
TOKEN=$(csrf_token /install/database) || fail "could not read the CSRF token from /install/database"

code=$(status POST /install/database \
    --data-urlencode "_token=$TOKEN" \
    --data-urlencode "db_driver=sqlite" \
    --data-urlencode "db_sqlite_path=$DB_PATH")

if [ "$code" = "302" ]; then
    pass "the database step accepted the SQLite settings"
else
    fail "the database step returned $code"
fi

if [ -f "$DB_PATH" ]; then
    pass "the SQLite database file was created"
else
    fail "the SQLite database file was not created"
fi

if grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    pass ".env contains a generated APP_KEY"
else
    fail ".env does not contain a generated APP_KEY"
fi

if grep -q '^DB_CONNECTION=sqlite$' .env 2>/dev/null && grep -q '^SESSION_DRIVER=database$' .env 2>/dev/null; then
    pass ".env contains the database, session and cache settings"
else
    fail ".env is missing the connection or session settings"
fi

# ------------------------------------------------------------ step 3: app + admin
TOKEN=$(csrf_token /install/application) || fail "could not read the CSRF token from /install/application"

code=$(status POST /install/application \
    --data-urlencode "_token=$TOKEN" \
    --data-urlencode "app_name=YAC Smoke" \
    --data-urlencode "app_url=$BASE" \
    --data-urlencode "timezone=Africa/Lagos" \
    --data-urlencode "environment=production" \
    --data-urlencode "admin_name=Smoke Admin" \
    --data-urlencode "admin_email=smoke@example.test" \
    --data-urlencode "admin_password=SmokeTest12345" \
    --data-urlencode "admin_password_confirmation=SmokeTest12345")

if [ "$code" = "302" ]; then
    pass "the application step accepted the settings"
else
    fail "the application step returned $code"
fi

if [ -f storage/app/installation.json ]; then
    pass "the installation marker was written"
else
    fail "the installation marker was not written"
fi

admins=$(db_count "select count(*) from users where role = 'admin' and is_active = 1")
if [ "$admins" = "1" ]; then
    pass "the administrator account exists in the migrated database"
else
    fail "the administrator account is missing (found '$admins')"
fi

tables=$(db_count "select count(*) from sqlite_master where type = 'table' and name in ('users','campaigns','campaign_recipients','settings','sessions','jobs')")
if [ "$tables" = "6" ]; then
    pass "the migrations created the application tables"
else
    fail "expected 6 core tables, found '$tables'"
fi

# --------------------------------------------------------------- after install
loc=$(location /install)
if [ "$loc" = "$BASE/dashboard" ]; then
    pass "the installer closes itself once installed"
else
    fail "GET /install redirected to '$loc' after installation"
fi

code=$(status GET /login)
if [ "$code" = "200" ]; then
    pass "the sign-in page renders with the installed configuration"
else
    fail "GET /login returned $code after installation"
fi

sessions=$(db_count "select count(*) from sessions")
if [ "$sessions" -ge 1 ] 2>/dev/null; then
    pass "sessions are stored in the database after installation"
else
    fail "no session rows were written after installation (found '$sessions')"
fi

echo
if [ "$STATUS" = "0" ]; then
    echo "All installer checks passed."
else
    echo "The installer smoke test failed."
fi

exit "$STATUS"
