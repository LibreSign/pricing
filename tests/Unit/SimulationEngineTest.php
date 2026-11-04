<?php

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
        yield 'cloud' => ['cloud', ['documents' => 100, 'storage_gb' => 10, 'service_hours' => 2], '195.00', '99.30', '79.30'];
        yield 'managed' => ['managed', ['documents' => 100, 'storage_gb' => 10, 'service_hours' => 2], '1025.00', '484.50', '304.50'];
        yield 'customer infrastructure' => ['customer_infrastructure', ['documents' => 0, 'storage_gb' => 0, 'service_hours' => 8], '2400.00', '716.00', '436.00'];
    }
    #[DataProvider('examples')]
    public function testSyntheticScenario(string $model, array $usage, string $price, string $contribution, string $margin): void
    {
        $input = SimulationInput::fromArray(['model' => $model, 'parameter_version' => 'synthetic-v1', 'usage' => $usage]);
        $engine = new SimulationEngine(new SyntheticCatalog(dirname(__DIR__, 2).'/config/pricing/synthetic-v1.json'));
        $first = $engine->simulate($input);
        self::assertSame($first, $engine->simulate($input));
        self::assertSame($price, $first['synthetic_list_price']);
        self::assertSame($contribution, $first['tax_scenarios']['synthetic_a']['incremental_contribution']);
        self::assertSame($margin, $first['tax_scenarios']['synthetic_a']['full_cost_margin']);
        self::assertFalse($first['commercialization']['quote_allowed']);
        self::assertFalse($first['commercialization']['activation_allowed']);
    }
    public function testUnknownVersionRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SimulationEngine(new SyntheticCatalog(dirname(__DIR__, 2).'/config/pricing/synthetic-v1.json')))->simulate(
            SimulationInput::fromArray(['model' => 'cloud', 'parameter_version' => 'unknown', 'usage' => ['documents' => 0, 'storage_gb' => 0,'service_hours' => 0]])
        );
    }
}
