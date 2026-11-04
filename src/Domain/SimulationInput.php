<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(additionalProperties: false)]
final readonly class SimulationInput
{
    public const MODELS = ['cloud', 'managed', 'customer_infrastructure'];
    public const VERSION_PATTERN = '^[a-z][a-z0-9-]{0,63}$';
    public const PRICE_PATTERN = '^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$';

    private function __construct(
        #[OA\Property(type: 'string', enum: self::MODELS)]
        public string $model,
        #[OA\Property(property: 'parameter_version', type: 'string', pattern: self::VERSION_PATTERN)]
        public string $parameterVersion,
        #[OA\Property(ref: new Model(type: SimulationUsage::class))]
        public SimulationUsage $usage,
        #[OA\Property(property: 'candidate_price', type: 'string', nullable: true, pattern: self::PRICE_PATTERN)]
        public ?string $candidatePrice,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        self::rejectUnknownFields($body);

        $model = self::model($body['model'] ?? null);

        return new self(
            $model,
            self::parameterVersion($body['parameter_version'] ?? null),
            SimulationUsage::fromArray($body['usage'] ?? null, $model),
            self::candidatePrice($body['candidate_price'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function normalized(): array
    {
        return [
            'model' => $this->model,
            'parameter_version' => $this->parameterVersion,
            'usage' => $this->usage->toArray(),
            'candidate_price' => $this->candidatePrice,
        ];
    }

    /** @param array<string, mixed> $body */
    private static function rejectUnknownFields(array $body): void
    {
        if (array_diff(array_keys($body), ['model', 'parameter_version', 'usage', 'candidate_price']) !== []) {
            throw new InvalidSimulationInput('Unsupported request field.');
        }
    }

    private static function model(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::MODELS, true)) {
            throw new InvalidSimulationInput('Invalid model.');
        }

        return $value;
    }

    private static function parameterVersion(mixed $value): string
    {
        if (!is_string($value) || preg_match('/'.self::VERSION_PATTERN.'/D', $value) !== 1) {
            throw new InvalidSimulationInput('Invalid parameter_version.');
        }

        return $value;
    }

    private static function candidatePrice(mixed $value): ?string
    {
        if ($value !== null && (!is_string($value) || preg_match('/'.self::PRICE_PATTERN.'/D', $value) !== 1)) {
            throw new InvalidSimulationInput('Invalid candidate_price.');
        }

        return $value;
    }
}
