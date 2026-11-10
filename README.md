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

This repository provides a parameterized economic calculator for the three LibreSign modalities, including an external economic matrix, deterministic result fingerprints, and separately calculated one-time setup costs. The public fixtures are **entirely fictional**: none of their values represent LibreCode expenses, approved prices, or applicable tax rates.

A separate, disabled-by-default analyst API and private browser workbench support controlled economic validation. Real parameter files and analyst credentials are loaded **only from privately mounted host configuration**. The workbench never modifies configuration or enables WooCommerce billing, contract activation, or public price publication.

Economic results remain estimates pending governance and data homologation. No customer-facing pricing integration is active.

## Project documentation

- [Architecture and development](docs/development.md)
- [Economic matrix contract](docs/economic-matrix.md)
- [Economic simulation formulas](docs/economic-calculation.md)
- [Product-first commercial offer lab, source evidence and provisional credits](docs/commercial-offer-lab.md)
- [Private economic configuration and browser workbench](docs/private-economic-configuration.md)
- [Private Docker homologation and controlled deployment](docs/homologation.md)
- [Simulation API](docs/api.md)
- [Generated OpenAPI specification](docs/openapi.yaml)
- [Contributing](CONTRIBUTING.md)
- [Security reporting](SECURITY.md)
