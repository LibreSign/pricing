<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Tests\Unit;

use LibreSign\Pricing\Domain\CommercialOfferEngine;
use LibreSign\Pricing\Domain\CommercialOfferInput;
use LibreSign\Pricing\Domain\InvalidSimulationInput;
use LibreSign\Pricing\Infrastructure\EconomicMatrixLoader;
use PHPUnit\Framework\TestCase;

final class CommercialOfferEngineTest extends TestCase
{
    private static function engine(?string $file = null): CommercialOfferEngine
    {
        return new CommercialOfferEngine(new EconomicMatrixLoader($file ?? self::fixture()));
    }

    private static function fixture(): string
    {
        return dirname(__DIR__).'/Fixtures/economic-historical-synthetic.json';
    }

    private static function cloud(
        int $accounts = 100,
        int $credits = 100,
        int $included = 100,
        ?string $price = '39.00',
        string $takeRate = '0',
    ): CommercialOfferInput {
        return new CommercialOfferInput(
            'cloud',
            'fictional-v2',
            $accounts,
            $credits,
            $included,
            50,
            $takeRate,
            '0.10',
            '0.10',
            $price,
            '10.00',
            null,
        );
    }

    public function testSelfServiceCloudHasNoHumanSetupAndProjectsSubscriptionAnnualAndCosts(): void
    {
        $result = self::engine()->simulate(self::cloud());
        self::assertSame('calculated', $result['status']);
        self::assertSame('PROVISIONAL_NOT_METERED', $result['credit_unit_status']);
        self::assertNull($result['calculation']['setup_recommended']);
        self::assertNull($result['calculation']['setup_candidate']);
        self::assertSame('39.00', $result['calculation']['monthly_candidate']);
        self::assertSame('421.20', $result['calculation']['annual_subscription']);
        self::assertSame('35.10', $result['calculation']['annual_effective_monthly']);
        self::assertSame('3900.00', $result['calculation']['periods']['monthly']['subscription_revenue']);
        self::assertSame(1, self::projection($result, 100)['hosting_nodes']);
        self::assertSame('100', $result['calculation']['average_credits_served']);
        self::assertFalse($result['commercialization']['quote_allowed']);
        self::assertFalse($result['commercialization']['activation_allowed']);
        self::assertSame('FICTIONAL_SCENARIO_REPRODUCTION', $result['evidence'][0]['source']);
        self::assertArrayHasKey('simulation_fingerprint', $result);
    }

    public function testCloudCapacityStepsAtContractAndCreditLimits(): void
    {
        $ordinary = self::engine()->simulate(self::cloud(151));
        self::assertSame(2, self::projection($ordinary, 151)['hosting_nodes']);
        $heavy = self::engine()->simulate(self::cloud(100, 1000, 1000));
        self::assertSame(4, self::projection($heavy, 100)['hosting_nodes']);
        self::assertGreaterThan(
            (float) $ordinary['calculation']['monthly_floor_expected'],
            (float) $heavy['calculation']['monthly_floor_expected'],
        );
    }

    public function testUnpurchasedExcessCreditsAreNotConsumedOrCharged(): void
    {
        $withoutRecharges = self::engine()->simulate(self::cloud(100, 160, 100, '39.00', '0'));
        $withRecharges = self::engine()->simulate(self::cloud(100, 160, 100, '39.00', '1'));
        self::assertSame('100', $withoutRecharges['calculation']['average_credits_served']);
        self::assertSame('160', $withRecharges['calculation']['average_credits_served']);
        self::assertSame(2, $withRecharges['calculation']['additional_packs_if_fully_recharged']);
        self::assertSame('2000.00', $withRecharges['calculation']['periods']['monthly']['topup_revenue']);
        self::assertSame('0.00', $withoutRecharges['calculation']['periods']['monthly']['topup_revenue']);
    }

    public function testManagedAddsDedicatedInfrastructureAndHumanHoursWithoutCloudNode(): void
    {
        $result = self::engine()->simulate(new CommercialOfferInput(
            'managed',
            'fictional-v2',
            1,
            400,
            400,
            100,
            '0',
            '0',
            '0',
            '1200.00',
            '25.00',
            null,
            supportHours: '4.00',
        ));
        $period = $result['calculation']['periods']['monthly'];
        self::assertSame('145.00', $period['hosting']);
        self::assertSame('270.00', $period['operations']);
        self::assertSame('360.00', $period['support']);
        self::assertSame('1200.00', $period['subscription_revenue']);
        self::assertSame(1, self::projection($result, 1)['hosting_nodes']);
        self::assertNotSame('0.00', $result['calculation']['setup_recommended']);
    }

    public function testOnPremiseWithoutMaintenanceHasOnlyProjectAndAccessHours(): void
    {
        $result = self::engine()->simulate(new CommercialOfferInput(
            'customer_infrastructure',
            'fictional-v2',
            1,
            0,
            0,
            100,
            '0',
            '0',
            '0',
            null,
            null,
            null,
            setupExtraHours: '5.00',
            remoteAccess: true,
            recurringMaintenance: false,
        ));
        self::assertSame('calculated', $result['status']);
        self::assertFalse($result['calculation']['recurring_enabled']);
        self::assertSame('29.00', $result['calculation']['setup_hours']);
        self::assertSame('2610.00', $result['calculation']['setup_cost']);
        self::assertNull($result['calculation']['periods']);
        self::assertSame([], $result['calculation']['sensitivity']);
        self::assertSame('not_applicable', $result['credit_unit_status']);
    }

