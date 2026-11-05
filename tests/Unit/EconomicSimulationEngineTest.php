<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\EconomicScenarioInput;
use LibreSign\Pricing\Domain\EconomicSimulationEngine;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EconomicSimulationEngineTest extends TestCase
{
    public static function historicalScenarios(): iterable
    {
        // Historical research values were ILLUSTRATIVE. This fixture is intentionally fictional.
        yield 'cloud low' => ['cloud', 20, '124.00', '142.00', '161.04', '184.42'];
        yield 'cloud medium' => ['cloud', 50, '76.00', '94.00', '98.70', '122.08'];
        yield 'cloud high' => ['cloud', 75, '65.33', '83.33', '84.85', '108.23'];
        yield 'managed low' => ['managed', 1, '3810.00', '4260.00', '4948.05', '5532.47'];
        yield 'managed medium' => ['managed', 3, '1610.00', '2060.00', '2090.91', '2675.32'];
        yield 'managed high' => ['managed', 6, '1060.00', '1510.00', '1376.62', '1961.04'];
        yield 'infrastructure low' => ['customer_infrastructure', 1, '610.00', '880.00', '792.21', '1142.86'];
        yield 'infrastructure medium' => ['customer_infrastructure', 3, '410.00', '680.00', '532.47', '883.12'];
        yield 'infrastructure high' => ['customer_infrastructure', 6, '360.00', '630.00', '467.53', '818.18'];
    }

    #[DataProvider('historicalScenarios')]
    public function testReproducesNinePreviouslyDocumentedIllustrativeScenarios(
        string $model,
        int $customers,
        string $fullCost,
        string $preservationCost,
        string $fullPrice,
        string $preservationPrice,
    ): void {
        $engine = self::engine();
        $input = new EconomicScenarioInput($model, 'fictional-v2', $customers, null);
        $first = $engine->simulate($input);
        self::assertSame($first, $engine->simulate($input));
        self::assertSame('calculated', $first['status']);
        self::assertSame($fullCost, $first['calculation']['full_cost']);
        self::assertSame($preservationCost, $first['calculation']['preservation_cost']);
        self::assertSame($fullPrice, $first['calculation']['full_price_with_margin']);
        self::assertSame($preservationPrice, $first['calculation']['preservation_price_with_margin']);
        self::assertFalse($first['commercialization']['quote_allowed']);
        self::assertFalse($first['commercialization']['activation_allowed']);
    }

    public function testSetupPriceIsSeparateFromRecurringInfrastructureSupport(): void
    {
        $result = self::engine()->simulate(new EconomicScenarioInput('customer_infrastructure', 'fictional-v2', 1, null));
        self::assertSame('1142.86', $result['calculation']['preservation_price_with_margin']);
        self::assertSame('1800.00', $result['calculation']['one_time_setup_cost']);
        self::assertSame('2337.66', $result['calculation']['one_time_setup_preservation_price']);
    }

    public function testCandidatePriceProducesExplainableComparisons(): void
    {
        $input = new EconomicScenarioInput('cloud', 'fictional-v2', 20, '200.00');
        $result = self::engine()->simulate($input);
        self::assertSame('200.00', $result['calculation']['candidate_price']);
        self::assertSame('158.00', $result['calculation']['candidate_incremental_contribution']);
        self::assertSame('60.00', $result['calculation']['candidate_full_margin']);
        self::assertSame('42.00', $result['calculation']['candidate_preservation_delta']);
    }

    public function testExcessCapacityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::engine()->simulate(new EconomicScenarioInput('cloud', 'fictional-v2', 81, null));
    }

    public function testWrongMatrixVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::engine()->simulate(new EconomicScenarioInput('cloud', 'wrong-v1', 20, null));
    }

    public function testUnknownCostsBlockCalculationInsteadOfBecomingZero(): void
    {
        $data = json_decode(
            (string) file_get_contents(self::fixturePath()),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $data['parameters'][0]['value'] = null;
        $path = tempnam(sys_get_temp_dir(), 'pricing-test-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
            $result = (new EconomicSimulationEngine(new EconomicMatrixLoader($path)))
                ->simulate(new EconomicScenarioInput('cloud', 'fictional-v2', 20, null));
            self::assertSame('incomplete', $result['status']);
            self::assertNull($result['calculation']);
            self::assertContains('simulation.cloud.fixed_pool', $result['missing_parameters']);
            self::assertFalse($result['commercialization']['quote_allowed']);
        } finally {
            unlink($path);
        }
    }

    public function testSyntheticApprovalNeverAuthorizesQuote(): void
    {
        $result = self::engine()->simulate(new EconomicScenarioInput('cloud', 'fictional-v2', 20, null));
        self::assertSame('SYNTHETIC_ONLY', $result['parameters']['scope']);
        self::assertFalse($result['commercialization']['quote_allowed']);
    }

    private static function engine(): EconomicSimulationEngine
    {
        return new EconomicSimulationEngine(new EconomicMatrixLoader(self::fixturePath()));
    }

    private static function fixturePath(): string
    {
        return dirname(__DIR__, 2).'/tests/Fixtures/economic-historical-synthetic.json';
    }
}
