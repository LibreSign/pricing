<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\SimulationInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimulationInputTest extends TestCase
{
    public static function invalidInputs(): iterable
    {
        $base = [
            'model' => 'cloud',
            'parameter_version' => 'synthetic-v1',
            'usage' => [
                'documents' => 0,
                'storage_gb' => 0,
                'service_hours' => 0,
            ],
        ];

        yield 'unsupported model' => [[...$base, 'model' => 'bogus']];
        yield 'missing model' => [[...$base, 'model' => null]];
        yield 'unknown operation flag' => [[...$base, 'publish' => true]];
        yield 'negative document count' => [[
            ...$base,
            'usage' => [...$base['usage'], 'documents' => -1],
        ]];
        yield 'document count exceeds limit' => [[
            ...$base,
            'usage' => [...$base['usage'], 'documents' => 1000001],
        ]];
        yield 'fractional document count' => [[
            ...$base,
            'usage' => [...$base['usage'], 'documents' => 1.5],
        ]];
        yield 'missing usage field' => [[
            ...$base,
            'usage' => ['documents' => 0, 'storage_gb' => 0],
        ]];
        yield 'unexpected usage field' => [[
            ...$base,
            'usage' => [...$base['usage'], 'months' => 2],
        ]];
        yield 'float candidate price' => [[...$base, 'candidate_price' => 3.5]];
        yield 'excessive monetary precision' => [[...$base, 'candidate_price' => '1.005']];
        yield 'invalid parameter version' => [[...$base, 'parameter_version' => 'Bad Version']];
        yield 'infrastructure document charges' => [[
            ...$base,
            'model' => 'customer_infrastructure',
            'usage' => [...$base['usage'], 'documents' => 1],
        ]];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejected(array $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SimulationInput::fromArray($body);
    }

    public function testNormalizedUsageUsesPublicSnakeCaseFields(): void
    {
        $input = SimulationInput::fromArray([
            'model' => 'managed',
            'parameter_version' => 'synthetic-v1',
            'usage' => [
                'documents' => 1,
                'storage_gb' => 2,
                'service_hours' => 3,
            ],
        ]);

        self::assertSame(
            ['documents' => 1, 'storage_gb' => 2, 'service_hours' => 3],
            $input->normalized()['usage'],
        );
    }
}
