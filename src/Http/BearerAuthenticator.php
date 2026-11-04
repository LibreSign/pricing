<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Http;

use Symfony\Component\HttpFoundation\Request;

final readonly class BearerAuthenticator
{
    public function assertAuthenticated(Request $request): void
    {
        $configured = $_SERVER['PRICING_API_TOKEN']
            ?? $_ENV['PRICING_API_TOKEN']
            ?? getenv('PRICING_API_TOKEN');

        if (!is_string($configured)
            || $configured === ''
            || !hash_equals('Bearer '.$configured, (string) $request->headers->get('Authorization'))) {
            throw new ApiProblem(401, 'unauthorized');
        }
    }
}
