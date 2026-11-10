<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Commercial offer laboratory (research only)

The internal workbench `/internal/pricing-workbench` is a **product-first, non-commercial pricing laboratory**. It does not approve an offer, open a checkout, create customer entitlements, meter credits, issue an invoice, or modify WooCommerce. The previous model-by-model technical calculator remains available to analysts under `/internal/pricing-workbench/technical`.

## Research hypothesis and evidence

The user starts by choosing **Cloud**, **Managed**, or **customer infrastructure**. All financial coefficient values come from one explicitly mounted, versioned economic JSON matrix. All other quantities (forecast customer count, candidate prices, support hours, annual discount, consumption and top-up assumptions) are declared in the request and returned in the simulation trace. Neither PHP nor JavaScript embed monetary assumptions. Every internal result lists each coefficient's ID, unit, value, origin and approval status; these values are **analyst-only**.

The fictional fixtures include deliberately unrealistic sample values under the `offer.*` namespace. They are not evidence of actual infrastructure costs, marketing conversion rates, payment-provider fees, tax rates or an approved price. Missing selected cost coefficients fail with `status=incomplete`; the system must not substitute zero.

### Cloud self-service

The commercial hypothesis is a **fixed subscription plus a non-cumulative monthly allowance**. Cloud includes no per-customer human setup and no human support hours. It nevertheless includes shared platform operations, infrastructure, variable consumption, processing fees, taxes and forecast acquisition costs.

At `N` simulated contracts, `U` expected credits actually delivered per contract and with two independent capacity constraints:

- `shared_nodes = max(ceil(N / contracts_per_node), ceil(N × U / monthly_credits_per_node))`.
- `hosting_cost = shared_nodes × node_monthly_cost`.
- `monthly_platform_cost = N × platform_per_contract + shared_operations`.
- `monthly_consumption_cost = N × U × unit_credit_cost`.
- `monthly_acquisition_cost = N × acquisition_rate × acquisition_cost_per_new_contract`.

The source of truth must eventually be measured system resource consumption and a controlled allocation of shared overhead. **Forecast contracts do not determine the published plan price.** They determine whether a candidate fixed price is economically sustainable. Sensitivity compares cohorts with the same package.

### Managed as a Cloud extension

Managed **reuses the platform and unit-consumption layers** of Cloud, but substitutes dedicated hosting for Cloud's shared hosting (never both). Per-contract hosting is the dedicated-node rate times nodes required by the same hypothetical monthly consumption. It additionally counts managed operations, regular updates and optionally contracted support hours. Setup is separate from monthly charges.

### Customer-hosted / on-premise

The customer's own hosting costs are outside the LibreCode service charge. The lab distinguishes project installation, monthly coordination/support and extra remote-management hours only when explicit infrastructure access is authorized. Analysts can switch **installation off** to price a support-only or maintenance-only contract on an already-running installation; the installation cost then disappears. With remote access authorized, initial access provisioning may still be a separately charged one-time service. Analysts can instead switch recurring maintenance **off** to price installation-only; monthly/annual subscriptions then disappear. The model does not assume remote access or outsourced updates are always present.

## Allowance, recharges and financial projections

The candidate charging unit remains **provisional**, pending metering, idempotency and contract evidence. Earlier research considered a completed *signer-document obligation*, not the envelope container, to avoid double charging. Signing attempts, file uploads, notifications and TSA calls are not automatically a billable unit.

- Monthly plan: fixed candidate BRL price and included capacity. **Unused monthly allowance expires** at cycle-end in this research model; no rollover.
- A pack of `topup_credits` is purchased at an independently declared price. `take_rate` is the modeled proportion of contracts authorizing payment for packs. It is not an instruction to charge them automatically.
- Demand beyond the allowance is **blocked unless an authorized pack is purchased**. For each contract, modeled service consumption is `min(demand, allowance) + take_rate × max(0, demand − allowance)`. The expected count of purchased packs is `take_rate × ceil(excess_demand / pack_size)`. This is an aggregate expectation, not a wallet or ledger.
- The minimum economic recharge price is modeled separately as `(credits_per_pack × unit_credit_cost + payment_fixed_fee) / (1 − taxes − default_risk − variable_payment_fee − target_margin)`. Recharge income is never assumed to be certain.
- Price floor **does not rely on uncertain top-up revenue**. The full-allowance risk case recomputes infrastructure sizing **as well as unit costs**, assuming every subscriber exercises the included capacity.
- Recurring package floor at target gross margin: `(fixed_operating + base_consumption_cost + customer_acquisition + fixed_payment_fees) / N / (1 − taxes − default_risk − variable_payment_rate − target_margin)`.
- Annual: `candidate_annual_subscription = 12 × candidate_monthly × (1 − annual_discount)`. Annual equivalent monthly revenue recognizes that amount over 12 months; the fixed payment fee for the yearly subscription is divided by 12. Future usage-based pack purchases are shown separately, not advertised as guaranteed annual revenue.
- Setup: `(setup_hours × collective_hour + fixed_payment_fee) / (1 − taxes − default_risk − variable_payment_rate − target_margin)`. Cloud automated setup is not charged as a manual implementation project.

Outputs distinguish a **cost-derived floor**, the **unapproved candidate selling price**, monthly and annual contract amounts, a profit/sensitivity estimate and the evidence for every coefficient. Do not call the floor an approved market price: competition, value, CAC, willingness to pay and service-level commitments require their own business review. Sales activation remains explicitly blocked.

## Internal API

`POST /v1/offer-simulations`, analyst bearer token required. The request is research-only:

