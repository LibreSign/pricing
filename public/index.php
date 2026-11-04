<?php

declare(strict_types=1);

use LibreSign\Pricing\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$kernel = new Kernel(
    $_SERVER['APP_ENV'] ?? 'prod',
    ($_SERVER['APP_DEBUG'] ?? '0') === '1',
);

$kernel->handle(Request::createFromGlobals())->send();
