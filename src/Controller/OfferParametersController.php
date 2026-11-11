<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Controller;

use LibreSign\Pricing\Domain\CreditUsage;
use LibreSign\Pricing\Domain\EconomicOfferControls;
use LibreSign\Pricing\Http\ApiProblem;
use LibreSign\Pricing\Http\EconomicBearerAuthenticator;
use LibreSign\Pricing\Infrastructure\CatalogUnavailable;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Confidential research coefficients, distinct from the metadata discovery endpoint. */
final readonly class OfferParametersController
{
    private const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private EconomicMatrixLoader $loader,
        private EconomicBearerAuthenticator $authenticator,
    ) {
    }

    #[Route('/v1/offer-parameters', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->authenticator->assertAnalyst($request);
            $matrix = $this->loader->load();
            $financial = [];
            foreach (EconomicOfferControls::FIELDS as $id => $control) {
                $source = $matrix->evidence($id);
                $financial[] = [
                    'id' => $id,
                    'label' => $control['label'],
                    'value' => $source['value'] ?? null,
                    'unit' => $source['unit'] ?? 'unknown',
                    'source' => $source['source'] ?? 'NOT_SUPPLIED',
                ];
            }
            $weights = [];
            foreach (CreditUsage::COMPONENTS as $id => $component) {
                $source = $matrix->evidence('offer.credit_weight.'.$id);
                $weights[] = [
                    'id' => $id,
                    'label' => $component['label'],
                    'unit' => $component['unit'],
                    'value' => $source['value'] ?? null,
                    'source' => $source['source'] ?? 'NOT_SUPPLIED',
                    'approval_status' => $source['approval_status'] ?? 'not_approved_for_commercial_use',
                ];
            }

            return new JsonResponse([
                'parameters' => $matrix->metadata(),
                'economic_controls' => $financial,
                'credit_factors' => $weights,
            ], 200, self::HEADERS);
        } catch (ApiProblem $problem) {
            return new JsonResponse($problem->toArray(), $problem->status, self::HEADERS);
        } catch (CatalogUnavailable) {
            return new JsonResponse(['error' => 'economic_matrix_unavailable'], 503, self::HEADERS);
        }
    }
}
