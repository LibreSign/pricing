<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Http;

use LibreSign\Pricing\Domain\InvalidSimulationInput;
use LibreSign\Pricing\Domain\SimulationInput;
use Symfony\Component\HttpFoundation\Request;

final readonly class SimulationRequestParser
{
    public const MAX_BODY_BYTES = 16384;

    public function parse(Request $request): SimulationInput
    {
        if (!str_starts_with(
            strtolower((string) $request->headers->get('Content-Type')),
            'application/json',
        )) {
            throw new ApiProblem(415, 'content_type_not_supported');
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new ApiProblem(413, 'request_too_large');
        }

        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'invalid_json');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiProblem(422, 'invalid_input', 'JSON object required.');
        }

        try {
            return SimulationInput::fromArray($decoded);
        } catch (InvalidSimulationInput $exception) {
            throw new ApiProblem(422, 'invalid_input', $exception->getMessage());
        }
    }
}
