# Simulations API v1

`POST /v1/simulations`, `Authorization: Bearer <token>`, `Content-Type: application/json`. No database, write API, billing, or WooCommerce integration.

Request:

```json
{"model":"managed","parameter_version":"synthetic-v1","usage":{"documents":100,"storage_gb":10,"service_hours":2},"candidate_price":"1025.00"}
```

`candidate_price` is optional, exact BRL decimal string with at most two places. Usage values must be nonnegative integers within documented limits; use zero documents and storage for `customer_infrastructure`. The response includes synthetic reference price, evaluated price, full/incremental costs, two synthetic tax sensitivities, cooperative residual illustration, provenance SHA256, deterministic fingerprint, formula explanation and `commercialization.quote_allowed=false`, `commercialization.activation_allowed=false`.

Errors: 400 malformed JSON, 401 missing/invalid bearer token, 413 oversize payload, 415 unsupported media type, 422 invalid or unknown inputs. Do not send this endpoint confidential economic data in the first increment.

Examples (tax scenario synthetic_a, all BRL):

| Model | Usage (docs / GB / hours) | Reference | Incremental contribution | Full-cost margin |
| --- | --- | --- | --- | --- |
| cloud | 100 / 10 / 2 | 195.00 | 99.30 | 79.30 |
| managed | 100 / 10 / 2 | 1025.00 | 484.50 | 304.50 |
| customer_infrastructure | 0 / 0 / 8 | 2400.00 | 716.00 | 436.00 |

Do not assume these figures are commercial offers, real costs, an approved cooperative allocation policy or current Brazilian tax law. A future WooCommerce bridge must reconcile existing offers before any authority is transferred.
