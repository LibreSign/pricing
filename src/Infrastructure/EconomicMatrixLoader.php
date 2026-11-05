<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Infrastructure;

use LibreSign\Pricing\Domain\EconomicParameterMatrix;

final readonly class EconomicMatrixLoader
{
    public function __construct(private string $filename)
    {
    }

    public function load(): EconomicParameterMatrix
    {
        if ($this->filename === '') {
            throw new CatalogUnavailable('An economic matrix path must be explicitly configured.');
        }
        $content = @file_get_contents($this->filename);
        if (!is_string($content)) {
            throw new CatalogUnavailable('Economic matrix unavailable.');
        }
        try {
            return EconomicParameterMatrix::fromJson($content);
        } catch (\InvalidArgumentException $exception) {
            throw new CatalogUnavailable('Economic matrix invalid.', 0, $exception);
        }
    }
}
