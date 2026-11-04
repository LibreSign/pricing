# Pricing simulations API (v1)

`POST /v1/simulations` requires `Authorization: Bearer <token>` and `Content-Type: application/json`. It has no database, write API, billing, checkout, or WooCommerce integration.

## Example request (purely fictional)

```json
{
    "model": "managed",
    "parameter_version": "synthetic-v1",
    "usage": {
        "documents": 100,
        "storage_gb": 10,
        "service_hours": 2
    },
    "candidate_price": "25.00"
}
```

**Every numeric example is artificial** and must not be mistaken for LibreCode costs, market quotations, or approved prices. `candidate_price` is optional, expressed as an exact BRL decimal string (at most two fractional digits), and compares an input with the fictional catalogue; it does not authorize a commercial proposal.

The `usage` quantities are nonnegative integers within the limits described by [OpenAPI](openapi.yaml). For `customer_infrastructure`, document and storage usage must be zero. Set `parameter_version` equal to the version of the explicitly configured external catalogue.

## Configuration and privacy

The server uses `PRICING_CATALOG_PATH` to locate a parameter file **outside source control**. It does not search for a default catalogue and never loads `tests/Fixtures/` without explicit configuration. The only public numerical dataset is a deliberately unrealistic test fixture under `tests/Fixtures/synthetic-v1.json`. Current catalogue validation requires artificial data, `homologated=false`, and `status=SYNTHETIC_ONLY`.

A successful response contains the fictional reference price, evaluated candidate, cost calculations, two illustrative tax scenarios, cooperative residual illustration, version and SHA-256, formulas, and explicit `parameters.example_only=true`. It always includes `commercialization.quote_allowed=false` and `commercialization.activation_allowed=false`. Any future real-economics integration needs separate authorization, authenticated roles, and review of which cost fields are visible to which consumers.

## Errors

- HTTP 400: malformed JSON
- HTTP 401: missing or incorrect bearer token
- HTTP 413: request exceeds maximum size
- HTTP 415: unsupported media type
- HTTP 422: invalid input or unknown parameter version
- HTTP 503: configured catalogue missing or invalid, without filesystem path in the response

Do not pass actual confidential economics to this prototype. Its only purpose is to validate the deterministic calculation contracts independently of the commercial launch.
