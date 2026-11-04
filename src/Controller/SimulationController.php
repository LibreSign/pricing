<?php

declare(strict_types=1);

namespace LibreSign\Pricing\Controller;

use LibreSign\Pricing\Domain\InvalidSimulationInput;
use LibreSign\Pricing\Domain\SimulationEngine;
use LibreSign\Pricing\Domain\SimulationInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SimulationController
{
    public function __construct(private SimulationEngine $engine)
    {
    }
    #[Route('/v1/simulations', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        $configured = $_SERVER['PRICING_API_TOKEN'] ?? $_ENV['PRICING_API_TOKEN'] ?? getenv('PRICING_API_TOKEN');
        if (!is_string($configured) || $configured === '' || !hash_equals('Bearer '.$configured, (string) $request->headers->get('Authorization'))) {
            return new JsonResponse(['error' => 'unauthorized'], 401, $headers);
        }
        if (!str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'application/json')) {
            return new JsonResponse(['error' => 'content_type_not_supported'], 415, $headers);
        }
        if (strlen($request->getContent()) > 16384) {
            return new JsonResponse(['error' => 'request_too_large'], 413, $headers);
        }
        try {
            $decoded = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new InvalidSimulationInput('JSON object required.');
            }
        } catch (\JsonException $exception) {
            return new JsonResponse(['error' => 'invalid_json'], 400, $headers);
        } catch (InvalidSimulationInput $exception) {
            return new JsonResponse(['error' => 'invalid_input', 'detail' => $exception->getMessage()], 422, $headers);
        }
        try {
            return new JsonResponse($this->engine->simulate(SimulationInput::fromArray($decoded)), 200, $headers);
        } catch (InvalidSimulationInput $exception) {
            return new JsonResponse(['error' => 'invalid_input', 'detail' => $exception->getMessage()], 422, $headers);
        }
    }
}
