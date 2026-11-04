#!/usr/bin/env bats
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

setup() {
    SOURCE="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
    WORK="$(mktemp -d)"
    mkdir -p "$WORK/tests/Container" "$WORK/tests/Fixtures" "$WORK/bin"
    cp "$SOURCE/tests/Container/ci.sh" "$WORK/tests/Container/ci.sh"
    printf '{}\n' > "$WORK/tests/Fixtures/synthetic-v1.json"
    cat > "$WORK/tests/Container/smoke.sh" <<'MOCK'
#!/bin/sh
printf 'SMOKE_TEST_CALLED\n' >> "$FAKE_DOCKER_CALLS"
MOCK
    export FAKE_DOCKER_CALLS="$WORK/docker.calls"
    export FAKE_FAIL_STEP=''
    export FAKE_BAD_USER=''
    export FAKE_BAD_PORT=''
    export FAKE_BAD_NETWORK=''
    : > "$FAKE_DOCKER_CALLS"
    cat > "$WORK/bin/docker" <<'MOCK'
#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_DOCKER_CALLS"
case "$1" in
    run)
        exit 0
        ;;
    compose)
        case "$*" in
            *' config --quiet') step=config ;;
            *' build --pull') step=build ;;
            *' up -d --no-build') step=up ;;
            *' ps -q nginx') printf 'fake-nginx\n'; exit 0 ;;
            *' ps -q app') printf 'fake-app\n'; exit 0 ;;
            *' down --remove-orphans') step=down ;;
            *) exit 90 ;;
        esac
        [ "$FAKE_FAIL_STEP" = "$step" ] && exit 24
        ;;
    inspect)
        case "$*" in
            *'.Config.User'*)
                if [ "$FAKE_BAD_USER" = yes ]; then printf '0:0\n'; else printf '10001:10001\n'; fi
                ;;
            *'HostIp'*)
                if [ "$FAKE_BAD_PORT" = yes ]; then printf '0.0.0.0\n'; else printf '127.0.0.1\n'; fi
                ;;
            *'len .NetworkSettings.Networks'*)
                printf '1\n'
                ;;
            *) exit 91 ;;
        esac
        ;;
    network)
        if [ "$FAKE_BAD_NETWORK" = yes ]; then printf 'false\n'; else printf 'true\n'; fi
        ;;
    port)
        # No PHP port is published.
        ;;
    *) exit 92 ;;
esac
exit 0
MOCK
    chmod +x "$WORK/bin/docker"
    export PATH="$WORK/bin:$PATH"
    export PRICING_HTTP_PORT=18082
}

teardown() {
    rm -rf "$WORK"
}

@test "runs build, isolated checks and smoke; always removes the test stack" {
    run sh "$WORK/tests/Container/ci.sh"
    [ "$status" -eq 0 ]
    grep -q ' config --quiet' "$FAKE_DOCKER_CALLS"
    grep -q ' build --pull' "$FAKE_DOCKER_CALLS"
    grep -q ' up -d --no-build' "$FAKE_DOCKER_CALLS"
    grep -q 'SMOKE_TEST_CALLED' "$FAKE_DOCKER_CALLS"
    grep -q ' down --remove-orphans' "$FAKE_DOCKER_CALLS"
}

@test "failed build prevents up and still removes test resources" {
    export FAKE_FAIL_STEP=build
    run sh "$WORK/tests/Container/ci.sh"
    [ "$status" -eq 24 ]
    ! grep -q ' up -d --no-build' "$FAKE_DOCKER_CALLS"
    ! grep -q SMOKE_TEST_CALLED "$FAKE_DOCKER_CALLS"
    grep -q ' down --remove-orphans' "$FAKE_DOCKER_CALLS"
}

@test "root PHP user is rejected before running smoke" {
    export FAKE_BAD_USER=yes
    run sh "$WORK/tests/Container/ci.sh"
    [ "$status" -ne 0 ]
    ! grep -q SMOKE_TEST_CALLED "$FAKE_DOCKER_CALLS"
    grep -q ' down --remove-orphans' "$FAKE_DOCKER_CALLS"
}

@test "non-loopback binding is rejected before running smoke" {
    export FAKE_BAD_PORT=yes
    run sh "$WORK/tests/Container/ci.sh"
    [ "$status" -ne 0 ]
    ! grep -q SMOKE_TEST_CALLED "$FAKE_DOCKER_CALLS"
    grep -q ' down --remove-orphans' "$FAKE_DOCKER_CALLS"
}

@test "external PHP network is rejected before running smoke" {
    export FAKE_BAD_NETWORK=yes
    run sh "$WORK/tests/Container/ci.sh"
    [ "$status" -ne 0 ]
    ! grep -q SMOKE_TEST_CALLED "$FAKE_DOCKER_CALLS"
    grep -q ' down --remove-orphans' "$FAKE_DOCKER_CALLS"
}
