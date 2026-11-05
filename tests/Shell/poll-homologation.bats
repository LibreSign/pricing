#!/usr/bin/env bats
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

setup() {
    WORK="$(mktemp -d)"
    SOURCE="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
    mkdir -p "$WORK/deploy" "$WORK/bin"
    cp "$SOURCE/deploy/poll-homologation.sh" "$WORK/deploy/poll-homologation.sh"
    SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
    OLD_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
    export FAKE_MAIN_FILE="$WORK/main.json"
    export FAKE_RUNS_FILE="$WORK/runs.json"
    export FAKE_CURL_CALLS="$WORK/curl.calls"
    export FAKE_DEPLOY_CALLS="$WORK/deploy.calls"
    export FAKE_DEPLOY_FAILURE=no
    export FAKE_HEALTH=ok
    export FAKE_CURL_ERROR=''
    : > "$FAKE_CURL_CALLS"
    : > "$FAKE_DEPLOY_CALLS"
    printf '%s\n' "PRICING_APP_IMAGE=ghcr.io/libresign/pricing-app:sha-$OLD_SHA" \
        "PRICING_WEB_IMAGE=ghcr.io/libresign/pricing-web:sha-$OLD_SHA" > "$WORK/.env.homologation"

    python3 - "$SHA" "$FAKE_MAIN_FILE" "$FAKE_RUNS_FILE" <<'PY'
import json
import sys
sha, main_path, runs_path = sys.argv[1:]
identity = {"name": "Synthetic Tester", "email": "test@example.invalid"}
with open(main_path, "w", encoding="utf-8") as f:
    json.dump({
        "sha": sha,
        "commit": {
            "author": identity,
            "message": "fix: synthetic\n\nSigned-off-by: Synthetic Tester <test@example.invalid>",
            "verification": {"verified": True},
        },
    }, f)
names = (
    "Composer dependency audit", "Docker images", "Docker smoke",
    "GitHub Actions lint", "GitHub Actions security", "OpenAPI",
    "PHP coding standards", "PHP lint", "PHPStan", "PHPUnit",
    "Production smoke", "REUSE compliance", "Shell script tests", "ShellCheck",
)
rows = [
    {
        "name": name, "head_sha": sha, "event": "push",
        "head_branch": "main", "status": "completed", "conclusion": "success",
    }
    for name in names
]
with open(runs_path, "w", encoding="utf-8") as f:
    json.dump({"total_count": len(rows), "workflow_runs": rows}, f)
PY

    cat > "$WORK/bin/curl" <<'MOCK'
#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_CURL_CALLS"
case "$*" in
    *'/commits/main'*)
        [ "$FAKE_CURL_ERROR" = main ] && exit 52
        cat "$FAKE_MAIN_FILE"
        ;;
    *'/actions/runs?'*)
        [ "$FAKE_CURL_ERROR" = ci ] && exit 53
        cat "$FAKE_RUNS_FILE"
        ;;
    *) exit 51 ;;
esac
MOCK
    cat > "$WORK/bin/docker" <<'MOCK'
#!/bin/sh
[ "$FAKE_HEALTH" = down ] && exit 54
printf '{"status":"ok"}\n'
MOCK
    cat > "$WORK/deploy/homologation.sh" <<'MOCK'
#!/bin/sh
printf '%s\n' "$1" >> "$FAKE_DEPLOY_CALLS"
[ "$FAKE_DEPLOY_FAILURE" = yes ] && exit 23
exit 0
MOCK
    chmod +x "$WORK/bin/curl" "$WORK/bin/docker" "$WORK/deploy/"*.sh
    export PATH="$WORK/bin:$PATH"
}

teardown() {
    rm -rf "$WORK"
}

@test "does not deploy or recheck CI for the already-pinned main revision" {
    sed -i "s/$OLD_SHA/$SHA/g" "$WORK/.env.homologation"
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -eq 0 ]
    [ ! -s "$FAKE_DEPLOY_CALLS" ]
    [ "$(wc -l < "$FAKE_CURL_CALLS")" -eq 1 ]
}

