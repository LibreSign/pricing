#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

sha="${1:-}"
case "$sha" in
    ''|*[!0-9a-f]*)
        echo "Provide an approved 40-character lowercase commit SHA" >&2
        exit 2
        ;;
esac
[ "${#sha}" -eq 40 ] || { echo "Invalid SHA length" >&2; exit 2; }

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
envfile="$root/.env.homologation"
[ -f "$envfile" ] || { echo "Missing host-local deployment configuration" >&2; exit 1; }
[ -r "$envfile" ] || { echo "Deployment configuration is not readable" >&2; exit 1; }

# SHA-scoped immutable names: never deploy a moving latest tag.
export PRICING_APP_IMAGE="ghcr.io/libresign/pricing-app:sha-$sha"
export PRICING_WEB_IMAGE="ghcr.io/libresign/pricing-web:sha-$sha"

docker compose --env-file "$envfile" config --quiet
docker compose --env-file "$envfile" pull app nginx
docker compose --env-file "$envfile" up -d --no-build --remove-orphans

# Only the host's loopback port is exposed, never a public pricing endpoint.
port="$(sed -n 's/^PRICING_HTTP_PORT=//p' "$envfile" | tail -n 1)"
case "$port" in
    ''|*[!0-9]*)
        echo "PRICING_HTTP_PORT must be a numeric local port" >&2
        exit 1
        ;;
esac

i=0
while [ "$i" -lt 30 ]; do
    if curl -fsS --max-time 3 "http://127.0.0.1:$port/healthz" | grep -q '"status":"ok"'; then
        printf 'Homologation liveness verified for %s\n' "$sha"
        exit 0
    fi
    sleep 2
    i=$((i + 1))
done

echo "Health verification failed; review service logs and manually roll back to the prior approved SHA." >&2
exit 1
