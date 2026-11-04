<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

use OpenApi\Attributes as OA;

#[OA\Schema(additionalProperties: false)]
final readonly class SimulationUsage
{
    public const MAX_DOCUMENTS = 1000000;
    public const MAX_STORAGE_GB = 100000;
    public const MAX_SERVICE_HOURS = 10000;

    public function __construct(
        #[OA\Property(type: 'integer', minimum: 0, maximum: self::MAX_DOCUMENTS)]
        public int $documents,
        #[OA\Property(property: 'storage_gb', type: 'integer', minimum: 0, maximum: self::MAX_STORAGE_GB)]
        public int $storageGb,
        #[OA\Property(property: 'service_hours', type: 'integer', minimum: 0, maximum: self::MAX_SERVICE_HOURS)]
        public int $serviceHours,
    ) {
    }

    public static function fromArray(mixed $raw, string $model): self
    {
        if (!is_array($raw)
            || array_diff(array_keys($raw), ['documents', 'storage_gb', 'service_hours']) !== []) {
            throw new InvalidSimulationInput('Invalid usage.');
        }

        $documents = self::quantity($raw, 'documents', self::MAX_DOCUMENTS);
        $storage = self::quantity($raw, 'storage_gb', self::MAX_STORAGE_GB);
        $hours = self::quantity($raw, 'service_hours', self::MAX_SERVICE_HOURS);

        if ($model === 'customer_infrastructure' && ($documents !== 0 || $storage !== 0)) {
            throw new InvalidSimulationInput('Customer infrastructure has no document or storage charges.');
        }

        return new self($documents, $storage, $hours);
    }

    /** @return array{documents: int, storage_gb: int, service_hours: int} */
    public function toArray(): array
    {
        return [
            'documents' => $this->documents,
            'storage_gb' => $this->storageGb,
            'service_hours' => $this->serviceHours,
        ];
    }

    /** @param array<string, mixed> $raw */
    private static function quantity(array $raw, string $key, int $max): int
    {
        $value = $raw[$key] ?? null;
        if (!is_int($value) || $value < 0 || $value > $max) {
            throw new InvalidSimulationInput('Invalid usage.'.$key);
        }

        return $value;
    }
}
