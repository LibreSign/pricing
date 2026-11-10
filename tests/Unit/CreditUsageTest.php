<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\CommercialOfferEngine;
use LibreSign\Pricing\Domain\CommercialOfferInput;
use LibreSign\Pricing\Domain\CreditUsage;
use LibreSign\Pricing\Domain\InvalidSimulationInput;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use PHPUnit\Framework\TestCase;

final class CreditUsageTest extends TestCase
{
    /** @return array<string, int> */
    private static function usage(): array
    {
        return [
            'signed_obligations' => 55,
            'users' => 5,
            'storage_gb' => 20,
            'api_calls' => 1000,
            'tsa_documents' => 10,
        ];
    }

    private static function offer(array $usage, array $weights = [], array $economics = []): CommercialOfferInput
    {
        return new CommercialOfferInput(
            'managed',
            'fictional-v2',
            1,
            0,
            100,
            50,
            '0',
            '0',
            '0',
            '1500.00',
            null,
            null,
            supportHours: '2',
            resourceUsage: $usage,
            creditWeights: $weights,
            economicOverrides: $economics,
        );
    }

    private static function engine(): CommercialOfferEngine
    {
        return new CommercialOfferEngine(new EconomicMatrixLoader(
            dirname(__DIR__).'/Fixtures/economic-historical-synthetic.json',
        ));
    }

    public function testFadersAndAPIAgreeOnInitialCreditConsumptionAndChanges(): void
    {
        $initial = self::engine()->simulate(self::offer(self::usage()));
        self::assertSame('calculated', $initial['status']);
        self::assertSame(100, $initial['calculation']['average_credits_requested']);
        self::assertSame(100, $initial['calculation']['resource_credit_breakdown']['total_credits']);
        $more = self::usage();
        $more['signed_obligations'] = 65;
        $result = self::engine()->simulate(self::offer($more));
        self::assertSame(110, $result['calculation']['average_credits_requested']);
        self::assertSame('100', $result['calculation']['average_credits_served']);
        self::assertSame(1, $result['calculation']['additional_packs_if_fully_recharged']);
        self::assertNotSame($initial['simulation_fingerprint'], $result['simulation_fingerprint']);
    }

    public function testFractionalWeightsSumExactlyBeforeCeilingOnce(): void
    {
        $weights = ['signed_obligations' => '0.333', 'users' => '0.333'];
        $sum = CreditUsage::calculate([
            'signed_obligations' => 1, 'users' => 2, 'storage_gb' => 0,
            'api_calls' => 0, 'tsa_documents' => 0,
        ], $weights);
        self::assertSame(1, $sum['total_credits']);
        self::assertSame('0.333', $sum['lines'][0]['exact_credits']);
        self::assertSame('0.666', $sum['lines'][1]['exact_credits']);
    }

    public function testCreditWeightOverrideChangesDemandAndIsTraceable(): void
    {
        $result = self::engine()->simulate(self::offer(self::usage(), ['storage_gb' => '1.00']));
        self::assertSame(110, $result['calculation']['average_credits_requested']);
        $override = array_values(array_filter(
            $result['evidence'],
            static fn (array $e): bool => $e['id'] === 'offer.credit_weight.storage_gb',
        ));
        self::assertSame('1.00', $override[0]['value']);
        self::assertSame('ANALYST_SCENARIO_OVERRIDE', $override[0]['source']);
        self::assertFalse($result['commercialization']['quote_allowed']);
    }

    public function testHourlyOverrideChangesCostAndKeepsSeparateSalePrice(): void
    {
        $base = self::engine()->simulate(self::offer(self::usage()));
        self::assertSame('16.00', $base['calculation']['setup_formula']['hours']);
        self::assertSame('90.00', $base['calculation']['setup_formula']['hourly_cost']);
        self::assertSame('1440.00', $base['calculation']['setup_formula']['direct_labor_cost']);
        self::assertSame('1960.27', $base['calculation']['setup_formula']['reference_price']);
        $edited = self::engine()->simulate(self::offer(self::usage(), [], [
            'simulation.managed.collective_hour' => '250.00',
            'simulation.managed.setup_hours' => '20',
        ]));
        self::assertSame('20', $edited['calculation']['setup_formula']['hours']);
        self::assertSame('250.00', $edited['calculation']['setup_formula']['hourly_cost']);
        self::assertSame('5000.00', $edited['calculation']['setup_formula']['direct_labor_cost']);
        self::assertGreaterThan(5000, (float) $edited['calculation']['setup_formula']['reference_price']);
    }

    public function testUnrelatedCostOverrideRejected(): void
    {
        $this->expectException(InvalidSimulationInput::class);
        self::engine()->simulate(self::offer(self::usage(), [], ['offer.cloud.host_node_monthly' => '0']));
    }

    public function testRejectsMalformedResourceMap(): void
    {
        $usage = self::usage();
        unset($usage['api_calls']);
        $this->expectException(InvalidSimulationInput::class);
        self::offer($usage);
    }

    public function testRejectsInvalidEconomicOverride(): void
    {
        $this->expectException(InvalidSimulationInput::class);
        self::offer(self::usage(), [], ['simulation.target_margin' => '1.1']);
    }

    public function testRejectsInvalidCreditWeight(): void
    {
        $this->expectException(InvalidSimulationInput::class);
        self::offer(self::usage(), ['users' => '-1']);
    }
}
