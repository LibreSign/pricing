# Security

Do not report vulnerabilities or disclose confidential economics in public GitHub issues. Use the repository's GitHub Security Advisories private reporting when available, or an established private contact channel for LibreSign maintainers.

Do not commit customer data, operational costs, product price lists, credentials, or runtime parameter catalogs. `PRICING_CATALOG_PATH` must point to an access-controlled file outside the public repository; file paths and contents must not be returned in API errors.

The current API is a synthetic-only prototype with a single bearer token and no per-role cost-field filtering. Do not expose it to the public internet or treat its outputs as authorized offers.
