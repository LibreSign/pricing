<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Http;

use Symfony\Component\HttpFoundation\Request;

final readonly class EconomicBearerAuthenticator
{
    public function __construct(
        private string $analystTokenFile,
        private string $quotesTokenFile,
    ) {
    }

    public function assertAnalyst(Request $request): void
    {
        $this->assertRole($request, $this->analystTokenFile);
    }

    public function assertQuotes(Request $request): void
    {
        $this->assertRole($request, $this->quotesTokenFile);
    }

    private function assertRole(Request $request, string $path): void
    {
        // File-only credentials; there is no environment token fallback.
        if ($path === '') {
            throw new ApiProblem(401, 'unauthorized');
        }
        $configured = @file_get_contents($path);
        $configured = is_string($configured) ? rtrim($configured, "\r\n") : '';
        if (strlen($configured) < 32
            || !hash_equals('Bearer '.$configured, (string) $request->headers->get('Authorization'))) {
            throw new ApiProblem(401, 'unauthorized');
        }
    }
}
