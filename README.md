# LibreSign Pricing

A standalone Symfony service for **non-binding, synthetic pricing simulations**. It never writes WooCommerce prices, generates approved quotes, or enables commercial activation.

> **All quantities, rates, costs, prices, taxes, and cooperative factors in the test fixture are deliberately invented. They are not LibreCode internal economics, market quotations, or approved price lists.**

## Development

Requirements: PHP 8.5.12+, Composer 2, and the extensions required by Symfony and PHPUnit. Install from the committed lockfile:

```sh
composer validate --strict
composer install --no-interaction
composer check
```

The runtime reads parameters only from `PRICING_CATALOG_PATH`. **There is no default catalog.** If no path is supplied, the API rejects simulations with HTTP 503; it does not silently fall back to the test fixture.

To run a local demonstration only, point the variable explicitly to the fictional fixture in `tests/Fixtures/synthetic-v1.json`:

```sh
export APP_ENV=dev
export APP_SECRET=local-development-secret-only
export PRICING_API_TOKEN=local-demo-token
export PRICING_CATALOG_PATH="$(pwd)/tests/Fixtures/synthetic-v1.json"

php -S 127.0.0.1:8080 -t public
```

In a separate terminal:

```sh
curl -sS -X POST http://127.0.0.1:8080/v1/simulations \
  -H 'Authorization: Bearer local-demo-token' \
  -H 'Content-Type: application/json' \
  -d '{"model":"cloud","parameter_version":"synthetic-v1","usage":{"documents":100,"storage_gb":10,"service_hours":2}}'
```

The local fixture is for algorithm tests and demonstration only. Never use it to price a customer.

## Parameter separation

- **Public repository:** PHP source, schemas, documentation, and one explicitly artificial fixture under `tests/Fixtures/`. No operating prices or confidential cost parameters belong here.
- **Private environment:** the future authorized catalog must be managed outside Git and mounted/read with minimal filesystem privileges. Configure its absolute path via `PRICING_CATALOG_PATH`, not through a tracked PHP or JSON file. Do not commit `.env`, real datasets, invoices, or contracts.
- **Current guardrail:** this first increment accepts **only catalogs explicitly marked as fictional, synthetic, and not homologated**. Changing files or environment variables cannot authorize commercial use. Support for homologated catalogs and access control will require separately reviewed implementation and approvals.
- **Reproducibility:** results include the parameter version, SHA-256 of the loaded bytes, and deterministic simulation fingerprint. Those hashes are provenance aids, **not confidentiality controls**.

Run `composer check` after changes. PHPUnit uses a synthetic fixture through `phpunit.xml.dist` and must not depend on private financial data.

## Commercial boundaries

WordPress/WooCommerce, the current storefront, and Nextcloud provisioning remain unaffected and authoritative for existing subscriptions. The simulation engine has no database, ledger, payment, provision, or checkout integration.

Before any transfer of pricing authority, independently reconcile WooCommerce offers, billing periods, currencies, tax configuration, model versions, and economic approvals. Even when a candidate price is supplied, the API responds with `quote_allowed=false` and `activation_allowed=false`.

See [API documentation](docs/api.md) and [OpenAPI](docs/openapi.yaml) for the request and response contracts.

## Security limitations

A single environment-configured bearer token protects this prototype, with no tenant-specific roles, rate limiting, or approved exposure of internal cost breakdowns. **Do not deploy it as an internet-facing service or grant third parties access to simulations containing confidential economics.** The current tax and cooperative calculations are illustrative mathematical scenarios, not accounting or legal conclusions.

License: AGPL-3.0-or-later.
