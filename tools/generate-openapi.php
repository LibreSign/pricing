<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$target = $root.'/docs/openapi.yaml';
$check = ($argv[1] ?? '') === '--check';
if (isset($argv[1]) && !$check) {
    fwrite(STDERR, "Usage: php tools/generate-openapi.php [--check]\n");
    exit(2);
}

$process = new Process(
    [PHP_BINARY, $root.'/bin/console', 'nelmio:apidoc:dump', '--format=yaml'],
    $root,
    ['APP_ENV' => 'dev', 'APP_SECRET' => 'synthetic-docs-only', 'PRICING_CATALOG_PATH' => ''],
);
$process->mustRun();

$spdx = 'SPDX';
$generated = "# {$spdx}-FileCopyrightText: 2026 LibreSign contributors\n"
    ."# {$spdx}-License-Identifier: AGPL-3.0-or-later\n"
    .rtrim(str_replace("\r\n", "\n", $process->getOutput()))."\n";

if ($check) {
    if (!is_file($target) || file_get_contents($target) !== $generated) {
        fwrite(STDERR, "OpenAPI drift: run composer api:generate and commit the generated result.\n");
        exit(1);
    }

    echo "OpenAPI specification matches application routes and attributes.\n";
    exit(0);
}

if (file_put_contents($target, $generated) === false) {
    throw new \RuntimeException('Cannot write generated OpenAPI contract.');
}

echo "Generated docs/openapi.yaml from application attributes.\n";
