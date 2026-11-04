<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Synthetic-only homologation deployment

This procedure prepares a **private, non-commercial** installation of LibreSign Pricing, isolated from unrelated applications and production data.

## Design and destination

The deployment location is selected through private infrastructure configuration. The service uses its own Docker Compose project and isolated networks, without joining unrelated services or their datastores. Only the web container joins the ingress bridge and publishes a loopback listener. PHP-FPM never publishes a port.

The application is PHP 8.5.12 with a pinned base image. The Nginx edge is pinned and rate limits simulation requests by the direct peer IP (30/minute with a burst of 4, concurrent connection cap 2). The application requires a bearer token stored as a Docker secret. Responses remain synthetic and set `quote_allowed=false` and `activation_allowed=false`.

**These controls are for a controlled engineering environment, not yet suitable for confidential costs or internet-facing pricing.** IP-based limits do not enforce daily quotas and can be bypassed by distributed traffic or shared legitimate credentials; future use requires customer-scoped authorization, output field filtering, audit evidence and budget enforcement at the authenticated identity level.

## Preflight

- Docker Engine and Compose plugin must be available, and the operator must be authorized to manage only the dedicated Pricing stack.
- Reserve local port 18080 and check that existing services will not be touched.
- Privately verify capacity and connectivity. Do not connect the service to unrelated container networks or storage.
- Use only source and image SHAs that passed the Docker smoke check. Do not deploy a branch-head or mutable `latest` tag.
- Before first deployment, create a reviewed **homologation** GitHub Environment with required approval and scoped SSH secrets. Never store production SSH credentials in repository secrets or in the source tree. Review whether an external GitHub runner can reach the approved deployment host; if not, use an authorized private deployment runner/relay instead of exposing SSH broadly.

## Verification and maintenance

The repository uses two **complementary** testing layers:

- **Bats** (`bats --tap tests/Shell/*.bats`): deterministic behavior tests for the deploy script, container CI orchestration, HTTP smoke script, PHP entrypoint, and Docker/Compose contract. Deploy tests use temporary files and mocked `docker`, `curl` and `sleep`; they never execute SSH or alter the approved deployment host. The deployment script validates its SHA and port **before** any Docker operation. Negative tests cover failed config, pull, up, exhausted health retries, access controls and cleanup.
- **Real synthetic Docker smoke** (`sh tests/Container/ci.sh`): builds and runs disposable Docker containers with an isolated Compose project; verifies loopback-only networking, non-root runtime, 401/429 responses and commercial guardrails. Run only on a dedicated development/CI Docker daemon, never on a shared production stack.

The CI runs Bats and ShellCheck as **separate independent workflows**. A passing linter does not substitute for behavior testing. The container CI script reuses its just-built PHP image in a disposable root-only helper to set synthetic secret ownership, avoiding a second, independently pinned BusyBox image. ShellCheck runs through a SHA-pinned GitHub Action whose version is tracked by the `github-actions` Dependabot ecosystem. Its analyzer binary follows the upstream `stable` release rather than remaining fixed to a shell-script Docker digest.

**Persistence:** no Docker named volumes are needed: this phase has no database or durable application state. The `tmpfs` mounts are intentionally ephemeral and the file-backed Docker secrets are mounted read-only from private host paths. Keep the private secrets in `secrets/`, not in a persistent `volumes/` tree. Introduce a documented `volumes/` structure only if later functionality genuinely requires durable state.

**Dependabot image updates:** `.github/dependabot.yml` has a weekly `package-ecosystem: docker` entry at `directory: /`. Dependabot's Docker file fetcher recognizes filenames containing `Dockerfile`, including both `Dockerfile` and `Dockerfile.nginx`, and its parser supports `FROM image:version@sha256:digest`. A Docker update PR can update the tag and the digest together; the checks must rebuild/test the changed images before any merge. These are **runtime base images**, not the immutable `ghcr.io/libresign/pricing-{app,web}:sha-<main-commit>` application images selected manually for deployment. Verify the first actual Dependabot Docker job under GitHub's Dependency graph after merging the configuration; a syntactically valid configuration alone does not prove the scheduled job has run.

## Host-local files (operator only)

