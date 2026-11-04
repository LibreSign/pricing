<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Http\ApiProblem;
use LibreSign\Pricing\Http\BearerAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BearerAuthenticatorTest extends TestCase
{
    public function testSecretFileTakesPriorityOverEnvironmentToken(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pricing-secret-');
        self::assertNotFalse($file);

        $previous = $_SERVER['PRICING_API_TOKEN_FILE'] ?? null;
        file_put_contents($file, "secret-from-file\n");

        try {
            $_SERVER['PRICING_API_TOKEN_FILE'] = $file;
            $authenticator = new BearerAuthenticator();
            $authorized = Request::create('/v1/simulations', 'POST', server: [
                'HTTP_AUTHORIZATION' => 'Bearer secret-from-file',
            ]);
            $authenticator->assertAuthenticated($authorized);

            $this->expectException(ApiProblem::class);
            $fallback = Request::create('/v1/simulations', 'POST', server: [
                'HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token',
            ]);
            $authenticator->assertAuthenticated($fallback);
        } finally {
            $this->restore($previous);
            unlink($file);
        }
    }

    public function testUnreadableSecretNeverFallsBack(): void
    {
        $previous = $_SERVER['PRICING_API_TOKEN_FILE'] ?? null;
        try {
            $_SERVER['PRICING_API_TOKEN_FILE'] = '/nonexistent/pricing-token';
            $this->expectException(ApiProblem::class);
            (new BearerAuthenticator())->assertAuthenticated(
                Request::create('/v1/simulations', 'POST', server: [
                    'HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token',
                ]),
            );
        } finally {
            $this->restore($previous);
        }
    }

    private function restore(?string $previous): void
    {
        if ($previous === null) {
            unset($_SERVER['PRICING_API_TOKEN_FILE']);
        } else {
            $_SERVER['PRICING_API_TOKEN_FILE'] = $previous;
        }
    }
}
