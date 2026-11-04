<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Infrastructure;

final readonly class SyntheticCatalog
{
    public function __construct(private string $filename)
    {
    }

    /** @return array{data: array<string, mixed>, sha256: string} */
    public function load(): array
    {
        // No fallback to the public test fixture is permitted.
        if ($this->filename === '') {
            throw new CatalogUnavailable('A pricing catalog path must be configured.');
        }

        $content = @file_get_contents($this->filename);
        if ($content === false) {
            throw new CatalogUnavailable('The configured pricing catalog is unavailable.');
        }

        try {
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogUnavailable('The configured pricing catalog is invalid.', 0, $exception);
        }

        if (!is_array($data)
            || ($data['schema_version'] ?? null) !== 1
            || !is_string($data['version'] ?? null)
            || preg_match('/^synthetic-v[1-9][0-9]*$/D', $data['version']) !== 1
            || ($data['homologated'] ?? null) !== false
            || ($data['status'] ?? null) !== 'SYNTHETIC_ONLY'
            || ($data['dataset_kind'] ?? null) !== 'ARTIFICIAL_TEST_DATA'
            || ($data['disclaimer'] ?? null) !== 'FICTIONAL_EXAMPLE_NOT_LIBRECODE_PRICES_OR_COSTS'
            || ($data['currency'] ?? null) !== 'BRL'
            || !is_array($data['models'] ?? null)
            || !is_array($data['tax_scenarios'] ?? null)) {
            throw new CatalogUnavailable('The configured catalog is not an authorized synthetic dataset.');
        }

        return ['data' => $data, 'sha256' => hash('sha256', $content)];
    }
}