The deployment directory is chosen by authorized operators in a private environment configuration. `/path/to/private-pricing-deployment` below is a **non-operational example**, not a real server path. Copy the reviewed `docker-compose.yml` and `deploy/homologation.sh` from the approved revision to that directory. The host's deployment configuration and secrets **must not be committed**.

Create private files under `/path/to/private-pricing-deployment/secrets` with restrictive permissions:

```sh
install -d -m 0700 secrets
umask 077
openssl rand -hex 32 > secrets/app-secret
openssl rand -hex 32 > secrets/api-token
cp tests/Fixtures/synthetic-v1.json secrets/catalog.json
chown 10001:10001 secrets/app-secret secrets/api-token secrets/catalog.json
chmod 0400 secrets/app-secret secrets/api-token secrets/catalog.json
```

These commands assume you are an authorized administrator of the dedicated stack. The PHP container runs as UID/GID `10001:10001` and must be able to read those files via Compose secrets. Keep the parent directory restricted; do not loosen file permissions to make a deployment succeed. When copying the synthetic fixture, use a reviewed source checkout rather than live operational economics.

Create a host-local `.env.homologation` with **paths and nonsecret values only**:

```dotenv
PRICING_COMPOSE_PROJECT=libresign-pricing-homologation
PRICING_HTTP_PORT=18080
PRICING_APP_IMAGE=ghcr.io/libresign/pricing-app:sha-<reviewed-main-sha>
PRICING_WEB_IMAGE=ghcr.io/libresign/pricing-web:sha-<reviewed-main-sha>
PRICING_APP_SECRET_FILE=/path/to/private-pricing-deployment/secrets/app-secret
PRICING_API_TOKEN_FILE=/path/to/private-pricing-deployment/secrets/api-token
PRICING_CATALOG_FILE=/path/to/private-pricing-deployment/secrets/catalog.json
```

Replace the image SHA placeholders with an actually published, approved SHA. Set `chmod 0600 .env.homologation`. Do not store this file in the repository. If GHCR images are private, set up **read-only registry credentials on the host**, not within the Compose file.

## Validate, deploy, and verify

```sh
docker compose --env-file .env.homologation config --quiet
docker compose --env-file .env.homologation pull
docker compose --env-file .env.homologation up -d --no-build
curl --fail --silent http://127.0.0.1:18080/healthz
```

For an approved SHA, the manual deployment script performs an immutable-image pull, update and health check:

```sh
sh deploy/homologation.sh <40-character-reviewed-main-sha>
```

The GitHub **Deploy homologation** workflow is a `workflow_dispatch` that accepts a reviewed main SHA and calls this script over verified SSH. Its Environment requires four variables (`PRICING_DEPLOY_HOST`, `PRICING_DEPLOY_USER`, `PRICING_DEPLOY_PORT`, `PRICING_DEPLOY_SCRIPT`) and two secrets (`PRICING_DEPLOY_SSH_KEY`, `PRICING_DEPLOY_KNOWN_HOSTS`). `PRICING_DEPLOY_SCRIPT` must be the absolute, privately configured path to the audited deployment script; it is not committed in this repository. Do not run it before configuring those values and approvals. It has **no push-triggered deployment**.

An authorized developer can access the private endpoint using an SSH tunnel through the approved access path, for example:

```sh
ssh -L 18080:127.0.0.1:18080 "$PRICING_DEPLOY_HOST"
```

Then call `http://127.0.0.1:18080/healthz` and the authenticated POST endpoint locally. The current machine may require the established jump host. Never expose `18080` or use a publicly routable hostname as a shortcut.

## Rollback and operational boundaries

Keep a private record of the last approved image SHA. To roll back, use the same deployment script with that SHA; never modify unrelated Compose projects.

Collect only status codes, request counts and technical service state; avoid logging authorization headers, candidate prices, cost results, catalog contents or identifiable customer data. For incident containment, stop only the dedicated Pricing Compose project and revoke its independent API token.

CI builds and smoke tests use synthetic data exclusively. The Docker image does not bundle runtime catalogs or secrets. The test fixture is explicitly selected in the test environment; no real economics are loaded.

**Not yet implemented:** a real economic catalog, its approval workflow, OAuth/client identity and per-client quotas, field-level cost confidentiality, a proposal UI, an approved WooCommerce adapter, and commercial activation. None of those follows automatically from staging deployment.
