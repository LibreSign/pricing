<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Http;

use LibreSign\Pricing\Controller\CommercialOfferController;
use LibreSign\Pricing\Domain\CommercialOfferEngine;
use LibreSign\Pricing\Http\CommercialOfferRequestParser;
use LibreSign\Pricing\Http\EconomicBearerAuthenticator;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CommercialOfferHttpTest extends TestCase
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
        $file = tempnam(sys_get_temp_dir(), 'pricing-offer-auth-');
        self::assertNotFalse($file);
        file_put_contents($file, $body);
        $this->files[] = $file;

        return $file;
    }

    private function controller(): CommercialOfferController
    {
        return new CommercialOfferController(
            new CommercialOfferEngine(new EconomicMatrixLoader(dirname(__DIR__).'/Fixtures/economic-historical-synthetic.json')),
            new CommercialOfferRequestParser(),
            new EconomicBearerAuthenticator(
                $this->secret('analyst-'.str_repeat('a', 35)),
                $this->secret('quotes-'.str_repeat('b', 35)),
            ),
        );
    }

    /** @param array<string, mixed>|null $body */
    private static function request(?array $body = null, string $token = ''): Request
    {
        $body ??= [
            'model' => 'cloud',
            'parameter_version' => 'fictional-v2',
            'accounts' => 100,
            'credits_per_account' => 100,
            'included_credits' => 100,
            'topup_credits' => 50,
            'topup_take_rate' => '0',
            'annual_discount' => '0.10',
            'acquisition_rate' => '0',
            'candidate_monthly_price' => '39.00',
            'candidate_topup_price' => null,
            'candidate_setup_price' => null,
        ];

        return Request::create(
            '/v1/offer-simulations',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    public function testUnauthorizedAndQuotesRoleCannotAccessCostDetails(): void
    {
        $controller = $this->controller();
        self::assertSame(401, $controller->simulate(self::request(token: 'missing'))->getStatusCode());
        self::assertSame(401, $controller->simulate(self::request(token: 'quotes-'.str_repeat('b', 35)))->getStatusCode());
    }

    public function testAnalystReceivesResearchOnlyCostBreakdown(): void
    {
        $controller = $this->controller();
        $response = $controller->simulate(self::request(token: 'analyst-'.str_repeat('a', 35)));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $result = json_decode((string) $response->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('calculated', $result['status']);
        self::assertSame('421.20', $result['calculation']['annual_subscription']);
        self::assertFalse($result['commercialization']['quote_allowed']);
        self::assertFalse($result['commercialization']['activation_allowed']);
        self::assertSame('SYNTHETIC_ONLY', $result['parameters']['scope']);
    }

    public function testUnknownInputPropertyFailsClosed(): void
    {
        $body = json_decode((string) self::request()->getContent(), true, 16, JSON_THROW_ON_ERROR);
        $body['unapproved_override'] = '1';
        self::assertSame(422, $this->controller()->simulate(self::request($body, 'analyst-'.str_repeat('a', 35)))->getStatusCode());
    }

    public function testClientCannotPostRateOverridesAsFloats(): void
    {
        $body = json_decode((string) self::request()->getContent(), true, 16, JSON_THROW_ON_ERROR);
        $body['annual_discount'] = 0.10;
        self::assertSame(422, $this->controller()->simulate(self::request($body, 'analyst-'.str_repeat('a', 35)))->getStatusCode());
    }

    public function testCustomerCanOnlyUseOfferResearchWithValidConditions(): void
    {
        $body = json_decode((string) self::request()->getContent(), true, 16, JSON_THROW_ON_ERROR);
        $body['model'] = 'customer_infrastructure';
        $body['credits_per_account'] = 0;
        $body['included_credits'] = 0;
        $body['recurring_maintenance'] = false;
        $body['candidate_monthly_price'] = '50.00';
        self::assertSame(422, $this->controller()->simulate(self::request($body, 'analyst-'.str_repeat('a', 35)))->getStatusCode());
    }
}
