<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\EconomicParameterMatrix;
use LibreSign\Pricing\Domain\EconomicScenarioInput;
use PHPUnit\Framework\TestCase;

final class EconomicMatrixVersionTest extends TestCase
{
    public function testDatedParameterVersionIsAcceptedInConfigAndRequest(): void
    {
        $data = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/tests/Fixtures/economic-historical-synthetic.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $data['parameter_set_version'] = '2026-11-04.1';
        $matrix = EconomicParameterMatrix::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
        $input = new EconomicScenarioInput('cloud', '2026-11-04.1', 1, null);

        self::assertSame('2026-11-04.1', $matrix->version());
        self::assertSame($matrix->version(), $input->parameterVersion);
    }

    public function testUnsafeVersionCharactersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EconomicScenarioInput('cloud', '../secret.json', 1, null);
    }
}
