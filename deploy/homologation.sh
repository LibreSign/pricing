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
if [ "$(grep -c '^PRICING_APP_IMAGE=' "$envfile")" -ne 1 ] ||
    [ "$(grep -c '^PRICING_WEB_IMAGE=' "$envfile")" -ne 1 ]; then
    echo "Both pinned image entries must occur exactly once in private deployment configuration" >&2
    exit 1
fi

# SHA-scoped images; never deploy a mutable latest tag.
export PRICING_APP_IMAGE="ghcr.io/libresign/pricing-app:sha-$sha"
export PRICING_WEB_IMAGE="ghcr.io/libresign/pricing-web:sha-$sha"

docker compose --env-file "$envfile" config --quiet
resolved="$(docker compose --env-file "$envfile" config --format json)"

# Refuse to deploy when a host-only override is missing or ineffective.
# Docker Compose serializes published service ports under a "ports" key.
if printf '%s\n' "$resolved" | grep -Eq '"ports"[[:space:]]*:'; then
    echo "Host-published ports are forbidden in private homologation" >&2
    exit 1
fi

docker compose --env-file "$envfile" pull app nginx
docker compose --env-file "$envfile" up -d --no-build --remove-orphans

# Probe Nginx from inside its container; no host port or public DNS is needed.
i=0
while [ "$i" -lt 30 ]; do
    if docker compose --env-file "$envfile" exec -T nginx wget -qO- http://127.0.0.1:8080/healthz |
        grep -q '"status":"ok"'; then
        # Only persist an approved image revision after a successful health check.
        # Use an atomic rename so a later docker compose invocation selects the
        # same running revision instead of silently rolling back.
        next_env="$(mktemp "$envfile.next.XXXXXXXX")"
        trap 'rm -f "$next_env"' 0
        awk -v app="$PRICING_APP_IMAGE" -v web="$PRICING_WEB_IMAGE" '
            /^PRICING_APP_IMAGE=/ { print "PRICING_APP_IMAGE=" app; next }
            /^PRICING_WEB_IMAGE=/ { print "PRICING_WEB_IMAGE=" web; next }
            { print }
        ' "$envfile" > "$next_env"
        chmod 0600 "$next_env"
        cp -p "$envfile" "$envfile.previous"
        mv "$next_env" "$envfile"
        trap - 0
        printf 'Homologation liveness verified for %s\n' "$sha"
        exit 0
    fi
    i=$((i + 1))
    if [ "$i" -lt 30 ]; then
        sleep 2
    fi
done

echo "Health verification failed; review service logs and manually roll back to the prior approved SHA." >&2
exit 1
