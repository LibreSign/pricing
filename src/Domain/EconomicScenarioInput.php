<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

final readonly class EconomicScenarioInput
{
    public function __construct(
        public string $model,
        public string $parameterVersion,
        public int $customers,
        public ?string $candidatePrice,
    ) {
        if (!in_array($this->model, ['cloud', 'managed', 'customer_infrastructure'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $this->parameterVersion) !== 1
            || $this->customers < 1
            || $this->customers > 1000000
            || ($this->candidatePrice !== null
                && preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D', $this->candidatePrice) !== 1)) {
            throw new InvalidSimulationInput('Invalid economic scenario input.');
        }
    }

    /** @return array{model: string, parameter_version: string, customers: int, candidate_price: string|null} */
    public function normalized(): array
    {
        return [
            'model' => $this->model,
            'parameter_version' => $this->parameterVersion,
            'customers' => $this->customers,
            'candidate_price' => $this->candidatePrice,
        ];
    }
}
