<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Controller;

use LibreSign\Pricing\Api\SimulationResponse;
use LibreSign\Pricing\Domain\InvalidSimulationInput;
use LibreSign\Pricing\Domain\SimulationEngine;
use LibreSign\Pricing\Domain\SimulationInput;
use LibreSign\Pricing\Http\ApiProblem;
use LibreSign\Pricing\Http\BearerAuthenticator;
use LibreSign\Pricing\Http\SimulationRequestParser;
use LibreSign\Pricing\Infrastructure\CatalogUnavailable;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SimulationController
{
    private const RESPONSE_HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private SimulationEngine $engine,
        private BearerAuthenticator $authenticator,
        private SimulationRequestParser $parser,
    ) {
    }

    #[Route('/v1/simulations', methods: ['POST'])]
    #[OA\Post(
        operationId: 'simulatePricing',
        summary: 'Calculate a fictional, non-binding pricing scenario',
        tags: ['Simulations'],
    )]
    #[OA\RequestBody(required: true, content: new Model(type: SimulationInput::class))]
    #[OA\Response(
        response: 200,
        description: 'Synthetic simulation; never an approved price',
        content: new Model(type: SimulationResponse::class),
    )]
    #[OA\Response(response: 400, description: 'Malformed JSON')]
    #[OA\Response(response: 401, description: 'Missing or invalid authentication')]
    #[OA\Response(response: 413, description: 'Request too large')]
    #[OA\Response(response: 415, description: 'Unsupported content type')]
    #[OA\Response(response: 422, description: 'Invalid input or unknown parameter version')]
    #[OA\Response(response: 503, description: 'Synthetic catalog is unavailable')]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->authenticator->assertAuthenticated($request);
            $input = $this->parser->parse($request);
            $result = $this->engine->simulate($input);

            return new JsonResponse(SimulationResponse::fromArray($result), 200, self::RESPONSE_HEADERS);
        } catch (ApiProblem $problem) {
            return new JsonResponse($problem->toArray(), $problem->status, self::RESPONSE_HEADERS);
        } catch (InvalidSimulationInput $exception) {
            return new JsonResponse(
                ['error' => 'invalid_input', 'detail' => $exception->getMessage()],
                422,
                self::RESPONSE_HEADERS,
            );
        } catch (CatalogUnavailable) {
            return new JsonResponse(['error' => 'pricing_catalog_unavailable'], 503, self::RESPONSE_HEADERS);
        }
    }
}
