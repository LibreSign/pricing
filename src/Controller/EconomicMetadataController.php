<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Controller;

use LibreSign\Pricing\Domain\EconomicScenarioFields;
use LibreSign\Pricing\Http\ApiProblem;
use LibreSign\Pricing\Http\EconomicBearerAuthenticator;
use LibreSign\Pricing\Infrastructure\CatalogUnavailable;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EconomicMetadataController
{
    private const HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private EconomicMatrixLoader $loader,
        private EconomicBearerAuthenticator $authenticator,
    ) {
    }

    #[Route('/v1/economic-metadata', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->authenticator->assertAnalyst($request);
            $matrix = $this->loader->load();

            return new JsonResponse([
                'parameters' => $matrix->metadata(),
                'currency' => 'BRL',
                'models' => [
                    'cloud' => $matrix->model('cloud')['billing_basis'],
                    'managed' => $matrix->model('managed')['billing_basis'],
                    'customer_infrastructure' => $matrix->model('customer_infrastructure')['billing_basis'],
                ],
                'scenario_fields' => EconomicScenarioFields::metadata(),
            ], 200, self::HEADERS);
        } catch (ApiProblem $problem) {
            return new JsonResponse($problem->toArray(), $problem->status, self::HEADERS);
        } catch (CatalogUnavailable) {
            return new JsonResponse(['error' => 'economic_matrix_unavailable'], 503, self::HEADERS);
        }
    }
}
