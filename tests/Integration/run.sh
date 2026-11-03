#!/bin/sh
# Builds the plugin archive, imports it into a real panel, and runs the checks against it. Needs Docker.
set -eu

cd "$(dirname "$0")"
root="$(cd ../.. && pwd)"

INTEGRATION_DB_PASSWORD="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
export INTEGRATION_DB_PASSWORD

compose() {
    docker compose -f compose.yml "$@"
}

cleanup() {
    compose down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "Starting the panel and MariaDB..."
compose up -d --wait --wait-timeout 300

echo "Importing the built archive..."
tar -C "$root" --exclude=./vendor --exclude=./dist --exclude=./.git --exclude=./docker -cf - . |
    compose exec -T panel sh -c 'mkdir -p /tmp/src && tar -xf - -C /tmp/src'
compose exec -T panel php /tmp/src/scripts/build-zip.php
compose exec -T panel php artisan db:seed --force >/dev/null
compose exec -T panel php /tmp/src/tests/Integration/import.php
compose exec -T panel php artisan p:plugin:install pelican-provisioner

echo "Starting the stand-in for Wings and seeding..."
compose exec -d panel php -S 0.0.0.0:8080 /tmp/src/tests/Integration/mock-wings.php
compose exec -T panel php /tmp/src/tests/Integration/seed.php

compose exec -T panel php /tmp/src/tests/Integration/checks.php

echo
echo "Parallel creates by one user, of which only one fits the quota..."
for attempt in 1 2 3; do
    start="$(compose exec -T panel php -r 'echo (int) (microtime(true) * 1000) + 4000;')"
    pids=""
    for process in 1 2 3 4 5 6 7 8; do
        compose exec -T panel php /tmp/src/tests/Integration/race.php "$start" "$process" &
        pids="$pids $!"
    done
    for pid in $pids; do
        wait "$pid"
    done
    compose exec -T panel php /tmp/src/tests/Integration/race_report.php "$attempt"
done

if [ -f "$root/vendor/phpstan/phpstan/phpstan.phar" ]; then
    echo
    echo "Static analysis of all of src against the real panel classes..."
    compose cp "$root/vendor/phpstan/phpstan/phpstan.phar" panel:/tmp/phpstan.phar
    compose exec -T panel php -d memory_limit=1500M /tmp/phpstan.phar analyse --no-progress --no-ansi -c /tmp/src/tests/Integration/phpstan.neon
fi

echo
echo "Integration suite passed."
