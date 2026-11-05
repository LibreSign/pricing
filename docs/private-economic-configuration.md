<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Private economic configuration and commercial authorization

The public Git repository and published Docker images **never include LibreCode's actual economic parameters**. Only fictional fixtures, schema documentation and the PHP formulas are versioned here.

## Installation boundary

The service starts with no configured economic matrix and no analyst/quote credentials: economic endpoints fail closed. To enable internal research, an authorized operator may supply the following paths in a **host-private Docker Compose override**, stored outside the repository:

- `PRICING_ECONOMIC_MATRIX_PATH`: read-only path inside the application container to a private, approved-for-access JSON configuration.
- `PRICING_ECONOMIC_ANALYST_TOKEN_FILE`: read-only credential file for internal analysis, distinct from the synthetic API token.
- `PRICING_ECONOMIC_QUOTES_TOKEN_FILE`: separate, read-only credential file for commercial quote consumers.
- `PRICING_ECONOMIC_QUOTES_ENABLED`: defaults to `0`. Deliberately setting `1` in the **private host configuration** enables a second gate, but never overrides unapproved parameters.

The host-private override must mount the economic matrix and credentials as **separate read-only secrets**; do not modify the published Compose file with real paths or add actual economic data to an image layer. Keep the pricing service on an isolated, non-public Docker network and do not expose new routes publicly without a separate security review.

Rotating credentials or a parameter snapshot is an operator-controlled action. Check the effective mounted file, source and SHA-256 before and after a change, and roll back by restoring the previous private snapshot. Do not log source paths, tokens, request bodies, cost results, or customer-identifying data.

## Endpoints

- `POST /v1/economic-simulations` (analyst token): calculates the full scenario, including economic costs, return targets and parameters' version/hash. This endpoint is **confidential**.
- `POST /v1/economic-quotes` (separate quotes token): returns separate one-time setup and service-period reference amounts, model, billing basis, parameter version and simulation fingerprint. It never returns costs, tax rates, return per hour or parameter values. An unapproved or incomplete matrix produces HTTP 409; an unset host enable flag produces HTTP 403. The explicit UTC approval date is included in the quote response and in the simulation fingerprint; analytical simulations do not silently depend on wall-clock time.
- `POST /v1/simulations`: legacy synthetic-only endpoint. Unchanged.

Both economic routes accept the same JSON request shape:

```json
{
  "model": "cloud",
  "parameter_version": "fictional-v2",
  "customers": 20,
  "candidate_price": "100.00"
}
```

The above is a **fictional example**. The `candidate_price` key is optional. No route provisions users, bills customers, or alters WooCommerce.

## Approval and limits

The private matrix records `approval_status`, `approved_by`, `approval_reference`, `effective_from`, `effective_until` and each parameter's approval status. The domain engine requires all inputs relevant to the selected scenario to be present, within validity and approved. The synthetic scope **cannot** authorize quotes.

These approval fields express **business evidence**, not a cryptographic signature. The private file must therefore be writable only by authorized financial governance and mounted read-only into the service; anyone allowed to edit it can change those assertions. Stronger approval attestation, per-client quotas and durable request audit may be added before internet-facing exposure. Until then, keep quote consumption private, per-role, explicitly enabled and independently reviewed.

A quote price is not a binding contract: `activation_allowed` is always `false`. Prices for project delivery and optional recurring support must be separated by the commercial adapter before sale.

## Private migration from previous research

Review the older `economic-parameters.private.review.json` **outside the public repository**. Convert its 39 `parameters` into one versioned matrix and normalize `customer_infra` to `customer_infrastructure`. Remove the duplicated `simulation_assumptions` and `scenario_results` source structures. Keep nulls for unverified quantities and update evidence and units explicitly. The nine fictional scenarios now have their own public test fixture.

No real parameter set, accountant working paper, confidential file path, or approval receipt must be committed. Economic homologation is a human-governed operation distinct from merging PHP code.

## Browser-based validation workbench

An optional, **internal-only** read-only interface is served by Symfony at `GET /internal/pricing-workbench`. The default `PRICING_WORKBENCH_ENABLED=0` returns HTTP 404 for the page and both local assets. An authorized infrastructure operator must deliberately set `PRICING_WORKBENCH_ENABLED=1` in the **private** deployment configuration and make the route reachable only on an authorized HTTPS/internal network.

The screen uses the same `POST /v1/economic-simulations` endpoint as programmatic consumers. `GET /v1/economic-metadata` accepts only the **analyst token** and returns the catalog version, SHA-256, scope, approval status and three model identifiers, not the underlying financial values. The browser sends the analyst token in an Authorization header, keeps it only in the password field during the open tab, and does not use browser storage or third-party scripts. Close/clear the tab after testing.

### Private setup (no real economic values in Git)

1. Integrate and review the calculator PR and this UI PR; validate their CI on the final `main` revision.
2. Use the existing isolated private Docker Compose override. Make sure no host ports are published and no public proxy network is attached. Mount the private economic matrix read-only at a path such as `/run/secrets/pricing_economic_matrix`, and an **independent** analyst token file at a path such as `/run/secrets/pricing_economic_analyst_token`. The token must be at least 32 characters. Never place its bytes or the matrix contents into Compose YAML, environment strings, the repository, container images, CI logs or support tickets.
3. Set `PRICING_ECONOMIC_MATRIX_PATH` and `PRICING_ECONOMIC_ANALYST_TOKEN_FILE` to those in-container secret paths. Set `PRICING_WORKBENCH_ENABLED=1` **only** in the host-private override. Keep `PRICING_ECONOMIC_QUOTES_ENABLED=0` and do not enable any WooCommerce activation or billing integration.
4. Open the internal workbench via the approved private HTTPS endpoint at `/internal/pricing-workbench`. Enter the analyst token and select **Carregar configuração**. The server responds with the current parameter version and scope. If the page is 404, verify the private enable flag and network route.
5. Select Cloud, Managed or infrastructure do cliente; enter the number of paying customers and optionally a candidate price; select **Calcular cenário**. Compare the full-cost, incremental and preservation results, plus one-time setup. Check the matrix SHA-256 and simulation fingerprint when editing the private JSON.
6. Validate unknown parameters (`null`), negative/invalid inputs, mismatched units, capacity limits and behavior with an expired/not approved snapshot. All quotes/activation must remain blocked when parameters or approvals are missing. To change costs, update only the private JSON, create a new catalog version and repeat the simulation.

Use `tests/Fixtures/economic-historical-synthetic.json` when demonstrating the workbench **without any actual economic data**. This file is intentionally fictional, reproducing three volume scenarios per modality in unit tests. The production image excludes test fixtures, so mount a private copy explicitly when testing with them.

The workbench has no parameter editor, import of confidential spreadsheets, history database or price publication action. Its session comparison table exists only in memory. In a private real-economics trial, each analyst is responsible for handling and clearing results; avoid browser extensions or screen sharing that could disclose those values. Never use an internet-facing endpoint as a substitute for internal authentication.
