# Contributing

Use Conventional Commits with DCO `Signed-off-by` trailers and cryptographically signed commits. Require CI and human review before merging.

Run `composer validate --strict` and `composer check`, including PHP lint, PHP-CS-Fixer, PHPStan, PHPUnit and Composer audit. Run REUSE, Actionlint, and Zizmor when changing repository policy or CI.

**Never commit real costs, margins, prices, customer contracts, tax calculations, `.env` files, or credential-bearing configuration.** The only permitted fixture is demonstrably artificial and belongs under `tests/Fixtures/`. Runtime catalog paths must be supplied as environment configuration and should reference a private, access-restricted file outside the repository.

Add HTTP and negative-path tests when modifying the API contract. Modify the schema and documentation in the same PR. Never turn off the commercial authorization guardrails by modifying the synthetic fixture.
