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
    public const VERSION = '1.1.0';

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
        $scenario = $input->scenario;
        $recurring = $input->model !== 'customer_infrastructure' || $scenario['recurring_support'];

        $needed = [
            $prefix.'.setup_hours' => 'hour/customer',
            $prefix.'.collective_hour' => 'BRL/hour',
            $prefix.'.capacity' => 'customer',
            'simulation.tax' => 'fraction',
            'simulation.default' => 'fraction',
            'simulation.target_margin' => 'fraction',
        ];
        if ($recurring) {
            $needed += [
                $prefix.'.fixed_pool' => 'BRL/period',
                $prefix.'.variable_unit' => 'BRL/customer_period',
                $prefix.'.hours_unit' => 'hours/customer_period',
                $prefix.'.full_hour' => 'BRL/hour',
            ];
        }

        // Additional drivers have no hard-coded money rates. Their exact decimal
        // coefficients must exist in the external economic matrix when selected.
        $drivers = [];
        if ($recurring) {
            foreach ([
                'documents' => ['document_unit', 'BRL/document'],
                'users' => ['user_unit', 'BRL/user_period'],
                'storage_gb' => ['storage_gb_unit', 'BRL/GB_period'],
            ] as $key => [$suffix, $unit]) {
                if (($scenario[$key] ?? 0) > 0) {
                    $drivers[$key] = [$prefix.'.'.$suffix, $unit, $scenario[$key]];
                }
            }
            if ($scenario['backup'] ?? false) {
                $drivers['backup'] = [$prefix.'.backup_gb_unit', 'BRL/GB_period', $scenario['storage_gb']];
            }
            if ($scenario['tsa'] ?? false) {
                $drivers['tsa'] = [$prefix.'.tsa_document_unit', 'BRL/document', $scenario['documents']];
            }
            if ($scenario['dedicated'] ?? false) {
                $drivers['dedicated'] = [$prefix.'.dedicated_period', 'BRL/period', 1];
            }
            if ($scenario['priority_support'] ?? false) {
                $drivers['priority_support'] = [$prefix.'.priority_hours', 'hours/customer_period', 1];
            }
        }
        if (($scenario['integrations'] ?? 0) > 0) {
            $drivers['integrations'] = [$prefix.'.integration_setup_hours', 'hour/integration', $scenario['integrations']];
        }
        if (($scenario['migration_gb'] ?? 0) > 0) {
            $drivers['migration_gb'] = [$prefix.'.migration_setup_hours', 'hour/GB', $scenario['migration_gb']];
        }

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
        $driverValues = [];
        foreach ($drivers as $key => [$id, $unit, $quantity]) {
            try {
                $number = $matrix->optionalDecimal($id, $unit);
            } catch (\InvalidArgumentException $exception) {
                throw new CatalogUnavailable('Economic matrix driver unit mismatch.', 0, $exception);
            }
            if ($number === null) {
                $missing[] = $id;
            } else {
                $driverValues[$key] = $number->multipliedBy($quantity);
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

        $collectiveHour = $values[$prefix.'.collective_hour'];
        $setupBaseHours = $values[$prefix.'.setup_hours'];
        $setupAdditionalHours = BigDecimal::zero();
        $setupItems = [
            'base' => $setupBaseHours->multipliedBy($collectiveHour),
        ];
        foreach (['integrations', 'migration_gb'] as $key) {
            $extra = $driverValues[$key] ?? BigDecimal::zero();
            $setupAdditionalHours = $setupAdditionalHours->plus($extra);
            $setupItems[$key] = $extra->multipliedBy($collectiveHour);
        }
        foreach (['training_hours', 'deployment_hours'] as $key) {
            $extra = BigDecimal::of($scenario[$key] ?? '0');
            $setupAdditionalHours = $setupAdditionalHours->plus($extra);
            $setupItems[$key] = $extra->multipliedBy($collectiveHour);
        }
        $setupHours = $setupBaseHours->plus($setupAdditionalHours);
        $setupCost = $setupHours->multipliedBy($collectiveHour);
        $setupPrice = $setupCost->dividedBy($withMargin, 12, RoundingMode::HalfUp);

        $calculation = [
            'recurring_enabled' => $recurring,
            'cash_cost' => null,
            'full_cost' => null,
            'preservation_cost' => null,
            'incremental_floor' => null,
            'full_price_with_margin' => null,
            'preservation_price_with_margin' => null,
            'one_time_setup_cost' => $this->money($setupCost),
            'one_time_setup_preservation_price' => $this->money($setupPrice),
            'setup_hours' => (string) $setupHours,
            'tax_rate' => (string) $tax,
            'default_rate' => (string) $badDebt,
            'margin_target' => (string) $margin,
            'customers' => $input->customers,
            'capacity' => (string) $capacity,
            'utilization_percent' => $this->money(BigDecimal::of($input->customers)->multipliedBy(100)->dividedBy($capacity, 12, RoundingMode::HalfUp)),
            'cost_breakdown' => [
                'fixed_allocation' => null,
                'base_variable' => null,
                'documents' => null,
                'users' => null,
                'storage_gb' => null,
                'backup' => null,
                'tsa' => null,
                'dedicated' => null,
                'base_work_hours' => null,
                'extra_support_hours' => null,
                'full_work_cost' => null,
                'collective_work_cost' => null,
                'setup' => array_map($this->money(...), $setupItems),
            ],
        ];

        if ($recurring) {
            $fixed = $values[$prefix.'.fixed_pool']->dividedBy($input->customers, 12, RoundingMode::HalfUp);
            $variable = $values[$prefix.'.variable_unit'];
            $usage = BigDecimal::zero();
            foreach (['documents', 'users', 'storage_gb', 'backup', 'tsa', 'dedicated'] as $key) {
                $amount = $driverValues[$key] ?? BigDecimal::zero();
                $usage = $usage->plus($amount);
                $calculation['cost_breakdown'][$key] = $this->money($amount);
            }
            $baseHours = $values[$prefix.'.hours_unit'];
            $addedHours = BigDecimal::of($scenario['support_hours'] ?? '0');
            if (isset($driverValues['priority_support'])) {
                $addedHours = $addedHours->plus($driverValues['priority_support']);
            }
            $workHours = $baseHours->plus($addedHours);
            $fullHour = $values[$prefix.'.full_hour'];
            $cashCost = $fixed->plus($variable)->plus($usage);
            $fullWorkCost = $workHours->multipliedBy($fullHour);
            $collectiveWorkCost = $workHours->multipliedBy($collectiveHour);
            $fullCost = $cashCost->plus($fullWorkCost);
            $preservationCost = $cashCost->plus($collectiveWorkCost);
            // Incremental service work is a genuine variable expense; the
            // baseline work benchmark remains inside the full-cost scenarios.
            $incrementalCost = $variable->plus($usage)->plus($addedHours->multipliedBy($fullHour));
            $incrementalFloor = $incrementalCost->dividedBy($denominator, 12, RoundingMode::HalfUp);
            $fullPrice = $fullCost->dividedBy($withMargin, 12, RoundingMode::HalfUp);
            $preservationPrice = $preservationCost->dividedBy($withMargin, 12, RoundingMode::HalfUp);

            $calculation['cash_cost'] = $this->money($cashCost);
            $calculation['full_cost'] = $this->money($fullCost);
            $calculation['preservation_cost'] = $this->money($preservationCost);
            $calculation['incremental_floor'] = $this->money($incrementalFloor);
            $calculation['full_price_with_margin'] = $this->money($fullPrice);
            $calculation['preservation_price_with_margin'] = $this->money($preservationPrice);
            $calculation['cost_breakdown']['fixed_allocation'] = $this->money($fixed);
            $calculation['cost_breakdown']['base_variable'] = $this->money($variable);
            $calculation['cost_breakdown']['base_work_hours'] = (string) $baseHours;
            $calculation['cost_breakdown']['extra_support_hours'] = (string) $addedHours;
            $calculation['cost_breakdown']['full_work_cost'] = $this->money($fullWorkCost);
            $calculation['cost_breakdown']['collective_work_cost'] = $this->money($collectiveWorkCost);

            if ($input->candidatePrice !== null) {
                $candidate = BigDecimal::of($input->candidatePrice);
                $net = $candidate->multipliedBy($denominator);
                $calculation['candidate_price'] = $input->candidatePrice;
                $calculation['candidate_incremental_contribution'] = $this->money($net->minus($incrementalCost));
                $calculation['candidate_full_margin'] = $this->money($net->minus($fullCost));
                $calculation['candidate_preservation_delta'] = $this->money($net->minus($preservationCost));
            }
        }

        if ($input->candidateSetupPrice !== null) {
            $candidate = BigDecimal::of($input->candidateSetupPrice);
            $net = $candidate->multipliedBy($denominator);
            $calculation['candidate_setup_price'] = $input->candidateSetupPrice;
            $calculation['candidate_setup_delta'] = $this->money($net->minus($setupCost));
        }

        // A read-only simulation never authorizes activation. Quotes additionally
        // require date-sensitive approval of every rate actually used.
        $parameterIds = array_merge(array_keys($needed), array_column($drivers, 0));
        if ($approvalDate !== null && $matrix->quoteAllowed($parameterIds, $approvalDate)) {
            $base['commercialization']['quote_allowed'] = true;
            $base['commercialization']['reason_code'] = 'APPROVED_PARAMETER_SET';
        }

        return $base + [
            'status' => 'calculated',
            'missing_parameters' => [],
            'calculation' => $calculation,
        ];
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