@test "a pinned revision with a failed health check is redeployed after green CI" {
    sed -i "s/$OLD_SHA/$SHA/g" "$WORK/.env.homologation"
    export FAKE_HEALTH=down
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -eq 0 ]
    [ "$(cat "$FAKE_DEPLOY_CALLS")" = "$SHA" ]
    [ "$(wc -l < "$FAKE_CURL_CALLS")" -eq 2 ]
}

@test "deploys new revision only after all main push workflows succeeded" {
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -eq 0 ]
    [ "$(cat "$FAKE_DEPLOY_CALLS")" = "$SHA" ]
    [ "$(wc -l < "$FAKE_CURL_CALLS")" -eq 2 ]
}

@test "a missing required workflow prevents deployment" {
    python3 - "$FAKE_RUNS_FILE" <<'PY'
import json,sys
p=sys.argv[1];d=json.load(open(p));d["workflow_runs"]=d["workflow_runs"][:-1]
d["total_count"]=len(d["workflow_runs"]);json.dump(d,open(p,"w"))
PY
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -eq 0 ]
    [[ "$output" == *"deferred"* ]]
    [ ! -s "$FAKE_DEPLOY_CALLS" ]
}

@test "a pending or failed check prevents deployment" {
    python3 - "$FAKE_RUNS_FILE" <<'PY'
import json,sys
p=sys.argv[1];d=json.load(open(p))
d["workflow_runs"][0]["status"]="in_progress"
d["workflow_runs"][0]["conclusion"]=None
json.dump(d,open(p,"w"))
PY
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -eq 0 ]
    [ ! -s "$FAKE_DEPLOY_CALLS" ]
}

@test "a failed or additional unexpected workflow prevents deployment" {
    python3 - "$FAKE_RUNS_FILE" <<'PY'
import json,sys
p=sys.argv[1];d=json.load(open(p))
d["workflow_runs"].append({
    "name":"additional", "head_sha":d["workflow_runs"][0]["head_sha"],
    "event":"push", "head_branch":"main",
    "status":"completed", "conclusion":"failure"
})
d["total_count"]=len(d["workflow_runs"])
json.dump(d,open(p,"w"))
PY
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -eq 0 ]
    [ ! -s "$FAKE_DEPLOY_CALLS" ]
}

@test "unsigned commit or missing matching DCO is rejected before checking CI" {
    python3 - "$FAKE_MAIN_FILE" <<'PY'
import json,sys
p=sys.argv[1];d=json.load(open(p))
d["commit"]["verification"]["verified"]=False
json.dump(d,open(p,"w"))
PY
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -ne 0 ]
    [ ! -s "$FAKE_DEPLOY_CALLS" ]
    [ "$(wc -l < "$FAKE_CURL_CALLS")" -eq 1 ]
}

@test "deploy failure is propagated, rather than reported as successful" {
    export FAKE_DEPLOY_FAILURE=yes
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -eq 23 ]
    [ "$(cat "$FAKE_DEPLOY_CALLS")" = "$SHA" ]
}

@test "unavailable GitHub CI status defers without deploying" {
    export FAKE_CURL_ERROR=ci
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -eq 0 ]
    [[ "$output" == *"deferred"* ]]
    [ ! -s "$FAKE_DEPLOY_CALLS" ]
}

@test "unavailable main metadata fails before deploying" {
    export FAKE_CURL_ERROR=main
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -ne 0 ]
    [ ! -s "$FAKE_DEPLOY_CALLS" ]
}

@test "missing private host configuration stops without network or deploy" {
    rm "$WORK/.env.homologation"
    run sh "$WORK/deploy/poll-homologation.sh"
    [ "$status" -eq 1 ]
    [ ! -s "$FAKE_CURL_CALLS" ]
    [ ! -s "$FAKE_DEPLOY_CALLS" ]
}
