<!-- SPDX-FileCopyrightText: 2026 LibreSign contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Security

Do not report vulnerabilities or disclose confidential economics in public GitHub issues. Use the repository's GitHub Security Advisories private reporting when available, or an established private contact channel for LibreSign maintainers.

Do not commit customer data, operational costs, product price lists, credentials, or runtime parameter catalogs. `PRICING_CATALOG_PATH` must point to an access-controlled file outside the public repository; file paths and contents must not be returned in API errors.

The current API is a synthetic-only prototype with a bearer token and no per-role cost-field filtering. Docker homologation uses an isolated PHP network, loopback-only ingress, file-backed token secret and Nginx rate limits. These restrictions do **not** guarantee confidentiality against authorized scraping or distributed requests; never expose real unit costs or approved offers. See [homologation controls](docs/homologation.md).

Do not expose the service to the public internet or treat its outputs as authorized offers.
