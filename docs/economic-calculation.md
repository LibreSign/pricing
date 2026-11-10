<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Economic simulation formulas

The calculator is a side-effect-free domain service. It **does not** replace the cooperative production allocator, a tax opinion, the WooCommerce commercial catalog, or the signature metering ledger.

All values are read from the external, versioned economic matrix as exact decimal strings. The `simulation.${model}.*` keys identify fixed period costs, variable per-paying-customer costs, incremental work hours and hourly economic benchmarks. No numerical economic assumptions are embedded in PHP.

For `customers = N`, `fixed_pool = F`, `variable_unit = V`, `hours_unit = h`, `full_hour = C`, `collective_hour = Q`, `tax = t`, `default = d`, and `margin = m`:

- Cash cost per customer: `F / N + V`.
- Full-cost basis: `cash_cost + h * C`.
- Cooperative preservation basis: `cash_cost + h * Q`.
- One-time setup/implementation price (separate from maintenance): `setup_hours * Q / (1 - t - d - m)`.
- Incremental floor: `V / (1 - t - d)`. This is not an approved minimum selling price.
- Full-cost price at target margin: `full_cost / (1 - t - d - m)`.
- Cooperative preservation price at target margin: `preservation_cost / (1 - t - d - m)`.

**Interpretation:** the scenario's `collective_hour` is the fully loaded target return per weighted work hour, not an additional salary expense. It replaces `full_hour` for the preservation basis; it is not added to the full-cost basis. The actual collective return indicator (`D / H`) from cooperative accounting should inform the benchmark when appropriately reviewed. Contract scope, fixed-versus-incremental capacity, reserves and special production allocations can require different inputs. The formulas express a commercial sensitivity analysis, not the legal allocation of production.

The margin `m` is defined here on gross revenue, net of taxes and expected default, by reducing the denominator. This convention must match the approved commercial policy before any real quoting.

All outputs are rounded HALF_UP at two decimal places. Intermediate divisions retain twelve decimals. A zero or negative denominator, excess capacity, incompatible units or unknown input fails closed. Unknown economics produces `status=incomplete`, not a zero.

The nine historical research scenarios (three customer volumes for each modality) are reproduced with a **synthetic-only fixture**. Their values were illustrative, not observed LibreCode economics.

The `customer_infrastructure` scenario has a project-plus-optional-recurring contract basis. Its setup price is **always calculated and reported separately** from optional recurring support; do not mislabel either amount as the other.


## Configurable operational scenario (engine 1.1)

The analyst may supply an optional `scenario` object in addition to model, contract-equivalent count and candidate price. The economic API remains read-only. The endpoint `GET /v1/economic-metadata` provides each model's allowed operational fields, their types, limits and default values, without returning money coefficients.

- Cloud and Managed: monthly document count, users, storage GB, additional support hours, backup, TSA, dedicated infrastructure and priority support. Managed additionally supports training and supplemental deployment hours. Optional migration and integration hours are one-time in both.
- Customer infrastructure: optional recurring support, additional support hours, priority support, migration, integrations, training and deployment hours. With recurring support disabled, recurring prices and candidate-price comparisons are `null`, never a zero-priced subscription.
- `candidate_setup_price` tests a one-time setup amount independently of `candidate_price`, which tests a recurring amount.

If selected, economic drivers are resolved against the active matrix: `document_unit` (BRL/document), `user_unit` (BRL/user_period), `storage_gb_unit` and `backup_gb_unit` (BRL/GB_period), `tsa_document_unit` (BRL/document), `dedicated_period` (BRL/period), `priority_hours` (hours/customer_period), `integration_setup_hours` (hour/integration) and `migration_setup_hours` (hour/GB). These keys have a prefix of `simulation.<model>.`. Existing base cost and margin fields are unchanged. All numerical rate values are defined exclusively in the external matrix.

**Recurring variable cost** equals the model's base `variable_unit` plus enabled usage drivers. **Total recurrent work hours** equal base `hours_unit`, additional analyst-selected `support_hours` and `priority_hours` when enabled. The recurring cash, full-cost and collective-preservation bases then use the existing formulas with these quantities. The incremental illustration includes added variable usage and incremental support at the full-hour benchmark; it excludes shared fixed allocation.

**One-time setup work hours** equal base `setup_hours` plus the integration count multiplied by `integration_setup_hours`, migrated GB multiplied by `migration_setup_hours`, training hours and supplemental deployment hours. The setup amount uses the cooperative benchmark and the same denominator but is calculated **independently** from recurring charges.

The model never equates an unlisted coefficient to zero if its driver is enabled. Missing or null selected coefficients produce `status=incomplete` and list the missing IDs; incompatible units fail closed. Operational quantities and toggles are validated server-side and included in the deterministic input fingerprint. The public sample matrices include fictional coefficients for browser exercises; they are not commercial estimates.

The contract's `customers` count represents equivalent active contracts for allocating the fixed cost pool, not users or tenant accounts. It must not be confused with `scenario.users`. Revenue-recognition, period consistency, reserve allocations and the real effective rates still require a reviewed private methodology before any quotation.
