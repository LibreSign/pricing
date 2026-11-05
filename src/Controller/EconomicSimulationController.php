<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Controller;

use LibreSign\Pricing\Domain\EconomicSimulationEngine;
use LibreSign\Pricing\Domain\InvalidSimulationInput;
use LibreSign\Pricing\Http\ApiProblem;
use LibreSign\Pricing\Http\EconomicBearerAuthenticator;
use LibreSign\Pricing\Http\EconomicRequestParser;
use LibreSign\Pricing\Infrastructure\CatalogUnavailable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EconomicSimulationController
{
    private const RESPONSE_HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private EconomicSimulationEngine $engine,
        private EconomicBearerAuthenticator $authenticator,
        private EconomicRequestParser $parser,
        private string $quotesEnabled,
    ) {
    }

    #[Route('/v1/economic-simulations', methods: ['POST'])]
    public function simulate(Request $request): JsonResponse
    {
        try {
            $this->authenticator->assertAnalyst($request);
            $input = $this->parser->parse($request);
            $result = $this->engine->simulate($input);

            return new JsonResponse($result, 200, self::RESPONSE_HEADERS);
        } catch (ApiProblem $problem) {
            return new JsonResponse($problem->toArray(), $problem->status, self::RESPONSE_HEADERS);
        } catch (InvalidSimulationInput) {
            return new JsonResponse(['error' => 'invalid_economic_input'], 422, self::RESPONSE_HEADERS);
        } catch (CatalogUnavailable) {
            return new JsonResponse(['error' => 'economic_matrix_unavailable'], 503, self::RESPONSE_HEADERS);
        }
    }

    #[Route('/v1/economic-quotes', methods: ['POST'])]
    public function quote(Request $request): JsonResponse
    {
        try {
            $this->authenticator->assertQuotes($request);
            if ($this->quotesEnabled !== '1') {
                return new JsonResponse(['error' => 'quotes_disabled'], 403, self::RESPONSE_HEADERS);
            }
            $input = $this->parser->parse($request);
            $result = $this->engine->simulate($input);

            if ($result['status'] !== 'calculated' || !$result['commercialization']['quote_allowed']) {
                return new JsonResponse(['error' => 'quote_not_approved'], 409, self::RESPONSE_HEADERS);
            }

            // Sales consumers never receive costs, hourly return, tax rates or raw matrix data.
            return new JsonResponse([
                'simulation_fingerprint' => $result['simulation_fingerprint'],
                'parameter_version' => $result['parameters']['version'],
                'model' => $input->model,
                'billing_basis' => $result['billing_basis'],
                'currency' => $result['currency'],
                'candidate_quote_price' => $result['calculation']['preservation_price_with_margin'],
                'activation_allowed' => false,
            ], 200, self::RESPONSE_HEADERS);
        } catch (ApiProblem $problem) {
            return new JsonResponse($problem->toArray(), $problem->status, self::RESPONSE_HEADERS);
        } catch (InvalidSimulationInput) {
            return new JsonResponse(['error' => 'invalid_economic_input'], 422, self::RESPONSE_HEADERS);
        } catch (CatalogUnavailable) {
            return new JsonResponse(['error' => 'economic_matrix_unavailable'], 503, self::RESPONSE_HEADERS);
        }
    }
}
