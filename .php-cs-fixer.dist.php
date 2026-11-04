<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/tools',
    ])
    ->append([
        __DIR__.'/.php-cs-fixer.dist.php',
        __DIR__.'/config/bundles.php',
        __DIR__.'/public/index.php',
    ]);

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder);