    public function testOnPremiseWithAccessCostsMoreThanSupportOnly(): void
    {
        $base = self::engine()->simulate(new CommercialOfferInput(
            'customer_infrastructure',
            'fictional-v2',
            1,
            0,
            0,
            100,
            '0',
            '0',
            '0',
            '650.00',
            null,
            null,
            supportHours: '2.00',
        ));
        $remote = self::engine()->simulate(new CommercialOfferInput(
            'customer_infrastructure',
            'fictional-v2',
            1,
            0,
            0,
            100,
            '0',
            '0',
            '0',
            '650.00',
            null,
            null,
            supportHours: '2.00',
            remoteAccess: true,
        ));
        self::assertGreaterThan(
            (float) $base['calculation']['monthly_floor_expected'],
            (float) $remote['calculation']['monthly_floor_expected'],
        );
    }

    public function testOnPremiseSupportOnlyHasNoInstallationOrHostingCharge(): void
    {
        $result = self::engine()->simulate(new CommercialOfferInput(
            'customer_infrastructure',
            'fictional-v2',
            1,
            0,
            0,
            100,
            '0',
            '0',
            '0',
            '400.00',
            null,
            null,
            supportHours: '2.00',
            includeSetup: false,
        ));
        self::assertSame('calculated', $result['status']);
        self::assertTrue($result['calculation']['recurring_enabled']);
        self::assertNull($result['calculation']['setup_recommended']);
        self::assertNull($result['calculation']['setup_cost']);
        self::assertNull($result['calculation']['setup_hours']);
        self::assertNull($result['calculation']['setup_candidate']);
        self::assertSame('0.00', $result['calculation']['periods']['monthly']['hosting']);
        self::assertSame('360.00', $result['calculation']['periods']['monthly']['support']);
        self::assertFalse($result['commercialization']['activation_allowed']);
    }

    public function testSupportOnlyWithRemoteAccessIncludesAccessSetupButNotInstall(): void
    {
        $result = self::engine()->simulate(new CommercialOfferInput(
            'customer_infrastructure',
            'fictional-v2',
            1,
            0,
            0,
            100,
            '0',
            '0',
            '0',
            '600.00',
            null,
            '800.00',
            includeSetup: false,
            remoteAccess: true,
        ));
        self::assertSame('4.00', $result['calculation']['setup_hours']);
        self::assertSame('360.00', $result['calculation']['setup_cost']);
        self::assertSame('800.00', $result['calculation']['setup_candidate']);
    }

    public function testRechargesHaveCostDerivedMinimumIncludingProcessingFee(): void
    {
        $result = self::engine()->simulate(self::cloud());
        self::assertSame('6.53', $result['calculation']['topup_reference_floor']);
        self::assertSame('10.00', $result['calculation']['topup_price']);
    }

    public function testCannotSellUndefinedTopupWhenOverageIsPurchased(): void
    {
        $this->expectException(InvalidSimulationInput::class);
        new CommercialOfferInput(
            'cloud',
            'fictional-v2',
            100,
            160,
            100,
            50,
            '1',
            '0',
            '0',
            '39.00',
            null,
            null,
        );
    }

    public function testUnknownHostingCoefficientBlocksCommercialProjection(): void
    {
        $data = json_decode((string) file_get_contents(self::fixture()), true, 32, JSON_THROW_ON_ERROR);
        foreach ($data['parameters'] as &$entry) {
            if ($entry['id'] === 'offer.cloud.host_node_monthly') {
                $entry['value'] = null;
            }
        }
        unset($entry);
        $file = tempnam(sys_get_temp_dir(), 'pricing-commercial-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR));
            $result = self::engine($file)->simulate(self::cloud());
            self::assertSame('incomplete', $result['status']);
            self::assertContains('offer.cloud.host_node_monthly', $result['missing_parameters']);
            self::assertNull($result['calculation']);
        } finally {
            unlink($file);
        }
    }

    public function testCanonicalFingerprintIgnoresRepeatedCalculations(): void
    {
        $first = self::engine()->simulate(self::cloud());
        self::assertSame($first, self::engine()->simulate(self::cloud()));
        $different = self::engine()->simulate(self::cloud(10));
        self::assertNotSame($first['simulation_fingerprint'], $different['simulation_fingerprint']);
    }

    public function testCustomerHostedCannotHaveMonthlyCandidateWithoutMaintenance(): void
    {
        $this->expectException(InvalidSimulationInput::class);
        new CommercialOfferInput(
            'customer_infrastructure',
            'fictional-v2',
            1,
            0,
            0,
            100,
            '0',
            '0',
            '0',
            '300.00',
            null,
            null,
            recurringMaintenance: false,
        );
    }

    /** @param array<string, mixed> $result
     *  @return array<string, mixed>
     */
    private static function projection(array $result, int $accounts): array
    {
        foreach ($result['calculation']['sensitivity'] as $projection) {
            if ($projection['accounts'] === $accounts) {
                return $projection;
            }
        }
        throw new \LogicException('Required sensitivity case missing.');
    }
}
