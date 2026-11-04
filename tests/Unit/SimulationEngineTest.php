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
        yield 'cloud' => ['cloud', ['documents' => 100, 'storage_gb' => 10, 'service_hours' => 2], '16.00', '9.20', '8.20'];
        yield 'managed' => ['managed', ['documents' => 100, 'storage_gb' => 10, 'service_hours' => 2], '30.00', '14.50', '11.50'];
        yield 'customer infrastructure' => ['customer_infrastructure', ['documents' => 0, 'storage_gb' => 0, 'service_hours' => 8], '62.00', '30.90', '25.90'];
    }
    #[DataProvider('examples')]
    public function testSyntheticScenario(string $model, array $usage, string $price, string $contribution, string $margin): void
    {
        $input = SimulationInput::fromArray(['model' => $model, 'parameter_version' => 'synthetic-v1', 'usage' => $usage]);
        $engine = new SimulationEngine(new SyntheticCatalog(dirname(__DIR__, 2).'/tests/Fixtures/synthetic-v1.json'));
        $first = $engine->simulate($input);
        self::assertSame($first, $engine->simulate($input));
        self::assertSame($price, $first['synthetic_list_price']);
        self::assertSame($contribution, $first['tax_scenarios']['synthetic_a']['incremental_contribution']);
        self::assertSame($margin, $first['tax_scenarios']['synthetic_a']['full_cost_margin']);
        self::assertTrue($first['parameters']['example_only']);
        self::assertFalse($first['commercialization']['quote_allowed']);
        self::assertFalse($first['commercialization']['activation_allowed']);
    }
    public function testCandidatePriceCannotAuthorizeCommercialUse(): void
    {
        $input = SimulationInput::fromArray([
            'model' => 'managed',
            'parameter_version' => 'synthetic-v1',
            'usage' => ['documents' => 100, 'storage_gb' => 10, 'service_hours' => 2],
            'candidate_price' => '1000.00',
        ]);
        $engine = new SimulationEngine(new SyntheticCatalog(dirname(__DIR__, 2).'/tests/Fixtures/synthetic-v1.json'));
        $result = $engine->simulate($input);

        self::assertSame('1000.00', $result['evaluated_price']);
        self::assertSame('30.00', $result['synthetic_list_price']);
        self::assertFalse($result['commercialization']['quote_allowed']);
        self::assertFalse($result['commercialization']['activation_allowed']);
    }

    public function testUnknownVersionRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SimulationEngine(new SyntheticCatalog(dirname(__DIR__, 2).'/tests/Fixtures/synthetic-v1.json')))->simulate(
            SimulationInput::fromArray(['model' => 'cloud', 'parameter_version' => 'unknown', 'usage' => ['documents' => 0, 'storage_gb' => 0,'service_hours' => 0]])
        );
    }
}
