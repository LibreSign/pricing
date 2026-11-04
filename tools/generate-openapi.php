<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use LibreSign\Pricing\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require dirname(__DIR__).'/vendor/autoload.php';

$target = dirname(__DIR__).'/docs/openapi.yaml';
$check = ($argv[1] ?? '') === '--check';
if (isset($argv[1]) && !$check) {
    fwrite(STDERR, "Usage: php tools/generate-openapi.php [--check]\n");
    exit(2);
}

// Invoke the Symfony API-documentation command in the same process,
// without an additional process-management library.
$_SERVER['APP_SECRET'] ??= 'synthetic-docs-only';
$application = new Application(new Kernel('dev', true));
$application->setAutoExit(false);
$output = new BufferedOutput();
$exitCode = $application->run(new ArrayInput([
    'command' => 'nelmio:apidoc:dump',
    '--format' => 'yaml',
]), $output);
if ($exitCode !== 0) {
    throw new RuntimeException('OpenAPI generation failed: '.$output->fetch());
}

$spdx = 'SPDX';
$generated = "# {$spdx}-FileCopyrightText: 2026 LibreSign contributors\n"
    ."# {$spdx}-License-Identifier: AGPL-3.0-or-later\n"
    .rtrim(str_replace("\r\n", "\n", $output->fetch()))."\n";

if ($check) {
    if (!is_file($target) || file_get_contents($target) !== $generated) {
        fwrite(STDERR, "OpenAPI drift: run composer api:generate and commit the generated result.\n");
        exit(1);
    }

    echo "OpenAPI specification matches application routes and attributes.\n";
    exit(0);
}

if (file_put_contents($target, $generated) === false) {
    throw new RuntimeException('Cannot write generated OpenAPI contract.');
}

echo "Generated docs/openapi.yaml from application attributes.\n";
