#!/usr/bin/env bats
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

setup() {
    SOURCE="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
    ENTRYPOINT="$SOURCE/docker/php/entrypoint.sh"
    WORK="$(mktemp -d)"
}

teardown() {
    rm -rf "$WORK"
}

@test "starts no process when neither secret nor secret file is configured" {
    run env -u APP_SECRET -u APP_SECRET_FILE \
        sh "$ENTRYPOINT" sh -c 'printf started'
    [ "$status" -eq 1 ]
    [[ "$output" == *"APP_SECRET is required"* ]]
    [[ "$output" != *"started"* ]]
}

@test "unreadable file fails closed even when environment secret exists" {
    run env APP_SECRET=synthetic-fallback APP_SECRET_FILE="$WORK/nonexistent" \
        sh "$ENTRYPOINT" sh -c 'printf started'
    [ "$status" -eq 1 ]
    [[ "$output" == *"APP_SECRET_FILE is not readable"* ]]
    [[ "$output" != *"started"* ]]
}

@test "empty configured secret fails closed" {
    : > "$WORK/secret"
    run env -u APP_SECRET APP_SECRET_FILE="$WORK/secret" \
        sh "$ENTRYPOINT" sh -c 'printf started'
    [ "$status" -eq 1 ]
    [[ "$output" == *"APP_SECRET is required"* ]]
    [[ "$output" != *"started"* ]]
}

@test "file-backed secret overrides environment and is forwarded to process" {
    printf 'synthetic-file-only-secret\n' > "$WORK/secret"
    run env APP_SECRET=synthetic-obsolete APP_SECRET_FILE="$WORK/secret" \
        sh "$ENTRYPOINT" sh -c 'printf "%s" "$APP_SECRET"'
    [ "$status" -eq 0 ]
    [ "$output" = synthetic-file-only-secret ]
}

@test "environment secret works for isolated standalone smoke" {
    run env -u APP_SECRET_FILE APP_SECRET=synthetic-test-secret \
        sh "$ENTRYPOINT" sh -c 'printf "%s" "$APP_SECRET"'
    [ "$status" -eq 0 ]
    [ "$output" = synthetic-test-secret ]
}

@test "entrypoint forwards child failure exit code" {
    run env -u APP_SECRET_FILE APP_SECRET=synthetic-test-secret \
        sh "$ENTRYPOINT" sh -c 'exit 17'
    [ "$status" -eq 17 ]
}
