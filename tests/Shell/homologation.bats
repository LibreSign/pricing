#!/usr/bin/env bats
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

setup() {
    SOURCE="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
    WORK="$(mktemp -d)"
    mkdir -p "$WORK/deploy" "$WORK/bin"
    cp "$SOURCE/deploy/homologation.sh" "$WORK/deploy/homologation.sh"
    printf '%s\n' 'PRICING_HTTP_PORT=18080' > "$WORK/.env.homologation"

    SHA='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    export FAKE_CALLS="$WORK/docker.calls"
    export FAKE_HEALTH_ATTEMPTS="$WORK/health.attempts"
    export FAKE_SLEEP_CALLS="$WORK/sleep.calls"
    export FAKE_DOCKER_FAIL=''
    export FAKE_HEALTH_MODE='success'
    export FAKE_HEALTH_FAILS=0
    : > "$FAKE_CALLS"
    printf '0\n' > "$FAKE_HEALTH_ATTEMPTS"
    : > "$FAKE_SLEEP_CALLS"

    cat > "$WORK/bin/docker" <<'MOCK'
#!/bin/sh
printf '%s | %s | %s\n' "$*" "$PRICING_APP_IMAGE" "$PRICING_WEB_IMAGE" >> "$FAKE_CALLS"
case "$*" in
    *' config --quiet') step=config ;;
    *' pull app nginx') step=pull ;;
    *' up -d --no-build --remove-orphans') step=up ;;
    *) exit 91 ;;
esac
[ "$step" = "$FAKE_DOCKER_FAIL" ] && exit 19
exit 0
MOCK
    cat > "$WORK/bin/curl" <<'MOCK'
#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_CALLS"
attempts="$(cat "$FAKE_HEALTH_ATTEMPTS")"
attempts=$((attempts + 1))
printf '%s\n' "$attempts" > "$FAKE_HEALTH_ATTEMPTS"
if [ "$FAKE_HEALTH_MODE" = never ] || [ "$attempts" -le "$FAKE_HEALTH_FAILS" ]; then
    exit 22
fi
printf '{"status":"ok"}\n'
MOCK
    cat > "$WORK/bin/sleep" <<'MOCK'
#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_SLEEP_CALLS"
MOCK
    chmod +x "$WORK/bin/"*
    export PATH="$WORK/bin:$PATH"
}

teardown() {
    rm -rf "$WORK"
}

@test "rejects missing, short and uppercase SHA without Docker calls" {
    run sh "$WORK/deploy/homologation.sh"
    [ "$status" -eq 2 ]
    run sh "$WORK/deploy/homologation.sh" abc123
    [ "$status" -eq 2 ]
    run sh "$WORK/deploy/homologation.sh" AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
    [ "$status" -eq 2 ]
    [ ! -s "$FAKE_CALLS" ]
}

@test "rejects shell metacharacters in revision without executing them" {
    run sh "$WORK/deploy/homologation.sh" 'abc;touch /tmp/never-execute'
    [ "$status" -eq 2 ]
    [ ! -s "$FAKE_CALLS" ]
}

@test "fails closed when local deployment configuration is missing" {
    rm "$WORK/.env.homologation"
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Missing host-local deployment configuration"* ]]
    [ ! -s "$FAKE_CALLS" ]
}

@test "rejects missing port before Docker is changed" {
    printf '%s\n' 'PRICING_COMPOSE_PROJECT=test' > "$WORK/.env.homologation"
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 1 ]
    [ ! -s "$FAKE_CALLS" ]
}

@test "rejects nonnumeric port before Docker is changed" {
    printf '%s\n' 'PRICING_HTTP_PORT=abc123' > "$WORK/.env.homologation"
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 1 ]
    [ ! -s "$FAKE_CALLS" ]
}

@test "rejects out-of-range port before Docker is changed" {
    printf '%s\n' 'PRICING_HTTP_PORT=65536' > "$WORK/.env.homologation"
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 1 ]
    [ ! -s "$FAKE_CALLS" ]
}

@test "Docker config failure prevents pull and up" {
    export FAKE_DOCKER_FAIL=config
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 19 ]
    [ "$(grep -c ' | ' "$FAKE_CALLS")" -eq 1 ]
    ! grep -q ' pull app nginx' "$FAKE_CALLS"
    ! grep -q ' up -d' "$FAKE_CALLS"
}

@test "Docker pull failure prevents up" {
    export FAKE_DOCKER_FAIL=pull
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 19 ]
    grep -q ' pull app nginx' "$FAKE_CALLS"
    ! grep -q ' up -d' "$FAKE_CALLS"
}

@test "Docker up failure prevents health validation" {
    export FAKE_DOCKER_FAIL=up
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 19 ]
    grep -q ' up -d' "$FAKE_CALLS"
    [ "$(cat "$FAKE_HEALTH_ATTEMPTS")" -eq 0 ]
}

@test "successful deployment forces immutable SHA tags and loopback health" {
    # File values must not override the explicitly approved image SHA.
    printf '%s\n' 'PRICING_HTTP_PORT=18081' \
        'PRICING_APP_IMAGE=ghcr.io/libresign/pricing-app:latest' \
        'PRICING_WEB_IMAGE=ghcr.io/libresign/pricing-web:latest' > "$WORK/.env.homologation"
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 0 ]
    [[ "$output" == *"Homologation liveness verified"* ]]
    [ "$(grep -c ' | ' "$FAKE_CALLS")" -eq 3 ]
    grep -q "ghcr.io/libresign/pricing-app:sha-$SHA" "$FAKE_CALLS"
    grep -q "ghcr.io/libresign/pricing-web:sha-$SHA" "$FAKE_CALLS"
    ! grep -q ' | .*:latest ' "$FAKE_CALLS"
    grep -q 'http://127.0.0.1:18081/healthz' "$FAKE_CALLS"
}

@test "transient health errors recover without deploying again" {
    export FAKE_HEALTH_FAILS=2
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 0 ]
    [ "$(cat "$FAKE_HEALTH_ATTEMPTS")" -eq 3 ]
    [ "$(wc -l < "$FAKE_SLEEP_CALLS")" -eq 2 ]
    [ "$(grep -c ' | ' "$FAKE_CALLS")" -eq 3 ]
}

@test "exhausted health attempts fail with rollback guidance" {
    export FAKE_HEALTH_MODE=never
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 1 ]
    [[ "$output" == *"manually roll back"* ]]
    [ "$(cat "$FAKE_HEALTH_ATTEMPTS")" -eq 30 ]
    [ "$(wc -l < "$FAKE_SLEEP_CALLS")" -eq 29 ]
}
