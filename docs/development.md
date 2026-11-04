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

Development tools are isolated through `bamarni/composer-bin-plugin`. The root `require-dev` contains only the bin plugin and `nelmio/api-doc-bundle`: the former bootstraps the isolated installations, while the latter must remain available to the Symfony kernel as a registered development/test bundle. PHPUnit (including Symfony BrowserKit), PHPStan, and PHP-CS-Fixer have independent manifests and lockfiles under `vendor-bin/`. Optional development dependencies not required by the application are not duplicated in the root manifest. OpenAPI generation invokes Symfony Console directly, without a separate process library.

A normal Composer install forwards installation to the isolated tools; do not pass `--no-plugins` or `--no-scripts` in CI. A production installation with `composer install --no-dev` excludes both root development dependencies and the isolated tooling.

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

The Composer scripts invoke the native `nelmio:apidoc:dump` command. The first writes the generated YAML; the second pipes the generated output into `diff` and fails on drift. Licensing for this generated file is recorded in `REUSE.toml`. No custom API generator or PHP lint script is maintained. CI runs the drift check for every pull request. When changing a route, DTO, validation limit, or response contract, update the PHP source first and commit the resulting generated file.

The NelmioApiDocBundle runs only in development and test environments. No interactive documentation UI is enabled on the public service.

## Containerized homologation

See [the private Docker homologation guide](homologation.md) for the pinned PHP-FPM and Nginx images, synthetic-only Compose secrets, IP rate limits, loopback routing, CI smoke test, and manual SHA-scoped deployment. The public Pricing repository does not configure a reverse proxy for internet exposure or integrate with the existing Nextcloud stack.

## Economic parameters and boundaries

Only explicitly artificial, non-homologated fixtures are accepted. An absent/invalid catalog yields a sanitized HTTP 503 response; test fixtures are never loaded implicitly.

Future catalogs with private economic information require a separately approved implementation, suitable access controls, role-specific output DTOs, and independent fiscal/economic validation. No current output may be treated as an authorized commercial offer.
