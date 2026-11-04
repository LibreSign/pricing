<?php

declare(strict_types=1);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/vendor/') || str_contains($file->getPathname(), '/var/')) {
        continue;
    }
    passthru(PHP_BINARY.' -l '.escapeshellarg($file->getPathname()), $code);
    if ($code !== 0) {
        exit($code);
    }
}
