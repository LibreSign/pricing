<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Http;

use LibreSign\Pricing\Controller\EconomicMetadataController;
use LibreSign\Pricing\Controller\OfferParametersController;
use LibreSign\Pricing\Http\EconomicBearerAuthenticator;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class OfferParametersHttpTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function secret(string $body): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pricing-controls-');
        self::assertNotFalse($file);
        file_put_contents($file, $body);
        $this->files[] = $file;
        return $file;
    }

    /** @return array{0: OfferParametersController, 1: EconomicMetadataController} */
    private function controllers(): array
    {
        $auth = new EconomicBearerAuthenticator(
            $this->secret('analyst-'.str_repeat('a', 35)),
            $this->secret('quotes-'.str_repeat('b', 35)),
        );
        $loader = new EconomicMatrixLoader(dirname(__DIR__).'/Fixtures/economic-historical-synthetic.json');
        return [new OfferParametersController($loader, $auth), new EconomicMetadataController($loader, $auth)];
    }

    private static function request(string $route, string $token): Request
    {
        return Request::create($route, 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
    }

    public function testOnlyAnalystCanReadFinancialCoefficients(): void
    {
        [$private] = $this->controllers();
        self::assertSame(401, $private(self::request('/v1/offer-parameters', 'invalid'))->getStatusCode());
        self::assertSame(401, $private(self::request('/v1/offer-parameters', 'quotes-'.str_repeat('b', 35)))->getStatusCode());
        $response = $private(self::request('/v1/offer-parameters', 'analyst-'.str_repeat('a', 35)));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $data = json_decode((string) $response->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertCount(5, $data['credit_factors']);
        self::assertSame('simulation.managed.collective_hour', $data['economic_controls'][15]['id']);
        self::assertSame('90.00', $data['economic_controls'][15]['value']);
        self::assertSame('SYNTHETIC_ONLY', $data['parameters']['scope']);
    }

    public function testMetadataStillDoesNotExposePrivateMonetaryParameters(): void
    {
        [, $public] = $this->controllers();
        $response = $public(self::request('/v1/economic-metadata', 'analyst-'.str_repeat('a', 35)));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('economic_controls', $data);
        self::assertArrayNotHasKey('credit_factors', $data);
        self::assertStringNotContainsString('collective_hour', (string) $response->getContent());
    }
}
