<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Http;

use LibreSign\Pricing\Controller\EconomicSimulationController;
use LibreSign\Pricing\Domain\EconomicSimulationEngine;
use LibreSign\Pricing\Http\EconomicBearerAuthenticator;
use LibreSign\Pricing\Http\EconomicRequestParser;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EconomicSimulationHttpTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testAnalystReceivesDetailedSyntheticCalculation(): void
    {
        $controller = $this->controller('0');
        $response = $controller->simulate(self::request('/v1/economic-simulations', 'analyst-'.str_repeat('x', 33)));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('3810.00', $data['calculation']['full_cost']);
        self::assertFalse($data['commercialization']['quote_allowed']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testQuotesFailClosedForSyntheticParameters(): void
    {
        $controller = $this->controller('1');
        $response = $controller->quote(self::request('/v1/economic-quotes', 'quotes-'.str_repeat('y', 33)));
        self::assertSame(409, $response->getStatusCode());
        self::assertStringNotContainsString('full_cost', (string) $response->getContent());
        self::assertStringNotContainsString('tax_rate', (string) $response->getContent());
    }

    public function testQuotesDisabledWithoutHostApproval(): void
    {
        $controller = $this->controller('0');
        $response = $controller->quote(self::request('/v1/economic-quotes', 'quotes-'.str_repeat('y', 33)));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testRolesAreNotInterchangeable(): void
    {
        $controller = $this->controller('1');
        self::assertSame(401, $controller->simulate(self::request('/v1/economic-simulations', 'quotes-'.str_repeat('y', 33)))->getStatusCode());
        self::assertSame(401, $controller->quote(self::request('/v1/economic-quotes', 'analyst-'.str_repeat('x', 33)))->getStatusCode());
    }

    public function testUnconfiguredAuthenticationDeniesAccess(): void
    {
        $controller = new EconomicSimulationController(
            new EconomicSimulationEngine(new EconomicMatrixLoader(self::fixture())),
            new EconomicBearerAuthenticator('', ''),
            new EconomicRequestParser(),
            '1',
        );
        self::assertSame(401, $controller->simulate(self::request('/v1/economic-simulations', 'analyst-'.str_repeat('x', 33)))->getStatusCode());
    }

    public function testMissingMatrixIsSanitized(): void
    {
        $controller = $this->controller('1', '/nonexistent/private/economics.json');
        $response = $controller->simulate(self::request('/v1/economic-simulations', 'analyst-'.str_repeat('x', 33)));
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('/nonexistent', (string) $response->getContent());
    }

    public function testInvalidJsonIsRejected(): void
    {
        $controller = $this->controller('1');
        $request = Request::create(
            '/v1/economic-simulations',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer analyst-'.str_repeat('x', 33), 'CONTENT_TYPE' => 'application/json'],
            '{invalid',
        );
        self::assertSame(400, $controller->simulate($request)->getStatusCode());
    }

    public function testApprovedPrivateQuotesAreRedacted(): void
    {
        $data = json_decode((string) file_get_contents(self::fixture()), true, 32, JSON_THROW_ON_ERROR);
        $data['scope'] = 'PRIVATE_ECONOMICS';
        $data['approval_status'] = 'approved_for_quotes';
        $data['approved_by'] = 'fictional-reviewer';
        $data['approval_reference'] = 'synthetic-test-approval';
        $data['effective_from'] = '2020-01-01';
        $data['effective_until'] = '2099-12-31';
        foreach ($data['parameters'] as &$parameter) {
            $parameter['approval_status'] = 'approved_for_commercial_use';
        }
        unset($parameter);
        $private = $this->secret(json_encode($data, JSON_THROW_ON_ERROR));
        $controller = $this->controller('1', $private);
        $response = $controller->quote(self::request('/v1/economic-quotes', 'quotes-'.str_repeat('y', 33)));
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('5532.47', $payload['service_period_reference_price']);
        self::assertSame('1870.13', $payload['one_time_setup_reference_price']);
        self::assertSame(gmdate('Y-m-d'), $payload['approval_as_of']);
        self::assertFalse($payload['activation_allowed']);
        self::assertArrayNotHasKey('calculation', $payload);
        self::assertArrayNotHasKey('parameters', $payload);
        self::assertStringNotContainsString('full_cost', (string) $response->getContent());
    }

    private function controller(string $enabled, ?string $path = null): EconomicSimulationController
    {
        return new EconomicSimulationController(
            new EconomicSimulationEngine(new EconomicMatrixLoader($path ?? self::fixture())),
            new EconomicBearerAuthenticator(
                $this->secret('analyst-'.str_repeat('x', 33)),
                $this->secret('quotes-'.str_repeat('y', 33)),
            ),
            new EconomicRequestParser(),
            $enabled,
        );
    }

    private function secret(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pricing-auth-');
        self::assertNotFalse($file);
        $this->files[] = $file;
        file_put_contents($file, $content);
        return $file;
    }

    private static function request(string $path, string $token): Request
    {
        return Request::create(
            $path,
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            json_encode([
                'model' => 'managed',
                'parameter_version' => 'fictional-v2',
                'customers' => 1,
            ], JSON_THROW_ON_ERROR),
        );
    }

    private static function fixture(): string
    {
        return dirname(__DIR__, 2).'/tests/Fixtures/economic-historical-synthetic.json';
    }
}
