<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\Pricing\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$kernel = new Kernel('prod', false);
$request = Request::create(
    '/v1/simulations',
    'POST',
    [],
    [],
    [],
    [
        'HTTP_AUTHORIZATION' => 'Bearer smoke-test-token',
        'CONTENT_TYPE' => 'application/json',
    ],
    json_encode([
        'model' => 'cloud',
        'parameter_version' => 'synthetic-v1',
        'usage' => [
            'documents' => 0,
            'storage_gb' => 0,
            'service_hours' => 0,
        ],
    ], JSON_THROW_ON_ERROR),
);

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

$payload = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR);
if ($response->getStatusCode() !== 503 || $payload !== ['error' => 'pricing_catalog_unavailable']) {
    fwrite(STDERR, 'Production simulation must fail closed without an external catalog.'.PHP_EOL);
    exit(1);
}

echo 'Production kernel boots without dev dependencies and rejects missing catalogs.'.PHP_EOL;
