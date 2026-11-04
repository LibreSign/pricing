<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\SimulationInput;
use LibreSign\Pricing\Domain\SimulationUsage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class OpenApiContractTest extends TestCase
{
    public function testGeneratedSpecificationReflectsRuntimeConstraints(): void
    {
        $document = Yaml::parseFile(dirname(__DIR__, 2).'/docs/openapi.yaml');
        $schemas = $document['components']['schemas'];
        $input = $schemas['SimulationInput'];
        $usage = $schemas['SimulationUsage'];

        self::assertSame(SimulationInput::MODELS, $input['properties']['model']['enum']);
        self::assertSame(SimulationInput::VERSION_PATTERN, $input['properties']['parameter_version']['pattern']);
        self::assertSame(SimulationInput::PRICE_PATTERN, $input['properties']['candidate_price']['pattern']);
        self::assertSame(SimulationUsage::MAX_DOCUMENTS, $usage['properties']['documents']['maximum']);
        self::assertSame(SimulationUsage::MAX_STORAGE_GB, $usage['properties']['storage_gb']['maximum']);
        self::assertSame(SimulationUsage::MAX_SERVICE_HOURS, $usage['properties']['service_hours']['maximum']);
        self::assertFalse($input['additionalProperties']);
        self::assertFalse($usage['additionalProperties']);
        self::assertSame(['BRL'], $schemas['SimulationResponse']['properties']['currency']['enum']);
    }

    public function testRouteAndSecurityAreExposedByGeneratedContract(): void
    {
        $document = Yaml::parseFile(dirname(__DIR__, 2).'/docs/openapi.yaml');
        $post = $document['paths']['/v1/simulations']['post'];

        self::assertSame('simulatePricing', $post['operationId']);
        self::assertSame(
            ['200', '400', '401', '413', '415', '422', '503'],
            array_map('strval', array_keys($post['responses'])),
        );
        self::assertSame('bearer', $document['components']['securitySchemes']['bearerAuth']['scheme']);
        self::assertSame([['bearerAuth' => []]], $document['security']);
        self::assertSame(
            ['NON_HOMOLOGATED_SYNTHETIC_PARAMETERS'],
            $document['components']['schemas']['SimulationResponse']
                ['properties']['commercialization']['properties']['reason_code']['enum'],
        );
    }
}
