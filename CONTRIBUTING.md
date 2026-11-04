<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Use Conventional Commits with DCO `Signed-off-by` trailers and cryptographically signed commits. Require CI and human review before merging.

Run `composer validate --strict` and `composer check`, including PHP lint, PHP-CS-Fixer, PHPStan, PHPUnit, a generated OpenAPI drift check, and Composer audit. Tool dependencies and lockfiles are isolated under `vendor-bin/` using the Composer bin plugin. Run REUSE, Actionlint, Zizmor, and ShellCheck when changing repository policy, CI or deployment scripts. For Docker changes, run the isolated synthetic stack tests with `sh tests/Container/ci.sh` and verify that unauthorized requests return 401, abusive bursts return 429, the host publishes loopback only and PHP remains on an internal network. Never commit secrets or host deployment configuration.

**Never commit real costs, margins, prices, customer contracts, tax calculations, `.env` files, or credential-bearing configuration.** The only permitted fixture is demonstrably artificial and belongs under `tests/Fixtures/`. Runtime catalog paths must be supplied as environment configuration and should reference a private, access-restricted file outside the repository.

Add HTTP and negative-path tests when modifying the API contract. Modify the schema and documentation in the same PR. Never turn off the commercial authorization guardrails by modifying the synthetic fixture.
