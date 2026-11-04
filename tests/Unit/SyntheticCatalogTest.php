<?php

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Infrastructure\CatalogUnavailable;
use LibreSign\Pricing\Infrastructure\SyntheticCatalog;
use PHPUnit\Framework\TestCase;

final class SyntheticCatalogTest extends TestCase
{
    public function testMissingCatalogIsRejected(): void
    {
        $this->expectException(CatalogUnavailable::class);
        (new SyntheticCatalog(''))->load();
    }

    public function testUnconfiguredPathNeverFallsBackToFixture(): void
    {
        $this->expectException(CatalogUnavailable::class);
        (new SyntheticCatalog('/nonexistent/libresign-pricing-example.json'))->load();
    }

    public function testNonfictionalCatalogMarkerIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pricing-');
        self::assertNotFalse($path);
        try {
            $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/synthetic-v1.json'), true, 16, JSON_THROW_ON_ERROR);
            $data['dataset_kind'] = 'REAL_INTERNAL_DATA';
            file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

            $this->expectException(CatalogUnavailable::class);
            (new SyntheticCatalog($path))->load();
        } finally {
            unlink($path);
        }
    }

    public function testMalformedCatalogIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pricing-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, '{broken');

            $this->expectException(CatalogUnavailable::class);
            (new SyntheticCatalog($path))->load();
        } finally {
            unlink($path);
        }
    }

    public function testHomologationCannotBeEnabledByCatalog(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pricing-');
        self::assertNotFalse($path);
        try {
            $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/synthetic-v1.json'), true, 16, JSON_THROW_ON_ERROR);
            $data['homologated'] = true;
            file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
            $this->expectException(\RuntimeException::class);
            (new SyntheticCatalog($path))->load();
        } finally {
            unlink($path);
        }
    }

    public function testConfigurableSyntheticVersion(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pricing-');
        self::assertNotFalse($path);
        try {
            $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/synthetic-v1.json'), true, 16, JSON_THROW_ON_ERROR);
            $data['version'] = 'synthetic-v2';
            file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
            self::assertSame('synthetic-v2', (new SyntheticCatalog($path))->load()['data']['version']);
        } finally {
            unlink($path);
        }
    }
}
