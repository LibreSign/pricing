<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use LibreSign\Pricing\Infrastructure\CatalogUnavailable;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;

final readonly class EconomicSimulationEngine
{
    public const VERSION = '1.0.0';

    public function __construct(private EconomicMatrixLoader $loader)
    {
    }

    /** @return array<string, mixed> */
    public function simulate(EconomicScenarioInput $input, ?string $approvalDate = null): array
    {
        $matrix = $this->loader->load();
        if ($matrix->version() !== $input->parameterVersion) {
            throw new InvalidSimulationInput('Unknown economic parameter version.');
        }
        $model = $matrix->model($input->model);
        $prefix = $model['parameter_prefix'];
        $needed = [
            $prefix.'.fixed_pool' => 'BRL/period',
            $prefix.'.variable_unit' => 'BRL/customer_period',
            $prefix.'.hours_unit' => 'hours/customer_period',
            $prefix.'.setup_hours' => 'hour/customer',
            $prefix.'.full_hour' => 'BRL/hour',
            $prefix.'.collective_hour' => 'BRL/hour',
            $prefix.'.capacity' => 'customer',
            'simulation.tax' => 'fraction',
            'simulation.default' => 'fraction',
            'simulation.target_margin' => 'fraction',
        ];
        $values = [];
        $missing = [];
        foreach ($needed as $id => $unit) {
            try {
                $number = $matrix->decimal($id, $unit);
            } catch (\InvalidArgumentException $exception) {
                throw new CatalogUnavailable('Economic matrix parameter contract mismatch.', 0, $exception);
            }
            if ($number === null) {
                $missing[] = $id;
            } else {
                $values[$id] = $number;
            }
        }
        $metadata = $matrix->metadata();
        $fingerprint = hash('sha256', json_encode([
            'engine' => self::VERSION,
            'matrix_sha256' => $matrix->fingerprint(),
            'input' => $input->normalized(),
            'approval_date' => $approvalDate,
        ], JSON_THROW_ON_ERROR));
        $base = [
            'engine_version' => self::VERSION,
            'simulation_fingerprint' => $fingerprint,
            'parameters' => $metadata,
            'input' => $input->normalized(),
            'approval_as_of' => $approvalDate,
            'currency' => 'BRL',
            'billing_basis' => $model['billing_basis'],
            'commercialization' => [
                'quote_allowed' => false,
                'activation_allowed' => false,
                'reason_code' => 'EXPLICIT_APPROVAL_REQUIRED',
            ],
        ];

        if ($missing !== []) {
            return $base + [
                'status' => 'incomplete',
                'missing_parameters' => $missing,
                'calculation' => null,
            ];
        }

        $capacity = $values[$prefix.'.capacity'];
        if ($capacity->compareTo(0) <= 0 || $capacity->compareTo($input->customers) < 0) {
            throw new InvalidSimulationInput('Customers exceed configured capacity.');
        }
        $tax = $this->rate($values['simulation.tax']);
        $badDebt = $this->rate($values['simulation.default']);
        $margin = $this->rate($values['simulation.target_margin']);
        $denominator = BigDecimal::one()->minus($tax)->minus($badDebt);
        $withMargin = $denominator->minus($margin);
        if ($denominator->compareTo(0) <= 0 || $withMargin->compareTo(0) <= 0) {
            throw new InvalidSimulationInput('Tax, risk and target margin exceed available revenue.');
        }

        // Calculate per-contract prices, not total portfolio revenue.
        $fixed = $values[$prefix.'.fixed_pool']->dividedBy($input->customers, 12, RoundingMode::HalfUp);
        $variable = $values[$prefix.'.variable_unit'];
        $hours = $values[$prefix.'.hours_unit'];
        $fullHour = $values[$prefix.'.full_hour'];
        $collectiveHour = $values[$prefix.'.collective_hour'];

        $cashCost = $fixed->plus($variable);
        $fullCost = $cashCost->plus($hours->multipliedBy($fullHour));
        $preservationCost = $cashCost->plus($hours->multipliedBy($collectiveHour));
        $incrementalFloor = $variable->dividedBy($denominator, 12, RoundingMode::HalfUp);
        $fullPrice = $fullCost->dividedBy($withMargin, 12, RoundingMode::HalfUp);
        $preservationPrice = $preservationCost->dividedBy($withMargin, 12, RoundingMode::HalfUp);
        $setupHours = $values[$prefix.'.setup_hours'];
        $setupCost = $setupHours->multipliedBy($collectiveHour);
        $setupPrice = $setupCost->dividedBy($withMargin, 12, RoundingMode::HalfUp);

        $result = [
            'status' => 'calculated',
            'missing_parameters' => [],
            'calculation' => [
                'cash_cost' => $this->money($cashCost),
                'full_cost' => $this->money($fullCost),
                'preservation_cost' => $this->money($preservationCost),
                'incremental_floor' => $this->money($incrementalFloor),
                'full_price_with_margin' => $this->money($fullPrice),
                'preservation_price_with_margin' => $this->money($preservationPrice),
                'one_time_setup_cost' => $this->money($setupCost),
                'one_time_setup_preservation_price' => $this->money($setupPrice),
                'tax_rate' => (string) $tax,
                'default_rate' => (string) $badDebt,
                'margin_target' => (string) $margin,
                'customers' => $input->customers,
                'capacity' => (string) $capacity,
                'utilization_percent' => $this->money(BigDecimal::of($input->customers)->multipliedBy(100)->dividedBy($capacity, 12, RoundingMode::HalfUp)),
            ],
        ];
        if ($input->candidatePrice !== null) {
            $candidate = BigDecimal::of($input->candidatePrice);
            $net = $candidate->multipliedBy($denominator);
            $result['calculation']['candidate_price'] = $input->candidatePrice;
            $result['calculation']['candidate_incremental_contribution'] = $this->money($net->minus($variable));
            $result['calculation']['candidate_full_margin'] = $this->money($net->minus($fullCost));
            $result['calculation']['candidate_preservation_delta'] = $this->money($net->minus($preservationCost));
        }

        // Date-sensitive authorization is opt-in; ordinary simulations stay deterministic.
        if ($approvalDate !== null && $matrix->quoteAllowed(array_keys($needed), $approvalDate)) {
            $base['commercialization']['quote_allowed'] = true;
            $base['commercialization']['reason_code'] = 'APPROVED_PARAMETER_SET';
        }

        return $base + $result;
    }

    private function rate(BigDecimal $value): BigDecimal
    {
        if ($value->compareTo(0) < 0 || $value->compareTo(1) > 0) {
            throw new InvalidSimulationInput('Rate must be within [0,1].');
        }
        return $value;
    }

    private function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }
}
