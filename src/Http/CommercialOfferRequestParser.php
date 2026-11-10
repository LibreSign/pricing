<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Http;

use LibreSign\Pricing\Domain\CommercialOfferInput;
use Symfony\Component\HttpFoundation\Request;

final readonly class CommercialOfferRequestParser
{
    public function parse(Request $request): CommercialOfferInput
    {
        if (!preg_match('~^application/json(?:\s*;|$)~', strtolower((string) $request->headers->get('Content-Type')))) {
            throw new ApiProblem(415, 'content_type_not_supported');
        }
        $body = $request->getContent();
        if (strlen($body) > 16384) {
            throw new ApiProblem(413, 'request_too_large');
        }
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'invalid_json');
        }
        if (!is_array($data) || array_is_list($data)
            || array_diff(array_keys($data), [
                'model', 'parameter_version', 'accounts', 'credits_per_account', 'included_credits', 'topup_credits',
                'topup_take_rate', 'annual_discount', 'acquisition_rate', 'candidate_monthly_price',
                'candidate_topup_price', 'candidate_setup_price', 'support_hours', 'setup_extra_hours',
                'remote_access', 'recurring_maintenance', 'include_setup',
            ]) !== []
            || !is_string($data['model'] ?? null)
            || !is_string($data['parameter_version'] ?? null)
            || !is_int($data['accounts'] ?? null)
            || !is_int($data['credits_per_account'] ?? null)
            || !is_int($data['included_credits'] ?? null)
            || !is_int($data['topup_credits'] ?? null)
            || !is_string($data['topup_take_rate'] ?? null)
            || !is_string($data['annual_discount'] ?? null)
            || !is_string($data['acquisition_rate'] ?? null)
            || (isset($data['candidate_monthly_price']) && !is_string($data['candidate_monthly_price']))
            || (isset($data['candidate_topup_price']) && !is_string($data['candidate_topup_price']))
            || (isset($data['candidate_setup_price']) && !is_string($data['candidate_setup_price']))
            || (isset($data['support_hours']) && !is_string($data['support_hours']))
            || (isset($data['setup_extra_hours']) && !is_string($data['setup_extra_hours']))
            || (isset($data['remote_access']) && !is_bool($data['remote_access']))
            || (isset($data['recurring_maintenance']) && !is_bool($data['recurring_maintenance']))
            || (isset($data['include_setup']) && !is_bool($data['include_setup']))) {
            throw new ApiProblem(422, 'invalid_input');
        }

        return new CommercialOfferInput(
            model: $data['model'],
            parameterVersion: $data['parameter_version'],
            accounts: $data['accounts'],
            creditsPerAccount: $data['credits_per_account'],
            includedCredits: $data['included_credits'],
            topupCredits: $data['topup_credits'],
            topupTakeRate: $data['topup_take_rate'],
            annualDiscount: $data['annual_discount'],
            acquisitionRate: $data['acquisition_rate'],
            candidateMonthlyPrice: $data['candidate_monthly_price'] ?? null,
            candidateTopupPrice: $data['candidate_topup_price'] ?? null,
            candidateSetupPrice: $data['candidate_setup_price'] ?? null,
            supportHours: $data['support_hours'] ?? '0',
            setupExtraHours: $data['setup_extra_hours'] ?? '0',
            remoteAccess: $data['remote_access'] ?? false,
            recurringMaintenance: $data['recurring_maintenance'] ?? true,
            includeSetup: $data['include_setup'] ?? true,
        );
    }
}
