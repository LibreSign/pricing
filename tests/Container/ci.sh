#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

cd "$(dirname "$0")/../.."
secret_dir="$(mktemp -d)"
umask 077
openssl rand -hex 32 > "$secret_dir/app-secret"
openssl rand -hex 32 > "$secret_dir/api-token"
PRICING_SMOKE_TOKEN="$(cat "$secret_dir/api-token")"
export PRICING_SMOKE_TOKEN

export PRICING_APP_SECRET_FILE="$secret_dir/app-secret"
export PRICING_API_TOKEN_FILE="$secret_dir/api-token"
export PRICING_CATALOG_FILE="$PWD/tests/Fixtures/synthetic-v1.json"
export PRICING_APP_IMAGE="pricing-app:ci"
export PRICING_WEB_IMAGE="pricing-web:ci"
export PRICING_COMPOSE_PROJECT="pricing-ci-${GITHUB_RUN_ID:-local}-$$"
export PRICING_HTTP_PORT="${PRICING_HTTP_PORT:-18080}"

cleanup() {
    docker compose -f docker-compose.yml -f docker-compose.build.yml down --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$secret_dir"
}
trap cleanup EXIT HUP INT TERM

docker compose -f docker-compose.yml -f docker-compose.build.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.build.yml build --pull

# Reuse the freshly built and pinned PHP image instead of a separate image
# that Dependabot cannot discover inside a shell script.
# The helper is disposable, runs only against synthetic CI secrets, and
# overrides the normal non-root user solely to set restrictive ownership.
docker run --rm --user 0:0 --entrypoint /bin/sh \
    -v "$secret_dir:/secrets" "$PRICING_APP_IMAGE" \
    -c 'chown 10001:10001 /secrets/* && chmod 0400 /secrets/*' >/dev/null

docker compose -f docker-compose.yml -f docker-compose.build.yml up -d --no-build

web_id="$(docker compose -f docker-compose.yml -f docker-compose.build.yml ps -q nginx)"
app_id="$(docker compose -f docker-compose.yml -f docker-compose.build.yml ps -q app)"

# A non-root PHP process and loopback-only HTTP exposure are hard requirements.
[ "$(docker inspect "$app_id" --format '{{.Config.User}}')" = '10001:10001' ]
[ "$(docker inspect "$web_id" --format '{{(index (index .NetworkSettings.Ports "8080/tcp") 0).HostIp}}')" = '127.0.0.1' ]
[ "$(docker network inspect "${PRICING_COMPOSE_PROJECT}_backend" --format '{{.Internal}}')" = true ]
[ "$(docker inspect "$app_id" --format '{{len .NetworkSettings.Networks}}')" -eq 1 ]
[ -z "$(docker port "$app_id" 9000/tcp 2>/dev/null)" ]

sh tests/Container/smoke.sh
