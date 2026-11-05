<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EconomicWorkbenchController
{
    private const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    public function __construct(private string $enabled)
    {
    }

    #[Route('/internal/pricing-workbench', methods: ['GET'])]
    public function page(): Response
    {
        return $this->asset('pricing-workbench.html', 'text/html; charset=UTF-8', true);
    }

    #[Route('/internal/pricing-workbench.css', methods: ['GET'])]
    public function stylesheet(): Response
    {
        return $this->asset('pricing-workbench.css', 'text/css; charset=UTF-8');
    }

    #[Route('/internal/pricing-workbench.js', methods: ['GET'])]
    public function script(): Response
    {
        return $this->asset('pricing-workbench.js', 'text/javascript; charset=UTF-8');
    }

    private function asset(string $name, string $contentType, bool $html = false): Response
    {
        if ($this->enabled !== '1') {
            return new Response('', 404, self::HEADERS);
        }

        $content = @file_get_contents(__DIR__.'/../Ui/'.$name);
        if (!is_string($content)) {
            return new Response('', 503, self::HEADERS);
        }

        $headers = self::HEADERS + ['Content-Type' => $contentType];
        if ($html) {
            $headers['Content-Security-Policy'] = "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
        }

        return new Response($content, 200, $headers);
    }
}
