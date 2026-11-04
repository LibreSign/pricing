<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Http;

use LibreSign\Pricing\Controller\SimulationController;
use LibreSign\Pricing\Domain\SimulationEngine;
use LibreSign\Pricing\Http\BearerAuthenticator;
use LibreSign\Pricing\Http\SimulationRequestParser;
use LibreSign\Pricing\Infrastructure\SyntheticCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SimulationHttpTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return \LibreSign\Pricing\Kernel::class;
    }

    public function testAuthenticatedSimulationIsNeverCommercial(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/v1/simulations',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: self::body(),
        );
        self::assertResponseIsSuccessful();

        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('16.00', $payload['synthetic_list_price']);
        self::assertSame('BRL', $payload['currency']);
        self::assertFalse($payload['commercialization']['quote_allowed']);
    }

    public function testMissingCatalogFailsClosedWithoutLeakingPath(): void
    {
        $unavailable = '/nonexistent/private/pricing-configuration.json';
        $controller = new SimulationController(
            new SimulationEngine(new SyntheticCatalog($unavailable)),
            new BearerAuthenticator(),
            new SimulationRequestParser(),
        );
        $request = Request::create(
            '/v1/simulations',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token',
                'CONTENT_TYPE' => 'application/json',
            ],
            self::body(),
        );

        $response = $controller($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['error' => 'pricing_catalog_unavailable'],
            json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString($unavailable, (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string, int}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'missing authorization' => [
            self::body(),
            ['CONTENT_TYPE' => 'application/json'],
            'unauthorized',
            401,
        ];
        yield 'unsupported content type' => [
            self::body(),
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token'],
            'content_type_not_supported',
            415,
        ];
        yield 'malformed json' => [
            '{INVALID',
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token'],
            'invalid_json',
            400,
        ];
        yield 'oversized json' => [
            str_repeat(' ', SimulationRequestParser::MAX_BODY_BYTES + 1),
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token'],
            'request_too_large',
            413,
        ];
        yield 'publication flag is not allowed' => [
            json_encode([
                'model' => 'cloud',
                'parameter_version' => 'synthetic-v1',
                'usage' => ['documents' => 0, 'storage_gb' => 0, 'service_hours' => 0],
                'publish' => true,
            ], JSON_THROW_ON_ERROR),
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token'],
            'invalid_input',
            422,
        ];
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequest(
        string $body,
        array $server,
        string $error,
        int $status,
    ): void {
        $client = static::createClient();
        $client->request('POST', '/v1/simulations', server: $server, content: $body);

        self::assertResponseStatusCodeSame($status);
        $result = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame($error, $result['error']);
        self::assertStringContainsString(
            'no-store',
            (string) $client->getResponse()->headers->get('Cache-Control'),
        );
    }

    private static function body(): string
    {
        return json_encode([
            'model' => 'cloud',
            'parameter_version' => 'synthetic-v1',
            'usage' => [
                'documents' => 100,
                'storage_gb' => 10,
                'service_hours' => 2,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
