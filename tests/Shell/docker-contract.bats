#!/usr/bin/env bats
# SPDX-FileCopyrightText: 2026 LibreSign contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

setup() {
    SOURCE="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
}

@test "Dockerfile and Dockerfile.nginx pin each base tag to an immutable digest" {
    local dockerfile reference from_line aliases
    for dockerfile in "$SOURCE/Dockerfile" "$SOURCE/Dockerfile.nginx"; do
        [ -s "$dockerfile" ]
        grep -q '^FROM ' "$dockerfile"
        aliases=''
        while IFS= read -r from_line; do
            reference="$(printf '%s\n' "$from_line" | awk '{print $2}')"
            if [[ "$reference" =~ ^[^[:space:]@]+:[^[:space:]@]+@sha256:[a-f0-9]{64}$ ]]; then
                :
            else
                # Multi-stage COPY/extends must refer to an earlier local stage.
                [[ " $aliases " == *" $reference "* ]]
            fi
            if [[ "$from_line" == *' AS '* ]]; then
                aliases="$aliases $(printf '%s\n' "$from_line" | awk '{print $NF}')"
            fi
        done < <(grep '^FROM ' "$dockerfile")
    done
}

@test "ShellCheck workflow uses a tagged, SHA-pinned Dependabot-managed action" {
    run grep -E 'uses: ludeeus/action-shellcheck@[a-f0-9]{40} # 2.0.0' "$SOURCE/.github/workflows/shellcheck.yml"
    [ "$status" -eq 0 ]
    ! grep -q 'docker run' "$SOURCE/.github/workflows/shellcheck.yml"
}

@test "Dependabot GitHub Actions ecosystem tracks workflow actions" {
    run grep -A 4 'package-ecosystem: github-actions' "$SOURCE/.github/dependabot.yml"
    [ "$status" -eq 0 ]
    [[ "$output" == *"directory: /"* ]]
    [[ "$output" == *"interval: weekly"* ]]
}

@test "Dependabot Docker ecosystem scans root Dockerfiles" {
    run grep -A 4 'package-ecosystem: docker' "$SOURCE/.github/dependabot.yml"
    [ "$status" -eq 0 ]
    [[ "$output" == *"directory: /"* ]]
    [[ "$output" == *"interval: weekly"* ]]
}

@test "Compose has no persistent named volumes and requires three file-backed secrets" {
    run env PRICING_APP_IMAGE=example.invalid/app:synthetic \
        PRICING_WEB_IMAGE=example.invalid/web:synthetic \
        PRICING_APP_SECRET_FILE=/tmp/pricing-test-app-secret \
        PRICING_API_TOKEN_FILE=/tmp/pricing-test-api-token \
        PRICING_CATALOG_FILE=/tmp/pricing-test-catalog \
        docker compose -f "$SOURCE/docker-compose.yml" config --format json
    [ "$status" -eq 0 ]
    printf '%s\n' "$output" | jq -e '
        (.volumes == null)
        and (.services.app.read_only == true)
        and ((.services.app.secrets | length) == 3)
        and (.services.app.networks | keys == ["backend"])
        and (.networks.backend.internal == true)
        and (.services.app.ports == null)
        and (.services.nginx.ports | all(.host_ip == "127.0.0.1"))
    ' >/dev/null
}

@test "Compose keeps ephemeral writable directories in tmpfs" {
    run env PRICING_APP_IMAGE=example.invalid/app:synthetic \
        PRICING_WEB_IMAGE=example.invalid/web:synthetic \
        PRICING_APP_SECRET_FILE=/tmp/pricing-test-app-secret \
        PRICING_API_TOKEN_FILE=/tmp/pricing-test-api-token \
        PRICING_CATALOG_FILE=/tmp/pricing-test-catalog \
        docker compose -f "$SOURCE/docker-compose.yml" config --format json
    [ "$status" -eq 0 ]
    printf '%s\n' "$output" | jq -e '
        (.services.app.tmpfs | length > 0)
        and (.services.nginx.tmpfs | length > 0)
    ' >/dev/null
}

@test "private deployment uses outbound-only polling without hosted SSH secrets" {
    [ ! -e "$SOURCE/.github/workflows/deploy-homologation.yml" ]
    [ -s "$SOURCE/deploy/poll-homologation.sh" ]
    run grep -F 'api.github.com/repos/LibreSign/pricing' "$SOURCE/deploy/poll-homologation.sh"
    [ "$status" -eq 0 ]
    ! grep -q 'DEPLOY_SSH_KEY' "$SOURCE/deploy/poll-homologation.sh"
}

@test "Workbench HTML and assets do not consume the protected API request quota" {
    run grep -F 'location ~ ^/internal/pricing-workbench' "$SOURCE/docker/nginx/pricing.conf"
    [ "$status" -eq 0 ]

    local ui_block
    ui_block="$(awk '
        /# Static workbench assets do not consume/ { capture=1; next }
        capture && /^    }/ { exit }
        capture { print }
    ' "$SOURCE/docker/nginx/pricing.conf")"

    [[ "$ui_block" == *'limit_except GET { deny all; }'* ]]
    [[ "$ui_block" == *'limit_conn pricing_connections 2;'* ]]
    [[ "$ui_block" != *'limit_req zone=pricing_requests'* ]]

    run grep -c 'limit_req zone=pricing_requests burst=4 nodelay;' "$SOURCE/docker/nginx/pricing.conf"
    [ "$status" -eq 0 ]
    [ "$output" -eq 5 ]
}

@test "Commercial research requires the same protected API limits and private GET-only assets" {
    local offer_block
    offer_block="$(awk '
        /location = \/v1\/offer-simulations/ { capture=1; next }
        capture && /^    }/ { exit }
        capture { print }
    ' "$SOURCE/docker/nginx/pricing.conf")"
    [[ "$offer_block" == *'limit_except POST { deny all; }'* ]]
    [[ "$offer_block" == *'limit_req zone=pricing_requests burst=4 nodelay;'* ]]
    [[ "$offer_block" == *'fastcgi_param HTTP_AUTHORIZATION $http_authorization;'* ]]

    local private_assets
    private_assets="$(awk '
        /# Separate internal static assets/ { capture=1; next }
        capture && /^    }/ { exit }
        capture { print }
    ' "$SOURCE/docker/nginx/pricing.conf")"
    [[ "$private_assets" == *'pricing-studio'* ]]
    [[ "$private_assets" == *'limit_except GET { deny all; }'* ]]
    [[ "$private_assets" != *'limit_req zone=pricing_requests'* ]]
}
