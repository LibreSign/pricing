#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
envfile="$root/.env.homologation"
[ -r "$envfile" ] || { echo "Private deployment configuration is unavailable" >&2; exit 1; }

# Only one poll/deploy operation may run at a time.
exec 9>"$root/.deploy-poll.lock"
flock -n 9 || { echo "A homologation deployment check is already running"; exit 0; }

api=https://api.github.com/repos/LibreSign/pricing
main_response="$(curl -fsS --retry 1 --max-time 15 \
    -H 'Accept: application/vnd.github+json' "$api/commits/main")"
main_sha="$(printf '%s' "$main_response" | python3 -c '
import json
import re
import sys
data = json.load(sys.stdin)
sha = data.get("sha", "")
commit = data.get("commit", {})
author = commit.get("author") or {}
signed = "Signed-off-by: {} <{}>".format(author.get("name", ""), author.get("email", ""))
if not re.fullmatch("[0-9a-f]{40}", sha):
    sys.exit("Invalid main commit SHA")
if not commit.get("verification", {}).get("verified"):
    sys.exit("Unsigned main commit")
if signed not in commit.get("message", ""):
    sys.exit("Main commit is missing matching DCO")
print(sha)
')"

# Do not re-deploy a revision already persisted after a successful health check.
app_sha="$(sed -n 's@^PRICING_APP_IMAGE=ghcr.io/libresign/pricing-app:sha-@@p' "$envfile")"
web_sha="$(sed -n 's@^PRICING_WEB_IMAGE=ghcr.io/libresign/pricing-web:sha-@@p' "$envfile")"
if [ "$app_sha" = "$main_sha" ] && [ "$web_sha" = "$main_sha" ]; then
    if docker compose --env-file "$envfile" exec -T nginx \
        wget -qO- http://127.0.0.1:8080/healthz | grep -q '"status":"ok"'; then
        echo "Homologation already tracks the approved healthy main revision"
        exit 0
    fi
fi

# Require a complete green push pipeline, including image publication.
if ! ci_response="$(curl -fsS --retry 1 --max-time 15 \
    -H 'Accept: application/vnd.github+json' \
    "$api/actions/runs?branch=main&event=push&head_sha=$main_sha&per_page=100")"; then
    echo "Homologation update deferred: GitHub CI status unavailable"
    exit 0
fi
if ! printf '%s' "$ci_response" | python3 -c '
import json
import sys
from collections import Counter
data = json.load(sys.stdin)
sha = sys.argv[1]
required = {
    "Composer dependency audit", "Docker images", "Docker smoke",
    "GitHub Actions lint", "GitHub Actions security", "OpenAPI",
    "PHP coding standards", "PHP lint", "PHPStan", "PHPUnit",
    "Production smoke", "REUSE compliance", "Shell script tests", "ShellCheck",
}
runs = data.get("workflow_runs", [])
names = Counter(
    run.get("name") for run in runs
    if run.get("head_sha") == sha
    and run.get("event") == "push"
    and run.get("head_branch") == "main"
)
if data.get("total_count", 0) > 100 or not required.issubset(names):
    sys.exit(1)
if any(
    run.get("status") != "completed" or run.get("conclusion") != "success"
    for run in runs
):
    sys.exit(1)
' "$main_sha"; then
    echo "Homologation update deferred: required main checks or images not ready"
    exit 0
fi

echo "All required main checks passed; deploying approved immutable images"
sh "$root/deploy/homologation.sh" "$main_sha"
