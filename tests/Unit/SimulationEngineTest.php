<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\SimulationEngine;
use LibreSign\Pricing\Domain\SimulationInput;
use LibreSign\Pricing\Infrastructure\SyntheticCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimulationEngineTest extends TestCase
{
    public static function examples(): iterable
    {
        yield 'cloud recurring' => [
            'cloud',
            ['documents' => 100, 'storage_gb' => 10, 'service_hours' => 2],
            '16.00',
            '9.20',
            '8.20',
        ];

        yield 'managed recurring' => [
            'managed',
            ['documents' => 100, 'storage_gb' => 10, 'service_hours' => 2],
            '30.00',
            '14.50',
            '11.50',
        ];

        yield 'customer infrastructure project' => [
            'customer_infrastructure',
            ['documents' => 0, 'storage_gb' => 0, 'service_hours' => 8],
            '62.00',
            '30.90',
            '25.90',
        ];
    }

    #[DataProvider('examples')]
    public function testSyntheticScenario(
        string $model,
        array $usage,
        string $price,
        string $contribution,
        string $margin,
    ): void {
        $input = SimulationInput::fromArray([
            'model' => $model,
            'parameter_version' => 'synthetic-v1',
            'usage' => $usage,
        ]);
        $engine = $this->engine();
        $first = $engine->simulate($input);

        self::assertSame($first, $engine->simulate($input));
        self::assertSame($price, $first['synthetic_list_price']);
        self::assertSame($contribution, $first['tax_scenarios']['synthetic_a']['incremental_contribution']);
        self::assertSame($margin, $first['tax_scenarios']['synthetic_a']['full_cost_margin']);
        self::assertSame('BRL', $first['currency']);
        self::assertTrue($first['parameters']['example_only']);
        self::assertFalse($first['commercialization']['quote_allowed']);
        self::assertFalse($first['commercialization']['activation_allowed']);
    }

    public function testCandidatePriceCannotAuthorizeCommercialUse(): void
    {
        $input = SimulationInput::fromArray([
            'model' => 'managed',
            'parameter_version' => 'synthetic-v1',
            'usage' => [
                'documents' => 100,
                'storage_gb' => 10,
                'service_hours' => 2,
            ],
            'candidate_price' => '1000.00',
        ]);
        $result = $this->engine()->simulate($input);

        self::assertSame('1000.00', $result['evaluated_price']);
        self::assertSame('30.00', $result['synthetic_list_price']);
        self::assertFalse($result['commercialization']['quote_allowed']);
        self::assertFalse($result['commercialization']['activation_allowed']);
    }

    public function testHalfUpRoundingAndNegativeMargin(): void
    {
        $input = SimulationInput::fromArray([
            'model' => 'cloud',
            'parameter_version' => 'synthetic-v1',
            'usage' => [
                'documents' => 0,
                'storage_gb' => 0,
                'service_hours' => 0,
            ],
            'candidate_price' => '0.10',
        ]);
        $result = $this->engine()->simulate($input);
        $scenario = $result['tax_scenarios']['synthetic_a'];

        self::assertSame('0.01', $scenario['tax_amount']); // 0.10 * 0.05 = 0.005
        self::assertSame('0.00', $scenario['cooperative_residual_potential']);
        self::assertFalse($scenario['full_cost_recovered']);
        self::assertFalse($scenario['incremental_contribution_positive']);
    }

    public function testUnknownVersionRejected(): void
    {
        $input = SimulationInput::fromArray([
            'model' => 'cloud',
            'parameter_version' => 'unknown',
            'usage' => [
                'documents' => 0,
                'storage_gb' => 0,
                'service_hours' => 0,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->engine()->simulate($input);
    }

    private function engine(): SimulationEngine
    {
        return new SimulationEngine(new SyntheticCatalog(
            dirname(__DIR__, 2).'/tests/Fixtures/synthetic-v1.json',
        ));
    }
}
