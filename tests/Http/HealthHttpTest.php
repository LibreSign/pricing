<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Http;

use LibreSign\Pricing\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthHttpTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testLivenessContainsNoEconomicData(): void
    {
        $client = self::createClient();
        $client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'ok'],
            json_decode((string) $client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR),
        );
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testSimulationRouteStillRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('POST', '/v1/simulations', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(401);
    }
}
