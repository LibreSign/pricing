<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

/**
 * A commercial hypothesis, not an approved quote or a usage-ledger contract.
 * Financial values are exact strings, never floating point.
 */
final readonly class CommercialOfferInput
{
    public function __construct(
        public string $model,
        public string $parameterVersion,
        public int $accounts,
        public int $creditsPerAccount,
        public int $includedCredits,
        public int $topupCredits,
        public string $topupTakeRate,
        public string $annualDiscount,
        public string $acquisitionRate,
        public ?string $candidateMonthlyPrice,
        public ?string $candidateTopupPrice,
        public ?string $candidateSetupPrice,
        public string $supportHours = '0',
        public string $setupExtraHours = '0',
        public bool $remoteAccess = false,
        public bool $recurringMaintenance = true,
        public bool $includeSetup = true,
    ) {
        if (!in_array($model, ['cloud', 'managed', 'customer_infrastructure'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $parameterVersion) !== 1
            || $accounts < 1 || $accounts > 1000000
            || $creditsPerAccount < 0 || $creditsPerAccount > 1000000
            || $includedCredits < 0 || $includedCredits > 1000000
            || $topupCredits < 1 || $topupCredits > 1000000
            || !self::fraction($topupTakeRate, '1')
            || !self::fraction($annualDiscount, '0.50')
            || !self::fraction($acquisitionRate, '1')
            || !self::money($candidateMonthlyPrice)
            || !self::money($candidateTopupPrice)
            || !self::money($candidateSetupPrice)
            || !self::hours($supportHours)
            || !self::hours($setupExtraHours)
            || ($model === 'cloud' && ($supportHours !== '0' && $supportHours !== '0.00'))
            || ($model !== 'customer_infrastructure' && (!$recurringMaintenance || $remoteAccess))
            || ($model === 'customer_infrastructure' && !$recurringMaintenance && $candidateMonthlyPrice !== null)
            || ($model === 'customer_infrastructure' && !$recurringMaintenance && !$includeSetup)
            || (!$includeSetup && $setupExtraHours !== '0' && $setupExtraHours !== '0.00')
            || (!$includeSetup && !$remoteAccess && $candidateSetupPrice !== null)) {
            throw new InvalidSimulationInput('Invalid commercial offer hypothesis.');
        }
        if ($model !== 'customer_infrastructure'
            && $creditsPerAccount > $includedCredits
            && \Brick\Math\BigDecimal::of($topupTakeRate)->compareTo(0) > 0
            && $candidateTopupPrice === null) {
            throw new InvalidSimulationInput('A paid top-up scenario requires a candidate top-up price.');
        }
    }

    /** @return array<string, mixed> */
    public function normalized(): array
    {
        return [
            'model' => $this->model,
            'parameter_version' => $this->parameterVersion,
            'accounts' => $this->accounts,
            'credits_per_account' => $this->creditsPerAccount,
            'included_credits' => $this->includedCredits,
            'topup_credits' => $this->topupCredits,
            'topup_take_rate' => $this->topupTakeRate,
            'annual_discount' => $this->annualDiscount,
            'acquisition_rate' => $this->acquisitionRate,
            'candidate_monthly_price' => $this->candidateMonthlyPrice,
            'candidate_topup_price' => $this->candidateTopupPrice,
            'candidate_setup_price' => $this->candidateSetupPrice,
            'support_hours' => $this->supportHours,
            'setup_extra_hours' => $this->setupExtraHours,
            'remote_access' => $this->remoteAccess,
            'recurring_maintenance' => $this->recurringMaintenance,
            'include_setup' => $this->includeSetup,
        ];
    }

    private static function fraction(string $value, string $max): bool
    {
        return preg_match('/^(?:0|1)(?:\.[0-9]{1,4})?$/D', $value) === 1
            && \Brick\Math\BigDecimal::of($value)->compareTo($max) <= 0;
    }

    private static function money(?string $value): bool
    {
        return $value === null
            || preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D', $value) === 1;
    }

    private static function hours(string $value): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/D', $value) === 1;
    }
}
