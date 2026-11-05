<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\EconomicParameterMatrix;
use LibreSign\Pricing\Infrastructure\CatalogUnavailable;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use PHPUnit\Framework\TestCase;

final class EconomicMatrixLoaderTest extends TestCase
{
    public function testExplicitSyntheticMatrixLoadsAndKeepsMissingSeparateFromZero(): void
    {
        $matrix = $this->matrix();
        self::assertSame('fictional-v1', $matrix->version());
        self::assertSame('SYNTHETIC_ONLY', $matrix->scope());
        self::assertSame('5.00', (string) $matrix->requireDecimal('simulation.cloud.variable_unit', 'BRL/customer_period'));
        self::assertFalse($matrix->quoteAllowed(['simulation.cloud.variable_unit'], '2026-11-05'));
        self::assertCount(64, str_split($matrix->fingerprint()));

        $data = self::fixture();
        $data['parameters'][0]['value'] = null;
        $incomplete = EconomicParameterMatrix::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
        self::assertNull($incomplete->decimal('simulation.cloud.fixed_pool', 'BRL/period'));
        $this->expectException(\DomainException::class);
        $incomplete->requireDecimal('simulation.cloud.fixed_pool', 'BRL/period');
    }

    public function testFileIsNeverSelectedImplicitly(): void
    {
        $this->expectException(CatalogUnavailable::class);
        (new EconomicMatrixLoader(''))->load();
    }

    public function testAbsentPrivateFileFailsClosed(): void
    {
        $this->expectException(CatalogUnavailable::class);
        (new EconomicMatrixLoader('/nonexistent/private/economic-matrix.json'))->load();
    }

    public function testDuplicateIdentifiersAreRejected(): void
    {
        $data = self::fixture();
        $data['parameters'][] = $data['parameters'][0];
        $this->expectException(\InvalidArgumentException::class);
        EconomicParameterMatrix::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testFloatingPointInputsAreRejected(): void
    {
        $data = self::fixture();
        $data['parameters'][0]['value'] = 1.234;
        $this->expectException(\InvalidArgumentException::class);
        EconomicParameterMatrix::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testIncompatibleUnitsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->matrix()->decimal('simulation.cloud.variable_unit', 'BRL/hour');
    }

    public function testSyntheticApprovalEscalationIsRejected(): void
    {
        $data = self::fixture();
        $data['approval_status'] = 'approved_for_quotes';
        $this->expectException(\InvalidArgumentException::class);
        EconomicParameterMatrix::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testDuplicateSourcesOfEconomicValuesAreRejected(): void
    {
        $data = self::fixture();
        $data['simulation_assumptions'] = ['cloud' => ['fixed_pool' => '999.00']];
        $this->expectException(\InvalidArgumentException::class);
        EconomicParameterMatrix::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testChangedParametersChangeFingerprint(): void
    {
        $data = self::fixture();
        $data['parameters'][0]['value'] = '121.00';
        self::assertNotSame(
            $this->matrix()->fingerprint(),
            EconomicParameterMatrix::fromJson(json_encode($data, JSON_THROW_ON_ERROR))->fingerprint(),
        );
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/economic-synthetic-v1.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
    }

    private function matrix(): EconomicParameterMatrix
    {
        return (new EconomicMatrixLoader(dirname(__DIR__, 2).'/tests/Fixtures/economic-synthetic-v1.json'))->load();
    }
}
