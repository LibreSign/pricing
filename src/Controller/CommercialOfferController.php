<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Controller;

use LibreSign\Pricing\Domain\CommercialOfferEngine;
use LibreSign\Pricing\Domain\InvalidSimulationInput;
use LibreSign\Pricing\Http\ApiProblem;
use LibreSign\Pricing\Http\CommercialOfferRequestParser;
use LibreSign\Pricing\Http\EconomicBearerAuthenticator;
use LibreSign\Pricing\Infrastructure\CatalogUnavailable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Internal analyst research only. The quote route never calls this controller.
 */
final readonly class CommercialOfferController
{
    private const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private CommercialOfferEngine $engine,
        private CommercialOfferRequestParser $parser,
        private EconomicBearerAuthenticator $authenticator,
    ) {
    }

    #[Route('/v1/offer-simulations', methods: ['POST'])]
    public function simulate(Request $request): JsonResponse
    {
        try {
            $this->authenticator->assertAnalyst($request);
            $result = $this->engine->simulate($this->parser->parse($request));

            return new JsonResponse($result, 200, self::HEADERS);
        } catch (ApiProblem $problem) {
            return new JsonResponse($problem->toArray(), $problem->status, self::HEADERS);
        } catch (InvalidSimulationInput) {
            return new JsonResponse(['error' => 'invalid_economic_input'], 422, self::HEADERS);
        } catch (CatalogUnavailable) {
            return new JsonResponse(['error' => 'economic_matrix_unavailable'], 503, self::HEADERS);
        }
    }
}
