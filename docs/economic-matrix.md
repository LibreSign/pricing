<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Versioned economic parameter matrix

The economic matrix is a **private configuration file**, never an application source file or an image build input. Its path is explicitly provided at runtime. The public repository includes only `tests/Fixtures/economic-synthetic-v1.json`, containing fictional values.

## Contract

`schema_version: "2.0.0"`, `contract_id: "libresign.pricing.economic-parameters"`, `parameter_set_id`, `parameter_set_version`, `scope`, `approval_status`, `currency: "BRL"`, `models`, and `parameters` are mandatory. `models` has exactly the three delivery modalities. Each entry of the `parameters` list contains a unique stable ID, a decimal **string or null**, an explicit unit, a source reference, and `approval_status`.

Only `parameters` is the numerical source of truth. The earlier private research contract (schema 1.0.0) included duplicate `simulation_assumptions` and `scenario_results`; these are **not accepted** by the new contract. Migrate values and provenance into the parameter list privately. Recompute scenarios; do not carry over their calculated output as configuration.

Unknown (`null`) means the value has not been measured. It is never coerced to zero. Binary floating point numbers, duplicates, missing units and invalid versions fail validation.

`scope: "SYNTHETIC_ONLY"` and `approval_status: "research_only"` are appropriate for public fictional fixtures. `scope: "PRIVATE_ECONOMICS"` can only be selected from a **private, explicitly mounted file**. Commercial authorization additionally requires the catalog status, evidence and validity interval, and approval of every referenced parameter; approval is not inferred from the file's location.

The PHP engine defines formulas and known parameter IDs, not prices or economic assumptions. Values can be revised by distributing a new private, reviewed version, without changing the code. A SHA-256 digest identifies the exact bytes used for any result.

No actual costs, rates, client names, source paths, secrets, or accounting datasets belong in public fixtures, GitHub PRs, logs or Docker images. Catalog review and approval are distinct from deploying a new PHP revision.
