<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

use Brick\Math\BigDecimal;

/** Research-only editable assumptions, never mutations of the signed economic catalog. */
final class EconomicOfferControls
{
    /** @var array<string, array{label: string, max: string}> */
    public const FIELDS = [
        'simulation.tax' => ['label' => 'Tributos efetivos (fração)', 'max' => '1'],
        'simulation.default' => ['label' => 'Risco de inadimplência (fração)', 'max' => '1'],
        'simulation.target_margin' => ['label' => 'Margem-alvo (fração)', 'max' => '1'],
        'offer.common.payment_rate' => ['label' => 'Taxa variável do meio de pagamento (fração)', 'max' => '1'],
        'offer.common.payment_fixed' => ['label' => 'Taxa por transação (R$)', 'max' => '10000'],
        'offer.common.unit_credit_cost' => ['label' => 'Custo variável por crédito (R$)', 'max' => '1000'],
        'offer.common.platform_per_contract' => ['label' => 'Custo da plataforma por contrato (R$)', 'max' => '100000'],
        'offer.common.cac_per_new_contract' => ['label' => 'Aquisição por novo contrato (R$)', 'max' => '100000'],
        'offer.cloud.host_node_monthly' => ['label' => 'Servidor Cloud mensal (R$)', 'max' => '1000000'],
        'offer.cloud.node_contract_capacity' => ['label' => 'Contratos por servidor Cloud', 'max' => '1000000'],
        'offer.cloud.node_credit_capacity' => ['label' => 'Créditos por servidor Cloud', 'max' => '1000000000'],
        'offer.cloud.shared_operations_monthly' => ['label' => 'Operação compartilhada mensal (R$)', 'max' => '1000000'],
        'offer.managed.dedicated_node_monthly' => ['label' => 'Servidor dedicado Managed (R$)', 'max' => '1000000'],
        'offer.managed.node_credit_capacity' => ['label' => 'Créditos por servidor Managed', 'max' => '1000000000'],
        'offer.managed.operations_hours_monthly' => ['label' => 'Gestão mensal Managed (horas)', 'max' => '1000'],
        'simulation.managed.collective_hour' => ['label' => 'Custo cooperativista por hora Managed (R$)', 'max' => '100000'],
        'simulation.managed.setup_hours' => ['label' => 'Horas-base de implantação Managed', 'max' => '1000'],
        'simulation.customer_infrastructure.collective_hour' => ['label' => 'Custo cooperativista por hora on-premise (R$)', 'max' => '100000'],
        'simulation.customer_infrastructure.setup_hours' => ['label' => 'Horas-base de instalação on-premise', 'max' => '1000'],
        'offer.customer_infrastructure.remote_setup_hours' => ['label' => 'Horas para configurar acesso remoto', 'max' => '1000'],
        'offer.customer_infrastructure.service_coordination_hours_monthly' => ['label' => 'Coordenação mensal on-premise (horas)', 'max' => '1000'],
        'offer.customer_infrastructure.remote_maintenance_hours_monthly' => ['label' => 'Administração remota mensal (horas)', 'max' => '1000'],
    ];

    /** @param array<string, string> $overrides */
    public static function validate(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            if (!isset(self::FIELDS[$key])
                || preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,4})?$/D', $value) !== 1
                || BigDecimal::of($value)->compareTo(self::FIELDS[$key]['max']) > 0) {
                throw new InvalidSimulationInput('Invalid economic research override.');
            }
        }
    }
}
