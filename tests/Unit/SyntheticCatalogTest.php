<?php

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Infrastructure\SyntheticCatalog;
use PHPUnit\Framework\TestCase;

final class SyntheticCatalogTest extends TestCase
{
    public function testHomologationCannotBeEnabledByCatalog(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pricing-');
        self::assertNotFalse($path);
        try {
            $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/config/pricing/synthetic-v1.json'), true, 16, JSON_THROW_ON_ERROR);
            $data['homologated'] = true;
            file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
            $this->expectException(\RuntimeException::class);
            (new SyntheticCatalog($path))->load();
        } finally {
            unlink($path);
        }
    }
}
