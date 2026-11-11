<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\EconomicScenarioInput;
use LibreSign\Pricing\Domain\EconomicSimulationEngine;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use PHPUnit\Framework\TestCase;

final class EconomicScenarioOptionsTest extends TestCase
{
    private static function engine(?string $path = null): EconomicSimulationEngine
    {
        return new EconomicSimulationEngine(new EconomicMatrixLoader(
            $path ?? dirname(__DIR__, 2).'/tests/Fixtures/economic-historical-synthetic.json',
        ));
    }

    public function testCloudCostDriversAndSupportWorkContributeToRecurringPrice(): void
    {
        $base = self::engine()->simulate(new EconomicScenarioInput('cloud', 'fictional-v2', 20, null));
        $input = new EconomicScenarioInput('cloud', 'fictional-v2', 20, '1000.00', [
            'documents' => 1000,
            'users' => 10,
            'storage_gb' => 20,
            'backup' => true,
            'tsa' => true,
            'dedicated' => true,
            'priority_support' => true,
            'support_hours' => '2.00',
            'integrations' => 2,
            'migration_gb' => 5,
        ], '1000.00');
        $output = self::engine()->simulate($input);
        $breakdown = $output['calculation']['cost_breakdown'];
        self::assertSame('80.00', $breakdown['documents']);
        self::assertSame('15.00', $breakdown['users']);
        self::assertSame('14.00', $breakdown['storage_gb']);
        self::assertSame('5.00', $breakdown['backup']);
        self::assertSame('40.00', $breakdown['tsa']);
        self::assertSame('220.00', $breakdown['dedicated']);
        self::assertSame('3.50', $breakdown['extra_support_hours']);
        self::assertSame('13.00', $output['calculation']['setup_hours']);
        self::assertNotSame($base['calculation']['preservation_price_with_margin'], $output['calculation']['preservation_price_with_margin']);
        self::assertNotSame($base['simulation_fingerprint'], $output['simulation_fingerprint']);
        self::assertFalse($output['commercialization']['quote_allowed']);
        self::assertFalse($output['commercialization']['activation_allowed']);
        self::assertArrayHasKey('candidate_setup_delta', $output['calculation']);
    }

    public function testCustomerInfrastructureCanBeSetupOnly(): void
    {
        $input = new EconomicScenarioInput('customer_infrastructure', 'fictional-v2', 1, null, [
            'recurring_support' => false,
            'integrations' => 2,
            'migration_gb' => 4,
            'training_hours' => '3.00',
            'deployment_hours' => '2.00',
        ], '10000.00');
        $output = self::engine()->simulate($input);
        self::assertSame('calculated', $output['status']);
        self::assertFalse($output['calculation']['recurring_enabled']);
        self::assertNull($output['calculation']['preservation_price_with_margin']);
        self::assertNull($output['calculation']['full_cost']);
        self::assertSame('43.00', $output['calculation']['setup_hours']);
        self::assertSame('3870.00', $output['calculation']['one_time_setup_cost']);
        self::assertSame('5025.97', $output['calculation']['one_time_setup_preservation_price']);
        self::assertFalse($output['commercialization']['quote_allowed']);
    }

    public function testMissingSelectedDriverBlocksPriceRatherThanAssumingZero(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pricing-driver-');
        self::assertNotFalse($path);
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/economic-historical-synthetic.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($data['parameters'] as &$parameter) {
            if ($parameter['id'] === 'simulation.cloud.document_unit') {
                $parameter['value'] = null;
            }
        }
        unset($parameter);
        try {
            file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
            $result = self::engine($path)->simulate(new EconomicScenarioInput(
                'cloud',
                'fictional-v2',
                20,
                null,
                ['documents' => 10],
            ));
            self::assertSame('incomplete', $result['status']);
            self::assertContains('simulation.cloud.document_unit', $result['missing_parameters']);
            self::assertNull($result['calculation']);
        } finally {
            unlink($path);
        }
    }

    public function testOptionsHaveDeterministicFingerprintAndAreOrderIndependent(): void
    {
        $left = new EconomicScenarioInput('managed', 'fictional-v2', 3, null, [
            'integrations' => 1, 'support_hours' => '1.00', 'users' => 12,
        ]);
        $right = new EconomicScenarioInput('managed', 'fictional-v2', 3, null, [
            'users' => 12, 'integrations' => 1, 'support_hours' => '1.00',
        ]);
        self::assertSame(self::engine()->simulate($left), self::engine()->simulate($right));
    }
}
