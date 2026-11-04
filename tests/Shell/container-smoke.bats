#!/usr/bin/env bats
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

setup() {
    SOURCE="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
    WORK="$(mktemp -d)"
    mkdir -p "$WORK/bin"
    export FAKE_AUTH_COUNT="$WORK/auth.count"
    export FAKE_HEALTH_COUNT="$WORK/health.count"
    export FAKE_HEALTH_DOWN=no
    export FAKE_UNAUTH_OK=no
    export FAKE_AUTH_REJECT=no
    export FAKE_BAD_GUARD=no
    export FAKE_SOURCE_EXPOSED=no
    export FAKE_NO_RATE_LIMIT=no
    printf '0\n' > "$FAKE_AUTH_COUNT"
    printf '0\n' > "$FAKE_HEALTH_COUNT"
    export PRICING_HTTP_PORT=18082
    export PRICING_SMOKE_TOKEN=synthetic-test-only

    cat > "$WORK/bin/curl" <<'MOCK'
#!/bin/sh
outfile=''
want_status=no
authorized=no
url=''
while [ "$#" -gt 0 ]; do
    case "$1" in
        -o) outfile="$2"; shift 2 ;;
        -w) want_status=yes; shift 2 ;;
        -H)
            case "$2" in
                'Authorization: Bearer '*) authorized=yes ;;
            esac
            shift 2
            ;;
        -X|--data|--max-time) shift 2 ;;
        http://*) url="$1"; shift ;;
        *) shift ;;
    esac
done

case "$url" in
    */healthz)
        n="$(cat "$FAKE_HEALTH_COUNT")"; n=$((n + 1))
        printf '%s\n' "$n" > "$FAKE_HEALTH_COUNT"
        [ "$FAKE_HEALTH_DOWN" = yes ] && exit 7
        status=200
        body='{"status":"ok"}'
        ;;
    */v1/simulations)
        status=401
        body='{"error":"unauthorized"}'
        if [ "$authorized" = yes ]; then
            n="$(cat "$FAKE_AUTH_COUNT")"; n=$((n + 1))
            printf '%s\n' "$n" > "$FAKE_AUTH_COUNT"
            status=200
            body='{"quote_allowed":false,"activation_allowed":false}'
            if [ "$FAKE_BAD_GUARD" = yes ]; then
                body='{"quote_allowed":true,"activation_allowed":false}'
            fi
            if [ "$FAKE_AUTH_REJECT" = yes ]; then
                status=401
            elif [ "$FAKE_NO_RATE_LIMIT" != yes ] && [ "$n" -gt 1 ]; then
                status=429
                body='{"error":"rate_limited"}'
            fi
        elif [ "$FAKE_UNAUTH_OK" = yes ]; then
            status=200
        fi
        ;;
    */composer.json)
        status=404
        if [ "$FAKE_SOURCE_EXPOSED" = yes ]; then status=200; fi
        body='not-found'
        ;;
    *) exit 90 ;;
esac

if [ -n "$outfile" ]; then
    printf '%s\n' "$body" > "$outfile"
else
    printf '%s\n' "$body"
fi
if [ "$want_status" = yes ]; then printf '%s' "$status"; fi
MOCK
    cat > "$WORK/bin/sleep" <<'MOCK'
#!/bin/sh
exit 0
MOCK
    chmod +x "$WORK/bin/"*
    export PATH="$WORK/bin:$PATH"
}

teardown() {
    rm -rf "$WORK"
}

@test "rejects missing port or token without making requests" {
    unset PRICING_HTTP_PORT
    run sh "$SOURCE/tests/Container/smoke.sh"
    [ "$status" -ne 0 ]
    [ "$(cat "$FAKE_HEALTH_COUNT")" -eq 0 ]

    export PRICING_HTTP_PORT=18082
    unset PRICING_SMOKE_TOKEN
    run sh "$SOURCE/tests/Container/smoke.sh"
    [ "$status" -ne 0 ]
    [ "$(cat "$FAKE_HEALTH_COUNT")" -eq 0 ]
}

@test "fails when health endpoint never responds" {
    export FAKE_HEALTH_DOWN=yes
    run sh "$SOURCE/tests/Container/smoke.sh"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Pricing healthcheck failed"* ]]
    [ "$(cat "$FAKE_HEALTH_COUNT")" -eq 30 ]
    [ "$(cat "$FAKE_AUTH_COUNT")" -eq 0 ]
}

@test "refuses simulation endpoint that allows unauthenticated requests" {
    export FAKE_UNAUTH_OK=yes
    run sh "$SOURCE/tests/Container/smoke.sh"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Unauthenticated request was not rejected"* ]]
}

@test "refuses a valid token rejected with HTTP 401" {
    export FAKE_AUTH_REJECT=yes
    run sh "$SOURCE/tests/Container/smoke.sh"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Authenticated synthetic request failed"* ]]
}

@test "fails when a synthetic quote guardrail disappears" {
    export FAKE_BAD_GUARD=yes
    run sh "$SOURCE/tests/Container/smoke.sh"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Commercial guardrail missing"* ]]
}

@test "fails when application source files become reachable" {
    export FAKE_SOURCE_EXPOSED=yes
    run sh "$SOURCE/tests/Container/smoke.sh"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Source path should not be available"* ]]
}

@test "fails when abusive burst never receives HTTP 429" {
    export FAKE_NO_RATE_LIMIT=yes
    run sh "$SOURCE/tests/Container/smoke.sh"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Rapid request flood did not encounter 429"* ]]
    [ "$(cat "$FAKE_AUTH_COUNT")" -eq 21 ]
}

@test "succeeds only with authentication, explicit no-quote and HTTP 429" {
    run sh "$SOURCE/tests/Container/smoke.sh"
    [ "$status" -eq 0 ]
    [[ "$output" == *"Container smoke passed"* ]]
    [ "$(cat "$FAKE_HEALTH_COUNT")" -eq 1 ]
    [ "$(cat "$FAKE_AUTH_COUNT")" -eq 2 ]
}
