# LibreSign Pricing

Independent, **synthetic-only** Symfony pricing simulation API. It does not set WooCommerce prices or enable quotes.

## Quick start

Requires PHP 8.5.12+, Composer 2, and the extensions required by Symfony and PHPUnit. Install reproducibly with `composer install` after cloning. Set `APP_SECRET` and `PRICING_API_TOKEN` in the environment; do not commit secrets. For development, launch with `php -S localhost:8080 -t public`.

```sh
composer validate --strict
composer install --no-interaction
composer check
curl -X POST http://localhost:8080/v1/simulations \
  -H 'Authorization: Bearer <local-development-token>' \
  -H 'Content-Type: application/json' \
  -d '{"model":"cloud","parameter_version":"synthetic-v1","usage":{"documents":100,"storage_gb":10,"service_hours":2}}'
```

Modes: `cloud`, `managed`, `customer_infrastructure`. All amounts and rates are deliberately **invented**, in BRL, for exercising algorithms and tests. `candidate_price` is an optional decimal string for comparison only; no customer offer can be authorized.

For the API contract, see [docs/api.md](docs/api.md). Source is AGPL-3.0-or-later, with no confidential finance data in this repository.

## Commercial boundaries

The existing WordPress/WooCommerce subscriptions, storefront, and Nextcloud provisioning remain the authority for commercial transactions and launches. This engine has no databases, ledger, payment, provisioning, or checkout integration. A future controlled migration needs an audited reconciliation between existing WooCommerce offer IDs, currencies, recurring periods, tax configuration, and simulation input/model versions, as well as economic/legal sign-off. The API always returns `quote_allowed=false` and `activation_allowed=false`.

## Limitations

The first increment uses an environment-configured static bearer token, not consumer-specific authorization or rate limiting. It is not approved for exposure to the public internet. The tax rates and cooperative factor are synthetic sensitivities, not a legal or accounting determination.
