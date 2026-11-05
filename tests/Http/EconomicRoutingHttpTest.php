<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Http;

use LibreSign\Pricing\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EconomicRoutingHttpTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testEconomicRoutesAreRegisteredAndDenyUnauthenticatedRequests(): void
    {
        $client = static::createClient();

        foreach (['/v1/economic-simulations', '/v1/economic-quotes'] as $path) {
            $client->request(
                'POST',
                $path,
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{}',
            );
            self::assertResponseStatusCodeSame(401);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        }
    }
}