```json
{
  "model": "cloud",
  "parameter_version": "fictional-v2",
  "accounts": 100,
  "credits_per_account": 100,
  "included_credits": 100,
  "topup_credits": 50,
  "topup_take_rate": "0",
  "annual_discount": "0.10",
  "acquisition_rate": "0.10",
  "candidate_monthly_price": "39.00",
  "candidate_topup_price": "10.00",
  "candidate_setup_price": null,
  "support_hours": "0",
  "setup_extra_hours": "0",
  "remote_access": false,
  "recurring_maintenance": true,
  "include_setup": true
}
```

All values in this example are fictional. For Managed set `model: managed`, contracted `support_hours`, and optional `candidate_setup_price`. For customer infrastructure set `model: customer_infrastructure`, `credits_per_account: 0`, `included_credits: 0`, `topup_credits: 1`, `topup_take_rate: "0"`, and explicit `remote_access` / `recurring_maintenance` / `include_setup`. If maintenance is off, `candidate_monthly_price` must be null.

The response is cost-confidential, never fit for customers or WooCommerce consumers. The independent `/v1/economic-quotes` remains the only gate for an approved quote, and the new research API is **not** connected to that gate.

## Preconditions for a real approved price

A future `PRIVATE_ECONOMICS` matrix needs verifiable source periods and costing for: shared compute and peak capacity, database, file storage and retention, bandwidth, backup and restore, monitoring, updates, security, TSA and notifications, payment provider, taxes and chargebacks, client acquisition and conversion, plus cooperative labor and reserve allocations. Performance benchmarks must quantify throughput **per unit**. A realistic sales/consumption cohort is needed for risk scenarios. All pricing conditions and the actual metering unit require approval before exposing a consumer-facing catalog.

A future entitlement/credit ledger must protect idempotency, initial reservation, consistency between document and signer events, refunds, expired allowances, authorized reloads, balances, corrections and reconciliation with WooCommerce payments. The pricing calculator alone must not be treated as that ledger.


## Interactive resource-to-credit scenarios (research-only extension)

The analyst may select individual **signer-document completion obligations**, enabled users per month, average storage GB-month, API calls per month, and TSA-stamped documents per month. These are distinct operational drivers, not five separately approved billable metrics. For a research-only convertible unit, the server computes:

```
exact_credit_demand = completed_signer_document_obligations * obligation_weight
                    + enabled_users * user_month_weight
                    + average_storage_GB_month * storage_weight
                    + API_calls * api_call_weight
                    + TSA_documents * TSA_weight
credit_demand = ceil(exact_credit_demand)
remaining_allowance = included_credits - credit_demand
```

The fictional matrix defines these `offer.credit_weight.*` weights with explicit units and provenance; the client can adjust each weight in a simulation without modifying the file. The actual contractual credit formula is **not yet approved**. Slider motion updates the allowance, remaining balance, excess and per-resource contribution instantly in the browser. After interaction settles, the browser sends the complete exact decimal factors and quantities to the authenticated API and refreshes the financial result from the **server-owned** algorithm. A stale result is never deliberately relabeled as current. The separate `resource_usage` request field replaces the legacy aggregate `credits_per_account` when present (set it to zero); the server rejects an incomplete list of usage drivers, unknown units, unsuitable model or excessive credits.

Sample fictitious initial values: 55 completed obligations, 5 users, 20 GB-month, 1,000 API calls and 10 TSA documents with respective fictional weights 1, 2, 0.5, 0.010 and 1.5. They total 100 **hypothetical** monthly credits, not a calibrated actual package. The monthly allowance and payment prices can also be adjusted with sliders. Credit consumption does not produce invoice or debit events.

For economical experimentation, the browser offers sliders and exact input fields for monthly package price, annual discount, top-up price, growth, contract equivalents, support/setup hours and restricted internal coefficients (hourly cost benchmark, infrastructure size/cost, processing/tax/margin and acquisition) where relevant. These are *overrides specific to one research request*, not catalog writes or authorized selling prices. The simulation fingerprint includes both normal inputs and overrides. Each overridden coefficient is marked `ANALYST_SCENARIO_OVERRIDE` in the response, while the matrix and its original SHA-256 remain unchanged.

The financial result makes the following distinction explicit for setup:

```
direct_labor_cost = setup_hours * collective_hour_cost
reference_setup_price = (direct_labor_cost + fixed_payment_fee)
                        / (1 - tax_fraction - expected_default_fraction
                             - variable_payment_fraction - target_margin_fraction)
```

For example, the **invented** Managed fixture contains 16 hours at R$ 90.00 per hour, giving R$ 1,440.00 labor cost. At the fixture's tax 6%, expected default 2%, variable processor 3.5%, target margin 15% and R$ 0.80 payment fee, the model produces an estimated **R$ 1,960.27 selling-price floor**, not an R$ 1,960.27 labor cost. That R$ 90/hour **cannot be assumed to equal LibreCode's commercial billing rate or actual cooperative cost**.

### Confidential API boundaries

`GET /v1/economic-metadata` stays limited to nonfinancial model and field metadata; even the analyst bearer cannot obtain economic figures there. The separate `GET /v1/offer-parameters` is **analyst-only**, returns the parameter-matrix version, credit weights and research cost coefficients without opening them to a public pricing page. `POST /v1/offer-simulations` remains analyst-only and accepts optional `resource_usage`, `credit_weights` and `economic_overrides`. The UI preview is disabled by default and loopback-bound in the local Compose environment. No monetary coefficient, private source or bearer token is embedded in public JS or CSS.

The economic assumptions used here are deliberately fictitious. For final tariffs, measure technical unit consumption and reconcile the economic matrix against verified provider invoices, operational hours, taxes, payment processor, marketing cost and cooperative policy. Never publish a tariff or activate a payment based on these samples.
