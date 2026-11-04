<?php

declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
(new LibreSign\Pricing\Kernel($_SERVER['APP_ENV'] ?? 'prod', ($_SERVER['APP_DEBUG'] ?? '0') === '1'))->handle(Symfony\Component\HttpFoundation\Request::createFromGlobals())->send();
