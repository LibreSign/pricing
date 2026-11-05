<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Use Conventional Commits with DCO `Signed-off-by` trailers and cryptographically signed commits. Require CI and human review before merging.

Run `composer validate --strict` and `composer check`, including PHP lint, PHP-CS-Fixer, PHPStan, PHPUnit, a generated OpenAPI drift check, and Composer audit. Tool dependencies and lockfiles are isolated under `vendor-bin/` using the Composer bin plugin. Run REUSE, Actionlint, Zizmor, and ShellCheck when changing repository policy, CI or deployment scripts. **Run `bats --tap tests/Shell/*.bats` for behavior tests of every shell script**, including an offline-mocked deployment (no contact with the approved deployment host). For Docker changes, also run the isolated synthetic stack tests with `sh tests/Container/ci.sh` and verify that unauthorized requests return 401, abusive bursts return 429, the disposable CI stack binds loopback only, and PHP remains on an internal network. For a private host deployment, no port may be published; the deploy script must refuse a Compose configuration with published ports and must update host-local image pins only after a successful health check, with regression coverage. Never commit secrets or host deployment configuration.

**Never commit real costs, margins, prices, customer contracts, tax calculations, `.env` files, or credential-bearing configuration.** The only permitted fixture is demonstrably artificial and belongs under `tests/Fixtures/`. Runtime catalog paths must be supplied as environment configuration and should reference a private, access-restricted file outside the repository.

Add HTTP and negative-path tests when modifying the API contract. Modify the schema and documentation in the same PR. Never turn off the commercial authorization guardrails by modifying the synthetic fixture.
