<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Economic simulation formulas

The calculator is a side-effect-free domain service. It **does not** replace the cooperative production allocator, a tax opinion, the WooCommerce commercial catalog, or the signature metering ledger.

All values are read from the external, versioned economic matrix as exact decimal strings. The `simulation.${model}.*` keys identify fixed period costs, variable per-paying-customer costs, incremental work hours and hourly economic benchmarks. No numerical economic assumptions are embedded in PHP.

For `customers = N`, `fixed_pool = F`, `variable_unit = V`, `hours_unit = h`, `full_hour = C`, `collective_hour = Q`, `tax = t`, `default = d`, and `margin = m`:

- Cash cost per customer: `F / N + V`.
- Full-cost basis: `cash_cost + h * C`.
- Cooperative preservation basis: `cash_cost + h * Q`.
- Incremental floor: `V / (1 - t - d)`. This is not an approved minimum selling price.
- Full-cost price at target margin: `full_cost / (1 - t - d - m)`.
- Cooperative preservation price at target margin: `preservation_cost / (1 - t - d - m)`.

**Interpretation:** the scenario's `collective_hour` is the fully loaded target return per weighted work hour, not an additional salary expense. It replaces `full_hour` for the preservation basis; it is not added to the full-cost basis. The actual collective return indicator (`D / H`) from cooperative accounting should inform the benchmark when appropriately reviewed. Contract scope, fixed-versus-incremental capacity, reserves and special production allocations can require different inputs. The formulas express a commercial sensitivity analysis, not the legal allocation of production.

The margin `m` is defined here on gross revenue, net of taxes and expected default, by reducing the denominator. This convention must match the approved commercial policy before any real quoting.

All outputs are rounded HALF_UP at two decimal places. Intermediate divisions retain twelve decimals. A zero or negative denominator, excess capacity, incompatible units or unknown input fails closed. Unknown economics produces `status=incomplete`, not a zero.

The nine historical research scenarios (three customer volumes for each modality) are reproduced with a **synthetic-only fixture**. Their values were illustrative, not observed LibreCode economics.

The `customer_infrastructure` scenario has a project-plus-optional-recurring contract basis. A later approved quote must separately express one-time setup and recurring support rather than mislabeling a project fee as a monthly subscription.
