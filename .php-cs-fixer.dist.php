<?php
return (new PhpCsFixer\Config())->setRiskyAllowed(false)->setRules(['@PSR12' => true, 'declare_strict_types' => true])->setFinder(PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/tools'])->append([__DIR__.'/public/index.php']));
