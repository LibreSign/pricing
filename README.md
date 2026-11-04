<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# LibreSign Pricing

**Explainable pricing simulations for LibreSign's commercial models.**

LibreSign Pricing is an independent engine intended to help evaluate the economic sustainability of LibreSign services. It models alternative offers, compares incremental contribution with full-cost recovery, and makes assumptions and results traceable.

## Business scope

| Model | Purpose |
| --- | --- |
| **LibreSign Cloud** | Evaluate standardized, shared hosting and usage assumptions. |
| **LibreSign Managed** | Evaluate managed capacity, service commitments, and operating costs. |
| **LibreSign on customer infrastructure** | Evaluate deployment, integration, consulting, and optional ongoing support. |

The engine distinguishes costs that change with additional business from allocations needed to recover the full cost of operations. It also illustrates tax sensitivities and potential cooperative residuals. These are **simulation outputs**, not automatically approved prices or accounting conclusions.

## Economic governance

- **Explainability:** each result records the applied parameter version and model, formulas, and a reproducible input fingerprint.
- **Confidentiality:** operational economic parameters belong in private, access-controlled environments, not public source control.
- **Separation of responsibilities:** WooCommerce remains responsible for existing products, subscriptions, and billing; this engine does not provision accounts or issue invoices.
- **Approval boundaries:** a simulation does not authorize a quote, deployment, contract, price change, or tax treatment.

## Current maturity

This repository contains an **early technical increment with deliberately fictional test data only**. None of the numbers included in its fixtures represent LibreCode internal costs, approved market offers, or current Brazilian tax obligations. The engine refuses to load a catalog implicitly and cannot authorize commercialization.

No customer-facing pricing integration is active. Access to sensitive calculations must remain restricted until roles, confidentiality controls, independently verified economics, and approval workflows have been implemented and reviewed.

## Project documentation

- [Architecture and development](docs/development.md)
- [Private Docker homologation and controlled deployment](docs/homologation.md)
- [Simulation API](docs/api.md)
- [Generated OpenAPI specification](docs/openapi.yaml)
- [Contributing](CONTRIBUTING.md)
- [Security reporting](SECURITY.md)
