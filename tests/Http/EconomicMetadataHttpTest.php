<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Http;

use LibreSign\Pricing\Controller\EconomicMetadataController;
use LibreSign\Pricing\Http\EconomicBearerAuthenticator;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EconomicMetadataHttpTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testAnalystCanLoadMatrixVersionWithoutEconomicValues(): void
    {
        $controller = $this->controller();
        $request = $this->request('analyst-'.str_repeat('x', 33));
        $response = $controller($request);
        self::assertSame(200, $response->getStatusCode());
        $result = json_decode((string) $response->getContent(), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('fictional-v2', $result['parameters']['version']);
        self::assertSame('SYNTHETIC_ONLY', $result['parameters']['scope']);
        self::assertSame('BRL', $result['currency']);
        self::assertArrayHasKey('cloud', $result['models']);
        self::assertArrayHasKey('managed', $result['models']);
        self::assertArrayHasKey('customer_infrastructure', $result['models']);
        self::assertStringNotContainsString('fixed_pool', (string) $response->getContent());
        self::assertStringNotContainsString('collective_hour', (string) $response->getContent());
        self::assertStringNotContainsString('cost_per_', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testNoTokenOrQuoteTokenIsRejected(): void
    {
        $controller = $this->controller();
        foreach (['', 'quotes-'.str_repeat('y', 33)] as $token) {
            $request = $this->request($token);
            $response = $controller($request);
            self::assertSame(401, $response->getStatusCode());
            self::assertStringNotContainsString('fictional-v2', (string) $response->getContent());
        }
    }

    public function testUnavailableMatrixDoesNotLeakFilePath(): void
    {
        $controller = $this->controller('/nonexistent/private/economic-matrix.json');
        $response = $controller($this->request('analyst-'.str_repeat('x', 33)));
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('/nonexistent', (string) $response->getContent());
    }

    private function controller(?string $matrixPath = null): EconomicMetadataController
    {
        return new EconomicMetadataController(
            new EconomicMatrixLoader($matrixPath ?? dirname(__DIR__, 2).'/tests/Fixtures/economic-historical-synthetic.json'),
            new EconomicBearerAuthenticator(
                $this->tempFile('analyst-'.str_repeat('x', 33)),
                $this->tempFile('quotes-'.str_repeat('y', 33)),
            ),
        );
    }

    private function request(string $token): Request
    {
        return Request::create(
            '/v1/economic-metadata',
            'GET',
            [],
            [],
            [],
            $token === '' ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pricing-metadata-');
        self::assertNotFalse($path);
        $this->tempFiles[] = $path;
        file_put_contents($path, $contents);
        return $path;
    }
}
