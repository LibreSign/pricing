# Contributing

Use Conventional Commits with DCO `Signed-off-by` trailers. Commits must also be cryptographically signed; review approvals and CI are required before merge. Run `composer check` (lint, format check, PHPStan, PHPUnit, dependency audit) and add HTTP/negative tests when changing the public API. Parameter fixtures must stay synthetic and never contain real customers, offers, economics, or credentials. Update docs when request/response contracts change.
