<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use LibreSign\Pricing\Infrastructure\CatalogUnavailable;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;

/**
 * Hypothesis-only package economics. Never used by the quote or checkout API.
 *
 * Pricing combines a shared per-credit platform layer with exactly one hosting
 * strategy (pooled Cloud, dedicated Managed, or customer-owned on-premise).
 */
final readonly class CommercialOfferEngine
{
    public const VERSION = 'research-1.0';

    public function __construct(private EconomicMatrixLoader $loader)
    {
    }

    /** @return array<string, mixed> */
    public function simulate(CommercialOfferInput $input): array
    {
        $matrix = $this->loader->load();
        if ($matrix->version() !== $input->parameterVersion) {
            throw new InvalidSimulationInput('Economic matrix version changed.');
        }

        $model = $input->model;
        $recurring = $model !== 'customer_infrastructure' || $input->recurringMaintenance;
        $prefix = 'simulation.'.$model;
        $needed = [
            'simulation.tax' => 'fraction',
            'simulation.default' => 'fraction',
            'simulation.target_margin' => 'fraction',
            'offer.common.payment_rate' => 'fraction',
            'offer.common.payment_fixed' => 'BRL/payment',
            $prefix.'.collective_hour' => 'BRL/hour',
        ];
        if ($model !== 'cloud' && $input->includeSetup) {
            $needed[$prefix.'.setup_hours'] = 'hour/customer';
        }
        if ($recurring && $model !== 'customer_infrastructure') {
            $needed += [
                'offer.common.unit_credit_cost' => 'BRL/credit',
                'offer.common.platform_per_contract' => 'BRL/customer_period',
                'offer.common.cac_per_new_contract' => 'BRL/customer',
            ];
            if ($model === 'cloud') {
                $needed += [
                    'offer.cloud.host_node_monthly' => 'BRL/period',
                    'offer.cloud.node_contract_capacity' => 'customer',
                    'offer.cloud.node_credit_capacity' => 'credit/period',
                    'offer.cloud.shared_operations_monthly' => 'BRL/period',
                ];
            } else {
                $needed += [
                    'offer.managed.dedicated_node_monthly' => 'BRL/period',
                    'offer.managed.node_credit_capacity' => 'credit/period',
                    'offer.managed.operations_hours_monthly' => 'hours/customer_period',
                ];
            }
        }
        if ($model === 'customer_infrastructure') {
            if ($input->remoteAccess) {
                $needed['offer.customer_infrastructure.remote_setup_hours'] = 'hour/customer';
            }
            if ($recurring) {
                $needed['offer.customer_infrastructure.service_coordination_hours_monthly'] = 'hours/customer_period';
                if ($input->remoteAccess) {
                    $needed['offer.customer_infrastructure.remote_maintenance_hours_monthly'] = 'hours/customer_period';
                }
            }
        }

        $values = [];
        $missing = [];
        $evidence = [];
        foreach ($needed as $id => $unit) {
            $evidence[] = $matrix->evidence($id) ?? ['id' => $id, 'unit' => $unit, 'value' => null, 'source' => 'NOT_SUPPLIED', 'approval_status' => 'not_approved_for_commercial_use'];
            try {
                $value = $matrix->optionalDecimal($id, $unit);
            } catch (\InvalidArgumentException $exception) {
                throw new CatalogUnavailable('Invalid unit in commercial matrix.', 0, $exception);
            }
            if ($value === null) {
                $missing[] = $id;
            } else {
                $values[$id] = $value;
            }
        }
        $fingerprint = hash('sha256', json_encode([
            'engine' => self::VERSION,
            'matrix_sha256' => $matrix->fingerprint(),
            'input' => $input->normalized(),
        ], JSON_THROW_ON_ERROR));
        $base = [
            'engine_version' => self::VERSION,
            'status' => 'incomplete',
            'parameters' => $matrix->metadata(),
            'evidence' => $evidence,
            'input' => $input->normalized(),
            'simulation_fingerprint' => $fingerprint,
            'commercialization' => ['quote_allowed' => false, 'activation_allowed' => false],
            'credit_unit_status' => $model === 'customer_infrastructure' ? 'not_applicable' : 'PROVISIONAL_NOT_METERED',
        ];
        if ($missing !== []) {
            return $base + ['missing_parameters' => $missing, 'calculation' => null];
        }

        $rates = [];
        foreach (['simulation.tax', 'simulation.default', 'simulation.target_margin', 'offer.common.payment_rate'] as $id) {
            $value = $values[$id];
            if ($value->compareTo('0') < 0 || $value->compareTo('1') > 0) {
                throw new InvalidSimulationInput('An economic rate exceeds [0,1].');
            }
            $rates[$id] = $value;
        }
        $margin = $rates['simulation.target_margin'];
        $fees = $rates['simulation.tax']
            ->plus($rates['simulation.default'])
            ->plus($rates['offer.common.payment_rate']);
        $denominator = BigDecimal::one()->minus($fees)->minus($margin);
        if ($denominator->compareTo(0) <= 0) {
            throw new InvalidSimulationInput('Unviable tax, payment and margin assumptions.');
        }
        if ($model === 'cloud' || $model === 'managed') {
            foreach ([
                'offer.'.$model.'.node_credit_capacity',
                ...($model === 'cloud' ? ['offer.cloud.node_contract_capacity'] : []),
            ] as $key) {
                if ($values[$key]->compareTo(0) <= 0 || $values[$key]->compareTo(1000000000) > 0) {
                    throw new InvalidSimulationInput('Invalid capacity coefficient.');
                }
            }
        }
        $topupRate = BigDecimal::of($input->topupTakeRate);
        $overage = max(0, $input->creditsPerAccount - $input->includedCredits);
        $packs = intdiv($overage + $input->topupCredits - 1, $input->topupCredits);
        // Unpurchased over-quota demand is blocked, not served for free.
        $served = BigDecimal::of(min($input->creditsPerAccount, $input->includedCredits))
            ->plus(BigDecimal::of($overage)->multipliedBy($topupRate));
        $averagePacks = BigDecimal::of($packs)->multipliedBy($topupRate);

        $hourly = $values[$prefix.'.collective_hour'];
        $setupHours = BigDecimal::of($input->setupExtraHours);
        if ($model !== 'cloud' && $input->includeSetup) {
            $setupHours = $setupHours->plus($values[$prefix.'.setup_hours']);
        }
        if ($model === 'customer_infrastructure' && $input->remoteAccess) {
            $setupHours = $setupHours->plus($values['offer.customer_infrastructure.remote_setup_hours']);
        }
        $setupCost = $setupHours->multipliedBy($hourly);
        $projectRequired = $model !== 'cloud' && ($input->includeSetup || $input->remoteAccess);
        $setupRecommended = $projectRequired
            ? $setupCost->plus($values['offer.common.payment_fixed'])->dividedBy($denominator, 12, RoundingMode::HalfUp)
            : null;
        $setupCandidate = $input->candidateSetupPrice === null
            ? $setupRecommended : BigDecimal::of($input->candidateSetupPrice);
        $setupNet = $setupCandidate?->multipliedBy(BigDecimal::one()->minus($fees))
            ->minus($values['offer.common.payment_fixed'])->minus($setupCost);

        $topupFloor = $model === 'customer_infrastructure'
            ? null
            : $values['offer.common.unit_credit_cost']->multipliedBy($input->topupCredits)
                ->plus($values['offer.common.payment_fixed'])
                ->dividedBy($denominator, 12, RoundingMode::HalfUp);

        $calculation = [
            'recurring_enabled' => $recurring,
            'topup_reference_floor' => $topupFloor === null ? null : $this->money($topupFloor),
            'monthly_candidate' => $input->candidateMonthlyPrice,
            'monthly_floor_expected' => null,
            'monthly_floor_full_allowance' => null,
            'annual_subscription' => null,
            'annual_effective_monthly' => null,
            'annual_reference_floor' => null,
            'annual_subscription_floor' => null,
            'setup_recommended' => $setupRecommended === null ? null : $this->money($setupRecommended),
            'setup_candidate' => $model === 'cloud' || $input->candidateSetupPrice === null ? null : $this->money($setupCandidate),
            'setup_cost' => $projectRequired ? $this->money($setupCost) : null,
            'setup_hours' => $projectRequired ? (string) $setupHours : null,
            'setup_net_contribution' => $model === 'cloud' || $input->candidateSetupPrice === null ? null : $this->money($setupNet),
            'included_credits' => $model === 'customer_infrastructure' ? null : $input->includedCredits,
            'average_credits_requested' => $model === 'customer_infrastructure' ? null : $input->creditsPerAccount,
            'average_credits_served' => $model === 'customer_infrastructure' ? null : (string) $served,
            'additional_packs_if_fully_recharged' => $model === 'customer_infrastructure' ? null : $packs,
            'average_packs_purchased' => $model === 'customer_infrastructure' ? null : (string) $averagePacks,
            'topup_price' => $input->candidateTopupPrice,
            'topup_take_rate' => $input->topupTakeRate,
            'periods' => null,
            'sensitivity' => [],
        ];

        if ($recurring) {
            $forAccountCounts = array_unique(array_filter([
                ...($model === 'cloud' ? [10, 100, 1000] : ($model === 'managed' ? [1, 5, 20] : [1, 10, 50])),
                $input->accounts,
            ], static fn (int $n): bool => $n > 0 && $n <= 1000000));
            sort($forAccountCounts);
            $main = null;
            foreach ($forAccountCounts as $n) {
                $projection = $this->project($input, $values, $rates, $served, $averagePacks, $denominator, $n);
                $calculation['sensitivity'][] = $projection;
                if ($n === $input->accounts) {
                    $main = $projection;
                }
            }
            if ($main === null) {
                throw new \LogicException('Missing selected projection.');
            }
            $calculation = array_replace($calculation, [
                'monthly_floor_expected' => $main['required_monthly_price'],
                'monthly_floor_full_allowance' => $main['required_monthly_price_full_allowance'],
                'annual_reference_floor' => $main['annual_reference_floor'],
                'annual_subscription_floor' => $main['annual_subscription_floor'],
                'annual_subscription' => $main['annual_subscription'],
                'annual_effective_monthly' => $main['annual_effective_monthly'],
                'periods' => $main['periods'],
                'monthly_candidate' => $main['monthly_candidate'],
            ]);
        }

        return array_replace($base, [
            'status' => 'calculated',
            'missing_parameters' => [],
            'calculation' => $calculation,
        ]);
    }

    /**
     * @param array<string, BigDecimal> $values
     * @param array<string, BigDecimal> $rates
     * @return array<string, mixed>
     */
    private function project(
        CommercialOfferInput $input,
        array $values,
        array $rates,
        BigDecimal $servedCredits,
        BigDecimal $averagePacks,
        BigDecimal $denominator,
        int $accounts,
    ): array {
        $model = $input->model;
        $cloud = $model === 'cloud';
        $managed = $model === 'managed';
        $subscriptionMoney = $input->candidateMonthlyPrice === null ? null : BigDecimal::of($input->candidateMonthlyPrice);
        $topupPrice = $input->candidateTopupPrice === null ? BigDecimal::zero() : BigDecimal::of($input->candidateTopupPrice);
        $discount = BigDecimal::one()->minus($input->annualDiscount);
        $units = $servedCredits->multipliedBy($accounts);
        $platform = BigDecimal::zero();
        $operations = BigDecimal::zero();
        $hosting = BigDecimal::zero();
        $unitCost = BigDecimal::zero();
        $marketing = BigDecimal::zero();
        $nodes = 0;
        $supportCost = BigDecimal::zero();

        if ($cloud || $managed) {
            $unitCost = $units->multipliedBy($values['offer.common.unit_credit_cost']);
            $platform = $values['offer.common.platform_per_contract']->multipliedBy($accounts);
            $marketing = $values['offer.common.cac_per_new_contract']
                ->multipliedBy($input->acquisitionRate)->multipliedBy($accounts);

            if ($cloud) {
                $contractCapacity = (int) (string) $values['offer.cloud.node_contract_capacity'];
                $creditCapacity = (int) (string) $values['offer.cloud.node_credit_capacity'];
                $nodes = max(
                    intdiv($accounts + $contractCapacity - 1, $contractCapacity),
                    $this->ceilUnits($units, $creditCapacity),
                );
                $hosting = $values['offer.cloud.host_node_monthly']->multipliedBy($nodes);
                $operations = $values['offer.cloud.shared_operations_monthly'];
            } else {
                $creditCapacity = (int) (string) $values['offer.managed.node_credit_capacity'];
                $nodesPerContract = max(1, $this->ceilUnits($servedCredits, $creditCapacity));
                $nodes = $accounts * $nodesPerContract;
                $hosting = $values['offer.managed.dedicated_node_monthly']->multipliedBy($nodes);
                $operations = $values['offer.managed.operations_hours_monthly']
                    ->multipliedBy($values['simulation.managed.collective_hour'])->multipliedBy($accounts);
                $supportCost = BigDecimal::of($input->supportHours)
                    ->multipliedBy($values['simulation.managed.collective_hour'])->multipliedBy($accounts);
            }
        } else {
            $supportHours = BigDecimal::of($input->supportHours)
                ->plus($values['offer.customer_infrastructure.service_coordination_hours_monthly']);
            if ($input->remoteAccess) {
                $supportHours = $supportHours->plus($values['offer.customer_infrastructure.remote_maintenance_hours_monthly']);
            }
            $supportCost = $supportHours
                ->multipliedBy($values['simulation.customer_infrastructure.collective_hour'])->multipliedBy($accounts);
        }
        $fixedOperating = $hosting->plus($operations)->plus($platform)->plus($supportCost);
        $operatingCost = $fixedOperating->plus($unitCost)->plus($marketing);

        // Subscription prices must survive all included credits being exercised.
        // They do not rely on uncertain future recharge revenue.
        $baseUnits = min($input->creditsPerAccount, $input->includedCredits);
        $baseUnitCost = $cloud || $managed
            ? BigDecimal::of($baseUnits)->multipliedBy($values['offer.common.unit_credit_cost'])->multipliedBy($accounts)
            : BigDecimal::zero();
        $fullAllowanceCost = $cloud || $managed
            ? BigDecimal::of($input->includedCredits)->multipliedBy($values['offer.common.unit_credit_cost'])->multipliedBy($accounts)
            : BigDecimal::zero();
        $hostingAtFullAllowance = $hosting;
        if ($cloud) {
            $allowedNodes = max(
                intdiv($accounts + $contractCapacity - 1, $contractCapacity),
                $this->ceilUnits(BigDecimal::of($accounts)->multipliedBy($input->includedCredits), $creditCapacity),
            );
            $hostingAtFullAllowance = $values['offer.cloud.host_node_monthly']->multipliedBy($allowedNodes);
        } elseif ($managed) {
            $allowedNodes = max(1, $this->ceilUnits(BigDecimal::of($input->includedCredits), $creditCapacity));
            $hostingAtFullAllowance = $values['offer.managed.dedicated_node_monthly']->multipliedBy($accounts * $allowedNodes);
        }
        $operatingAtFullAllowance = $fixedOperating->minus($hosting)->plus($hostingAtFullAllowance);
        $paymentFixed = $values['offer.common.payment_fixed'];
        $accountFixedFees = $paymentFixed->multipliedBy($accounts);
        $floor = $fixedOperating->plus($marketing)->plus($baseUnitCost)->plus($accountFixedFees)
            ->dividedBy($accounts, 12, RoundingMode::HalfUp)->dividedBy($denominator, 12, RoundingMode::HalfUp);
        $fullAllowanceFloor = $operatingAtFullAllowance->plus($marketing)->plus($fullAllowanceCost)->plus($accountFixedFees)
            ->dividedBy($accounts, 12, RoundingMode::HalfUp)->dividedBy($denominator, 12, RoundingMode::HalfUp);
        $annualFixedFees = $paymentFixed->multipliedBy($accounts)->dividedBy(12, 12, RoundingMode::HalfUp);
        $annualFloor = $fixedOperating->plus($marketing)->plus($baseUnitCost)->plus($annualFixedFees)
            ->dividedBy($accounts, 12, RoundingMode::HalfUp)->dividedBy($denominator, 12, RoundingMode::HalfUp)
            ->dividedBy($discount, 12, RoundingMode::HalfUp);

        $periods = [];
        foreach (['monthly', 'annual'] as $period) {
            if ($subscriptionMoney === null) {
                $periods[$period] = null;
                continue;
            }
            $subscriptionEffective = $period === 'annual' ? $subscriptionMoney->multipliedBy($discount) : $subscriptionMoney;
            $subscriptionTotal = $subscriptionEffective->multipliedBy($accounts);
            $topupRevenue = ($cloud || $managed) ? $averagePacks->multipliedBy($topupPrice)->multipliedBy($accounts) : BigDecimal::zero();
            $revenue = $subscriptionTotal->plus($topupRevenue);
            $subscriptionPaymentFixed = $period === 'annual'
                ? $paymentFixed->multipliedBy($accounts)->dividedBy(12, 12, RoundingMode::HalfUp)
                : $paymentFixed->multipliedBy($accounts);
            $topupPaymentFixed = $averagePacks->multipliedBy($accounts)->multipliedBy($paymentFixed);
            $paymentCost = $revenue->multipliedBy($rates['offer.common.payment_rate'])
                ->plus($subscriptionPaymentFixed)->plus($topupPaymentFixed);
            $taxAndRisk = $revenue->multipliedBy($rates['simulation.tax']->plus($rates['simulation.default']));
            $net = $revenue->minus($paymentCost)->minus($taxAndRisk)->minus($operatingCost);
            $periods[$period] = [
                'gross_revenue' => $this->money($revenue),
                'subscription_revenue' => $this->money($subscriptionTotal),
                'topup_revenue' => $this->money($topupRevenue),
                'operating_cost' => $this->money($operatingCost),
                'hosting' => $this->money($hosting),
                'platform' => $this->money($platform),
                'operations' => $this->money($operations),
                'support' => $this->money($supportCost),
                'credits_served_cost' => $this->money($unitCost),
                'customer_acquisition' => $this->money($marketing),
                'payment_cost' => $this->money($paymentCost),
                'tax_and_default' => $this->money($taxAndRisk),
                'net_contribution' => $this->money($net),
                'net_margin_pct' => $revenue->compareTo(0) > 0
                    ? $this->money($net->multipliedBy(100)->dividedBy($revenue, 12, RoundingMode::HalfUp))
                    : null,
            ];
        }
        return [
            'accounts' => $accounts,
            'hosting_nodes' => $nodes,
            'average_credits_delivered_per_account' => $cloud || $managed ? (string) $servedCredits : null,
            'fixed_operating_cost' => $this->money($fixedOperating),
            'total_operating_cost' => $this->money($operatingCost),
            'required_monthly_price' => $this->money($floor),
            'required_monthly_price_full_allowance' => $this->money($fullAllowanceFloor),
            'annual_reference_floor' => $this->money($annualFloor),
            'annual_subscription_floor' => $this->money($annualFloor->multipliedBy(12)->multipliedBy($discount)),
            'monthly_candidate' => $input->candidateMonthlyPrice,
            'annual_subscription' => $subscriptionMoney === null ? null
                : $this->money($subscriptionMoney->multipliedBy(12)->multipliedBy($discount)),
            'annual_effective_monthly' => $subscriptionMoney === null ? null
                : $this->money($subscriptionMoney->multipliedBy($discount)),
            'periods' => $periods,
        ];
    }

    private function ceilUnits(BigDecimal $units, int $capacity): int
    {
        return (int) (string) $units->dividedBy($capacity, 0, RoundingMode::Ceiling);
    }

    private function money(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HalfUp);
    }
}
