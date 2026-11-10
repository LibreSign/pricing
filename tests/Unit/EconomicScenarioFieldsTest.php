<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\EconomicScenarioFields;
use LibreSign\Pricing\Domain\EconomicScenarioInput;
use LibreSign\Pricing\Domain\InvalidSimulationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EconomicScenarioFieldsTest extends TestCase
{
    public function testMetadataExposesModalitySpecificFieldsWithoutEconomicValues(): void
    {
        $fields = EconomicScenarioFields::metadata();
        self::assertContains('documents', array_column($fields['cloud'], 'id'));
        self::assertNotContains('documents', array_column($fields['customer_infrastructure'], 'id'));
        self::assertContains('recurring_support', array_column($fields['customer_infrastructure'], 'id'));
        self::assertNotContains('recurring_support', array_column($fields['managed'], 'id'));
        self::assertStringNotContainsString('BRL', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    public static function invalidFields(): iterable
    {
        yield 'unsupported field for customer infrastructure' => ['customer_infrastructure', ['storage_gb' => 10]];
        yield 'unknown field' => ['cloud', ['injected' => true]];
        yield 'fractional integer' => ['cloud', ['documents' => 2.5]];
        yield 'string integer' => ['cloud', ['documents' => '2']];
        yield 'negative quantity' => ['managed', ['integrations' => -1]];
        yield 'quantity over limit' => ['cloud', ['users' => 100001]];
        yield 'float hours' => ['managed', ['support_hours' => 1.5]];
        yield 'negative hours' => ['managed', ['support_hours' => '-2.00']];
        yield 'too many decimals' => ['managed', ['support_hours' => '1.001']];
        yield 'invalid toggle' => ['cloud', ['backup' => 'false']];
        yield 'backup without storage' => ['cloud', ['backup' => true]];
        yield 'TSA without documents' => ['cloud', ['tsa' => true]];
    }

    #[DataProvider('invalidFields')]
    public function testInvalidOrInapplicableFieldsFailClosed(string $model, array $fields): void
    {
        $this->expectException(InvalidSimulationInput::class);
        new EconomicScenarioInput($model, 'fictional-v2', 1, null, $fields);
    }

    public function testSeparateSetupCandidateAndOptionalMaintenance(): void
    {
        $input = new EconomicScenarioInput('customer_infrastructure', 'fictional-v2', 1, null, [
            'recurring_support' => false,
        ], '4000.00');
        self::assertFalse($input->scenario['recurring_support']);
        self::assertSame('4000.00', $input->normalized()['candidate_setup_price']);
        self::assertArrayNotHasKey('documents', $input->scenario);
    }

    public function testCannotQuoteRecurringServiceWhenDisabled(): void
    {
        $this->expectException(InvalidSimulationInput::class);
        new EconomicScenarioInput('customer_infrastructure', 'fictional-v2', 1, '100.00', [
            'recurring_support' => false,
        ]);
    }
}
