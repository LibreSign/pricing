<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Architecture and developer setup

## Runtime and installation

Requirements: PHP 8.5.12 or newer, Composer 2, and PHP extensions required by Symfony and PHPUnit.

```sh
composer validate --strict
composer install --no-interaction
composer check
```

Development CLI tools are isolated through `bamarni/composer-bin-plugin`. The root `composer.lock` controls runtime and framework dependencies. PHP-CS-Fixer, PHPUnit, and PHPStan have separate manifests and lockfiles under `vendor-bin/`. A normal Composer install forwards installation to these tools; do not pass `--no-plugins` or `--no-scripts` in CI.

Common checks: `composer lint`, `composer style`, `composer analyse`, `composer test`, `composer api:check`, and `composer audit --locked`.

## Running a local demonstration

The runtime reads `PRICING_CATALOG_PATH` only when explicitly configured. No default operational catalog ships in this repository. To exercise the API locally with the artificial fixture:

```sh
export APP_ENV=dev
export APP_SECRET=local-demo-only-secret
export PRICING_API_TOKEN=local-demo-token
export PRICING_CATALOG_PATH="$(pwd)/tests/Fixtures/synthetic-v1.json"

php -S 127.0.0.1:8080 -t public
```

In another terminal:

```sh
curl -sS -X POST http://127.0.0.1:8080/v1/simulations \
  -H 'Authorization: Bearer local-demo-token' \
  -H 'Content-Type: application/json' \
  -d '{"model":"cloud","parameter_version":"synthetic-v1","usage":{"documents":100,"storage_gb":10,"service_hours":2}}'
```

For the exact request/response behavior see [the API contract](api.md). Do not send actual confidential economics to this prototype. The single-token authorization does not provide per-consumer permissions or field-level confidentiality.

## OpenAPI generation

The source of truth is the Symfony route and the PHP attributes on the controller and typed input/output models. The YAML file is **generated**, not manually edited.

```sh
composer api:generate
composer api:check
```

The second command regenerates the specification in memory and fails if `docs/openapi.yaml` differs. CI runs it for every pull request. When changing a route, DTO, validation limit, or response contract, update the PHP source first and commit the resulting generated file.

The NelmioApiDocBundle runs only in development and test environments. No interactive documentation UI is enabled on the public service.

## Economic parameters and boundaries

Only explicitly artificial, non-homologated fixtures are accepted. An absent/invalid catalog yields a sanitized HTTP 503 response; test fixtures are never loaded implicitly.

Future catalogs with private economic information require a separately approved implementation, suitable access controls, role-specific output DTOs, and independent fiscal/economic validation. No current output may be treated as an authorized commercial offer.
