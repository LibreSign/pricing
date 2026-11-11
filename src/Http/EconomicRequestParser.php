<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Http;

use LibreSign\Pricing\Domain\EconomicScenarioInput;
use Symfony\Component\HttpFoundation\Request;

final readonly class EconomicRequestParser
{
    public function parse(Request $request): EconomicScenarioInput
    {
        $type = strtolower((string) $request->headers->get('Content-Type'));
        if (!preg_match('~^application/json(?:\s*;|$)~', $type)) {
            throw new ApiProblem(415, 'content_type_not_supported');
        }
        $body = $request->getContent();
        if (strlen($body) > 16384) {
            throw new ApiProblem(413, 'request_too_large');
        }
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'invalid_json');
        }
        if (!is_array($decoded) || array_is_list($decoded)
            || array_diff(array_keys($decoded), ['model', 'parameter_version', 'customers', 'candidate_price', 'candidate_setup_price', 'scenario']) !== []) {
            throw new ApiProblem(422, 'invalid_input');
        }
        if (!is_string($decoded['model'] ?? null)
            || !is_string($decoded['parameter_version'] ?? null)
            || !is_int($decoded['customers'] ?? null)
            || (array_key_exists('candidate_price', $decoded) && $decoded['candidate_price'] !== null && !is_string($decoded['candidate_price']))
            || (array_key_exists('candidate_setup_price', $decoded) && $decoded['candidate_setup_price'] !== null && !is_string($decoded['candidate_setup_price']))
            || (array_key_exists('scenario', $decoded) && (!is_array($decoded['scenario']) || ($decoded['scenario'] !== [] && array_is_list($decoded['scenario']))))) {
            throw new ApiProblem(422, 'invalid_input');
        }

        return new EconomicScenarioInput(
            $decoded['model'],
            $decoded['parameter_version'],
            $decoded['customers'],
            $decoded['candidate_price'] ?? null,
            $decoded['scenario'] ?? [],
            $decoded['candidate_setup_price'] ?? null,
        );
    }
}
