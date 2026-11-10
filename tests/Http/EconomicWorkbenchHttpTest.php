<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Http;

use LibreSign\Pricing\Controller\EconomicWorkbenchController;
use LibreSign\Pricing\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EconomicWorkbenchHttpTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testBrowserWorkbenchIsOffByDefault(): void
    {
        $client = static::createClient();
        foreach ([
            '/internal/pricing-workbench',
            '/internal/pricing-workbench.css',
            '/internal/pricing-workbench.js',
            '/internal/pricing-workbench/technical',
            '/internal/pricing-studio.css',
            '/internal/pricing-studio.js',
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testExplicitlyEnabledWorkbenchServesLocalAssetsOnly(): void
    {
        $controller = new EconomicWorkbenchController('1');
        $page = $controller->page();
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('text/html', (string) $page->headers->get('Content-Type'));
        self::assertStringContainsString("script-src 'self'", (string) $page->headers->get('Content-Security-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $page->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        self::assertStringContainsString('/internal/pricing-studio.js', (string) $page->getContent());
        self::assertStringNotContainsString('<script>', (string) $page->getContent());

        $script = $controller->script();
        self::assertSame(200, $script->getStatusCode());
        self::assertStringContainsString('text/javascript', (string) $script->headers->get('Content-Type'));
        self::assertStringContainsString('/v1/economic-simulations', (string) $script->getContent());
        self::assertStringNotContainsString('localStorage', (string) $script->getContent());
        self::assertStringNotContainsString('sessionStorage', (string) $script->getContent());

        $stylesheet = $controller->stylesheet();
        self::assertSame(200, $stylesheet->getStatusCode());
        self::assertStringContainsString('text/css', (string) $stylesheet->headers->get('Content-Type'));
    }

    public function testDisabledAssetsDoNotRevealContent(): void
    {
        $controller = new EconomicWorkbenchController('0');
        foreach ([$controller->page(), $controller->script(), $controller->stylesheet()] as $response) {
            self::assertSame(404, $response->getStatusCode());
            self::assertSame('', (string) $response->getContent());
        }
    }

    public function testOnlyGetRoutesAreRegistered(): void
    {
        $client = static::createClient();
        $client->request('POST', '/internal/pricing-workbench', content: '{}');
        self::assertResponseStatusCodeSame(405);
    }
}
