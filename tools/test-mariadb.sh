#!/usr/bin/env sh

set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
COMPOSE_FILE="$ROOT_DIR/docker-compose.test.yml"

cleanup() {
    docker compose --file "$COMPOSE_FILE" down --volumes >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

docker compose --file "$COMPOSE_FILE" up --detach --wait

export SEVDESK_TEST_DB_HOST=127.0.0.1
export SEVDESK_TEST_DB_PORT=33079
export SEVDESK_TEST_DB_DATABASE=sevdesk_test
export SEVDESK_TEST_DB_USERNAME=sevdesk
export SEVDESK_TEST_DB_PASSWORD=sevdesk_test
export SEVDESK_TEST_DB_REQUIRED=1

"$ROOT_DIR/vendor/bin/phpunit" --configuration "$ROOT_DIR/phpunit.integration.xml.dist"
