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
        $configured = $this->configuredToken();

        if ($configured === ''
            || !hash_equals('Bearer '.$configured, (string) $request->headers->get('Authorization'))) {
            throw new ApiProblem(401, 'unauthorized');
        }
    }

    private function configuredToken(): string
    {
        // A configured token file is authoritative. Do not fall back to an
        // environment token if a configured secret becomes unreadable.
        $path = $_SERVER['PRICING_API_TOKEN_FILE']
            ?? $_ENV['PRICING_API_TOKEN_FILE']
            ?? getenv('PRICING_API_TOKEN_FILE');

        if (is_string($path) && $path !== '') {
            $contents = @file_get_contents($path);

            return is_string($contents) ? rtrim($contents, "\r\n") : '';
        }

        $token = $_SERVER['PRICING_API_TOKEN']
            ?? $_ENV['PRICING_API_TOKEN']
            ?? getenv('PRICING_API_TOKEN');

        return is_string($token) ? $token : '';
    }
}
