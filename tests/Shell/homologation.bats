#!/usr/bin/env bats
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

setup() {
    SOURCE="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
    WORK="$(mktemp -d)"
    mkdir -p "$WORK/deploy" "$WORK/bin"
    cp "$SOURCE/deploy/homologation.sh" "$WORK/deploy/homologation.sh"
    printf '%s\n' 'PRICING_COMPOSE_PROJECT=synthetic-test' > "$WORK/.env.homologation"

    SHA='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
    export FAKE_CALLS="$WORK/docker.calls"
    export FAKE_HEALTH_ATTEMPTS="$WORK/health.attempts"
    export FAKE_SLEEP_CALLS="$WORK/sleep.calls"
    export FAKE_DOCKER_FAIL=''
    export FAKE_HEALTH_MODE=success
    export FAKE_HEALTH_FAILS=0
    export FAKE_PUBLISHED_PORT=no
    : > "$FAKE_CALLS"
    printf '0\n' > "$FAKE_HEALTH_ATTEMPTS"
    : > "$FAKE_SLEEP_CALLS"

    cat > "$WORK/bin/docker" <<'MOCK'
#!/bin/sh
printf '%s | %s | %s\n' "$*" "$PRICING_APP_IMAGE" "$PRICING_WEB_IMAGE" >> "$FAKE_CALLS"
case "$*" in
    *' config --quiet') step=config ;;
    *' config --format json')
        [ "$FAKE_DOCKER_FAIL" = config-json ] && exit 19
        if [ "$FAKE_PUBLISHED_PORT" = yes ]; then
            printf '{"services":{"nginx":{"ports":[{"published":18080}]}}}\n'
        else
            printf '{"services":{"nginx":{"networks":["backend","api"]}}}\n'
        fi
        exit 0
        ;;
    *' pull app nginx') step=pull ;;
    *' up -d --no-build --remove-orphans') step=up ;;
    *' exec -T nginx wget -qO- http://127.0.0.1:8080/healthz')
        attempts="$(cat "$FAKE_HEALTH_ATTEMPTS")"
        attempts=$((attempts + 1))
        printf '%s\n' "$attempts" > "$FAKE_HEALTH_ATTEMPTS"
        if [ "$FAKE_HEALTH_MODE" = never ] || [ "$attempts" -le "$FAKE_HEALTH_FAILS" ]; then
            exit 22
        fi
        if [ "$FAKE_HEALTH_MODE" = invalid ]; then
            printf '{"status":"fail"}\n'
        else
            printf '{"status":"ok"}\n'
        fi
        exit 0
        ;;
    *) exit 91 ;;
esac
[ "$step" = "$FAKE_DOCKER_FAIL" ] && exit 19
exit 0
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

@test "rejects missing, malformed and uppercase SHAs before Docker" {
    run sh "$WORK/deploy/homologation.sh"
    [ "$status" -eq 2 ]
    run sh "$WORK/deploy/homologation.sh" abc123
    [ "$status" -eq 2 ]
    run sh "$WORK/deploy/homologation.sh" AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
    [ "$status" -eq 2 ]
    run sh "$WORK/deploy/homologation.sh" 'abc;touch /tmp/not-executed'
    [ "$status" -eq 2 ]
    [ ! -s "$FAKE_CALLS" ]
}

@test "missing configuration fails without contacting Docker" {
    rm "$WORK/.env.homologation"
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Missing host-local deployment configuration"* ]]
    [ ! -s "$FAKE_CALLS" ]
}

@test "private deployment does not require any host HTTP port" {
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 0 ]
    [ "$(cat "$FAKE_HEALTH_ATTEMPTS")" -eq 1 ]
    ! grep -q 'curl' "$FAKE_CALLS"
    grep -q ' exec -T nginx wget -qO- http://127.0.0.1:8080/healthz' "$FAKE_CALLS"
}

@test "host-published ports are refused before pull or up" {
    export FAKE_PUBLISHED_PORT=yes
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Host-published ports are forbidden"* ]]
    ! grep -q ' pull app nginx' "$FAKE_CALLS"
    ! grep -q ' up -d' "$FAKE_CALLS"
}

@test "failed Compose configuration prevents pull and up" {
    export FAKE_DOCKER_FAIL=config
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 19 ]
    ! grep -q ' pull app nginx' "$FAKE_CALLS"
}

@test "failed config JSON inspection prevents pull and up" {
    export FAKE_DOCKER_FAIL=config-json
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 19 ]
    ! grep -q ' pull app nginx' "$FAKE_CALLS"
}

@test "failed image pull prevents container changes" {
    export FAKE_DOCKER_FAIL=pull
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 19 ]
    ! grep -q ' up -d' "$FAKE_CALLS"
    [ "$(cat "$FAKE_HEALTH_ATTEMPTS")" -eq 0 ]
}

@test "failed container startup prevents health checks" {
    export FAKE_DOCKER_FAIL=up
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 19 ]
    [ "$(cat "$FAKE_HEALTH_ATTEMPTS")" -eq 0 ]
}

@test "approved SHA always overrides mutable image tags" {
    printf '%s\n' \
        'PRICING_APP_IMAGE=ghcr.io/libresign/pricing-app:latest' \
        'PRICING_WEB_IMAGE=ghcr.io/libresign/pricing-web:latest' > "$WORK/.env.homologation"
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 0 ]
    grep -q "ghcr.io/libresign/pricing-app:sha-$SHA" "$FAKE_CALLS"
    grep -q "ghcr.io/libresign/pricing-web:sha-$SHA" "$FAKE_CALLS"
    ! grep -q ' | .*:latest ' "$FAKE_CALLS"
}

@test "temporary internal health failures are retried" {
    export FAKE_HEALTH_FAILS=2
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 0 ]
    [ "$(cat "$FAKE_HEALTH_ATTEMPTS")" -eq 3 ]
    [ "$(wc -l < "$FAKE_SLEEP_CALLS")" -eq 2 ]
}

@test "a successful HTTP response without healthy body is not accepted" {
    export FAKE_HEALTH_MODE=invalid
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 1 ]
    [ "$(cat "$FAKE_HEALTH_ATTEMPTS")" -eq 30 ]
}

@test "exhausted internal health retries report manual rollback" {
    export FAKE_HEALTH_MODE=never
    run sh "$WORK/deploy/homologation.sh" "$SHA"
    [ "$status" -eq 1 ]
    [[ "$output" == *"manually roll back"* ]]
    [ "$(cat "$FAKE_HEALTH_ATTEMPTS")" -eq 30 ]
    [ "$(wc -l < "$FAKE_SLEEP_CALLS")" -eq 29 ]
}
