<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

final readonly class EconomicScenarioInput
{
    /** @var array<string, int|string|bool> */
    public array $scenario;

    /** @param array<string, mixed>|null $scenario */
    public function __construct(
        public string $model,
        public string $parameterVersion,
        public int $customers,
        public ?string $candidatePrice,
        ?array $scenario = null,
        public ?string $candidateSetupPrice = null,
    ) {
        if (!in_array($this->model, ['cloud', 'managed', 'customer_infrastructure'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $this->parameterVersion) !== 1
            || $this->customers < 1
            || $this->customers > 1000000
            || !self::priceOrNull($this->candidatePrice)
            || !self::priceOrNull($this->candidateSetupPrice)) {
            throw new InvalidSimulationInput('Invalid economic scenario input.');
        }

        $this->scenario = EconomicScenarioFields::normalize($model, $scenario ?? []);
        if ($model === 'customer_infrastructure'
            && !$this->scenario['recurring_support']
            && $this->candidatePrice !== null) {
            throw new InvalidSimulationInput('A recurring candidate price requires recurring support.');
        }
    }

    /** @return array<string, mixed> */
    public function normalized(): array
    {
        return [
            'model' => $this->model,
            'parameter_version' => $this->parameterVersion,
            'customers' => $this->customers,
            'scenario' => $this->scenario,
            'candidate_price' => $this->candidatePrice,
            'candidate_setup_price' => $this->candidateSetupPrice,
        ];
    }

    private static function priceOrNull(?string $value): bool
    {
        return $value === null || preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D', $value) === 1;
    }
}
