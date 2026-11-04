<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Http;

final class ApiProblem extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($error);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->detail === null
            ? ['error' => $this->error]
            : ['error' => $this->error, 'detail' => $this->detail];
    }
}
