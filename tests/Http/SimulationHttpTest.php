<?php

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Http;

use LibreSign\Pricing\Controller\SimulationController;
use LibreSign\Pricing\Domain\SimulationEngine;
use LibreSign\Pricing\Infrastructure\SyntheticCatalog;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SimulationHttpTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return \LibreSign\Pricing\Kernel::class;
    }
    private static function body(): string
    {
        return json_encode(['model' => 'cloud','parameter_version' => 'synthetic-v1','usage' => ['documents' => 100,'storage_gb' => 10,'service_hours' => 2]], JSON_THROW_ON_ERROR);
    }
    public function testAuthenticatedSimulationIsNeverCommercial(): void
    {
        $client = static::createClient();
        $client->request('POST', '/v1/simulations', server:['HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token', 'CONTENT_TYPE' => 'application/json'], content:self::body());
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('16.00', $payload['synthetic_list_price']);
        self::assertFalse($payload['commercialization']['quote_allowed']);
    }
    public function testMissingCatalogFailsClosedWithoutLeakingPath(): void
    {
        $unavailable = '/nonexistent/private/pricing-configuration.json';
        $controller = new SimulationController(new SimulationEngine(new SyntheticCatalog($unavailable)));
        $request = Request::create(
            '/v1/simulations',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token', 'CONTENT_TYPE' => 'application/json'],
            self::body(),
        );
        $response = $controller($request);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'pricing_catalog_unavailable'], json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($unavailable, (string) $response->getContent());
    }

    public function testUnauthorizedDenied(): void
    {
        static::createClient()->request('POST', '/v1/simulations', server:['CONTENT_TYPE' => 'application/json'], content:self::body());
        self::assertResponseStatusCodeSame(401);
    }
    public function testPublicationFlagDenied(): void
    {
        $client = static::createClient();
        $client->request('POST', '/v1/simulations', server:['HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token','CONTENT_TYPE' => 'application/json'], content:'{"model":"cloud","parameter_version":"synthetic-v1","usage":{"documents":0,"storage_gb":0,"service_hours":0},"publish":true}');
        self::assertResponseStatusCodeSame(422);
    }
    public function testMalformedJsonDenied(): void
    {
        static::createClient()->request('POST', '/v1/simulations', server:['HTTP_AUTHORIZATION' => 'Bearer test-only-synthetic-token','CONTENT_TYPE' => 'application/json'], content:'{INVALID');
        self::assertResponseStatusCodeSame(400);
    }
}
