#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

: "${PRICING_HTTP_PORT:?Set loopback test port}"
: "${PRICING_SMOKE_TOKEN:?Provide a temporary synthetic test token}"
url="http://127.0.0.1:${PRICING_HTTP_PORT}"
token="$PRICING_SMOKE_TOKEN"
payload='{"model":"cloud","parameter_version":"synthetic-v1","usage":{"documents":100,"storage_gb":10,"service_hours":2}}'
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT HUP INT TERM

attempt=0
until curl -sS --max-time 3 -o "$tmp" "$url/healthz" 2>/dev/null && grep -q '"status":"ok"' "$tmp"; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Pricing healthcheck failed" >&2
        exit 1
    fi
    sleep 2
done

status="$(curl -sS -o "$tmp" -w '%{http_code}' -X POST "$url/v1/simulations" \
    -H 'Content-Type: application/json' --data "$payload")"
[ "$status" = 401 ] || { echo "Unauthenticated request was not rejected ($status)" >&2; exit 1; }

status="$(curl -sS -o "$tmp" -w '%{http_code}' -X POST "$url/v1/simulations" \
    -H "Authorization: Bearer $token" -H 'Content-Type: application/json' --data "$payload")"
[ "$status" = 200 ] || { echo "Authenticated synthetic request failed ($status)" >&2; exit 1; }
grep -q '"quote_allowed":false' "$tmp" || { echo "Commercial guardrail missing" >&2; exit 1; }
grep -q '"activation_allowed":false' "$tmp" || { echo "Activation guardrail missing" >&2; exit 1; }

status="$(curl -sS -o /dev/null -w '%{http_code}' "$url/composer.json")"
[ "$status" = 404 ] || { echo "Source path should not be available ($status)" >&2; exit 1; }

limited=false
attempt=0
while [ "$attempt" -lt 20 ]; do
    status="$(curl -sS --max-time 4 -o "$tmp" -w '%{http_code}' -X POST "$url/v1/simulations" \
        -H "Authorization: Bearer $token" -H 'Content-Type: application/json' --data "$payload")"
    if [ "$status" = 429 ]; then
        grep -q '"error":"rate_limited"' "$tmp" || { echo "Unexpected 429 response" >&2; exit 1; }
        limited=true
        break
    fi
    attempt=$((attempt + 1))
done

[ "$limited" = true ] || { echo "Rapid request flood did not encounter 429" >&2; exit 1; }
echo "Container smoke passed: health, authorization, synthetic pricing guard, file isolation and HTTP 429."
