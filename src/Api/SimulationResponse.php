<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Api;

use LibreSign\Pricing\Domain\SimulationInput;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(additionalProperties: false)]
final readonly class SimulationResponse implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $input
     * @param array<string, string> $costs
     * @param array<string, array<string, mixed>> $taxScenarios
     * @param array<string, string> $explanation
     * @param array<string, mixed> $commercialization
     */
    public function __construct(
        #[OA\Property(property: 'engine_version', type: 'string')]
        public string $engineVersion,
        #[OA\Property(property: 'simulation_fingerprint', type: 'string', pattern: '^[a-f0-9]{64}$')]
        public string $simulationFingerprint,
        #[OA\Property(
            type: 'object',
            required: ['version', 'sha256', 'synthetic', 'homologated', 'example_only'],
            properties: [
                new OA\Property(property: 'version', type: 'string'),
                new OA\Property(property: 'sha256', type: 'string'),
                new OA\Property(property: 'synthetic', type: 'boolean', enum: [true]),
                new OA\Property(property: 'homologated', type: 'boolean', enum: [false]),
                new OA\Property(property: 'example_only', type: 'boolean', enum: [true]),
            ],
        )]
        public array $parameters,
        #[OA\Property(ref: new Model(type: SimulationInput::class))]
        public array $input,
        #[OA\Property(type: 'string', enum: ['BRL'])]
        public string $currency,
        #[OA\Property(property: 'billing_basis', type: 'string', enum: ['monthly', 'project'])]
        public string $billingBasis,
        #[OA\Property(property: 'synthetic_list_price', type: 'string')]
        public string $syntheticListPrice,
        #[OA\Property(property: 'evaluated_price', type: 'string')]
        public string $evaluatedPrice,
        #[OA\Property(property: 'evaluated_price_source', type: 'string', enum: ['synthetic_catalog', 'candidate_input'])]
        public string $evaluatedPriceSource,
        #[OA\Property(type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'))]
        public array $costs,
        #[OA\Property(
            property: 'tax_scenarios',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'object',
                required: [
                    'synthetic_tax_rate', 'tax_amount', 'incremental_contribution',
                    'full_cost_margin', 'cooperative_residual_potential',
                    'incremental_contribution_positive', 'full_cost_recovered',
                ],
                properties: [
                    new OA\Property(property: 'synthetic_tax_rate', type: 'string'),
                    new OA\Property(property: 'tax_amount', type: 'string'),
                    new OA\Property(property: 'incremental_contribution', type: 'string'),
                    new OA\Property(property: 'full_cost_margin', type: 'string'),
                    new OA\Property(property: 'cooperative_residual_potential', type: 'string'),
                    new OA\Property(property: 'incremental_contribution_positive', type: 'boolean'),
                    new OA\Property(property: 'full_cost_recovered', type: 'boolean'),
                ],
            ),
        )]
        public array $taxScenarios,
        #[OA\Property(type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'))]
        public array $explanation,
        #[OA\Property(
            type: 'object',
            required: ['quote_allowed', 'activation_allowed', 'reason_code'],
            properties: [
                new OA\Property(property: 'quote_allowed', type: 'boolean', enum: [false]),
                new OA\Property(property: 'activation_allowed', type: 'boolean', enum: [false]),
                new OA\Property(property: 'reason_code', type: 'string', enum: ['NON_HOMOLOGATED_SYNTHETIC_PARAMETERS']),
            ],
        )]
        public array $commercialization,
    ) {
    }

    /** @param array<string, mixed> $result */
    public static function fromArray(array $result): self
    {
        return new self(
            $result['engine_version'],
            $result['simulation_fingerprint'],
            $result['parameters'],
            $result['input'],
            $result['currency'],
            $result['billing_basis'],
            $result['synthetic_list_price'],
            $result['evaluated_price'],
            $result['evaluated_price_source'],
            $result['costs'],
            $result['tax_scenarios'],
            $result['explanation'],
            $result['commercialization'],
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'engine_version' => $this->engineVersion,
            'simulation_fingerprint' => $this->simulationFingerprint,
            'parameters' => $this->parameters,
            'input' => $this->input,
            'currency' => $this->currency,
            'billing_basis' => $this->billingBasis,
            'synthetic_list_price' => $this->syntheticListPrice,
            'evaluated_price' => $this->evaluatedPrice,
            'evaluated_price_source' => $this->evaluatedPriceSource,
            'costs' => $this->costs,
            'tax_scenarios' => $this->taxScenarios,
            'explanation' => $this->explanation,
            'commercialization' => $this->commercialization,
        ];
    }
}
