<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Research-only conversion of operational quantities into equivalent credits.
 * The contractual charging unit and metering are not approved.
 */
final class CreditUsage
{
    /** @var array<string, array{label: string, unit: string, maximum: int}> */
    public const COMPONENTS = [
        'signed_obligations' => ['label' => 'Obrigações signatário-documento concluídas', 'unit' => 'credit/obligation', 'maximum' => 1000000],
        'users' => ['label' => 'Usuários habilitados no mês', 'unit' => 'credit/user_period', 'maximum' => 100000],
        'storage_gb' => ['label' => 'Armazenamento em GB-mês', 'unit' => 'credit/GB_period', 'maximum' => 100000],
        'api_calls' => ['label' => 'Chamadas de API no mês', 'unit' => 'credit/api_call', 'maximum' => 1000000],
        'tsa_documents' => ['label' => 'Documentos com TSA no mês', 'unit' => 'credit/tsa_document', 'maximum' => 1000000],
    ];

    /**
     * @param array<string, int> $quantities
     * @param array<string, string> $weights
     * @return array{total_credits: int, lines: list<array<string, string|int>>, weights: array<string, string>}
     */
    public static function calculate(array $quantities, array $weights): array
    {
        $total = BigDecimal::zero();
        $lines = [];
        foreach (self::COMPONENTS as $id => $description) {
            $quantity = $quantities[$id] ?? 0;
            $weight = BigDecimal::of($weights[$id] ?? '0');
            if ($weight->compareTo('0') < 0 || $weight->compareTo('999') > 0) {
                throw new InvalidSimulationInput('Credit weight outside research limits.');
            }
            $consumption = $weight->multipliedBy($quantity);
            $total = $total->plus($consumption);
            $lines[] = [
                'id' => $id,
                'label' => $description['label'],
                'quantity' => $quantity,
                'weight' => (string) $weight,
                'exact_credits' => (string) $consumption,
            ];
        }
        $rounded = (int) (string) $total->toScale(0, RoundingMode::Ceiling);
        if ($rounded > 1000000) {
            throw new InvalidSimulationInput('Resource usage exceeds the supported credit scenario.');
        }

        return ['total_credits' => $rounded, 'lines' => $lines, 'weights' => $weights];
    }
}
