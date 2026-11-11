<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

final class EconomicScenarioFields
{
    /** Operational quantities and switches only. Prices remain in the private economic matrix. */
    private const DEFINITIONS = [
        'documents' => ['label' => 'Documentos por mês', 'help' => 'Volume mensal estimado de documentos enviados.', 'type' => 'integer', 'default' => 0, 'max' => 10000000],
        'users' => ['label' => 'Usuários internos', 'help' => 'Contas da organização atendida, não clientes da carteira.', 'type' => 'integer', 'default' => 0, 'max' => 100000],
        'storage_gb' => ['label' => 'Armazenamento (GB)', 'help' => 'Volume reservado no serviço hospedado.', 'type' => 'integer', 'default' => 0, 'max' => 100000],
        'support_hours' => ['label' => 'Suporte adicional (horas/mês)', 'help' => 'Horas além do suporte básico previsto na matriz.', 'type' => 'decimal', 'default' => '0.00', 'max' => '1000.00'],
        'migration_gb' => ['label' => 'Migração inicial (GB)', 'help' => 'Dados migrados apenas durante a implantação.', 'type' => 'integer', 'default' => 0, 'max' => 100000],
        'integrations' => ['label' => 'Integrações na implantação', 'help' => 'Quantidade de integrações configuradas na entrega inicial.', 'type' => 'integer', 'default' => 0, 'max' => 100],
        'training_hours' => ['label' => 'Treinamento (horas)', 'help' => 'Esforço de treinamento cobrado uma única vez.', 'type' => 'decimal', 'default' => '0.00', 'max' => '1000.00'],
        'deployment_hours' => ['label' => 'Implantação adicional (horas)', 'help' => 'Esforço extra de implantação além do serviço básico.', 'type' => 'decimal', 'default' => '0.00', 'max' => '1000.00'],
        'backup' => ['label' => 'Backup adicional', 'help' => 'Aplica o custo de backup por GB da matriz privada.', 'type' => 'boolean', 'default' => false],
        'priority_support' => ['label' => 'Suporte prioritário', 'help' => 'Adiciona horas recorrentes de atendimento configuradas na matriz.', 'type' => 'boolean', 'default' => false],
        'tsa' => ['label' => 'Carimbo do tempo (TSA)', 'help' => 'Aplica custo variável por documento com carimbo do tempo.', 'type' => 'boolean', 'default' => false],
        'dedicated' => ['label' => 'Infraestrutura dedicada', 'help' => 'Substitui a hipótese de hospedagem compartilhada por adicional dedicado.', 'type' => 'boolean', 'default' => false],
        'recurring_support' => ['label' => 'Contratar manutenção mensal', 'help' => 'Quando desligado, calcula somente a implantação no ambiente do cliente.', 'type' => 'boolean', 'default' => true],
    ];

    private const MODELS = [
        'cloud' => ['documents', 'users', 'storage_gb', 'support_hours', 'migration_gb', 'integrations', 'backup', 'priority_support', 'tsa', 'dedicated'],
        'managed' => ['documents', 'users', 'storage_gb', 'support_hours', 'migration_gb', 'integrations', 'training_hours', 'deployment_hours', 'backup', 'priority_support', 'tsa', 'dedicated'],
        'customer_infrastructure' => ['support_hours', 'migration_gb', 'integrations', 'training_hours', 'deployment_hours', 'priority_support', 'recurring_support'],
    ];

    /** @return array<string, list<array<string, mixed>>> */
    public static function metadata(): array
    {
        $result = [];
        foreach (self::MODELS as $model => $keys) {
            $result[$model] = [];
            foreach ($keys as $key) {
                $result[$model][] = ['id' => $key] + self::DEFINITIONS[$key];
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $values
     *  @return array<string, int|string|bool>
     */
    public static function normalize(string $model, array $values): array
    {
        $keys = self::MODELS[$model] ?? null;
        if ($keys === null || array_diff(array_keys($values), $keys) !== []) {
            throw new InvalidSimulationInput('Unknown or unsupported scenario field.');
        }
        $normalized = [];
        foreach ($keys as $key) {
            $definition = self::DEFINITIONS[$key];
            $value = $values[$key] ?? $definition['default'];
            if ($definition['type'] === 'integer') {
                if (!is_int($value) || $value < 0 || $value > $definition['max']) {
                    throw new InvalidSimulationInput('Invalid scenario quantity.');
                }
            } elseif ($definition['type'] === 'decimal') {
                if (!is_string($value)
                    || preg_match('/^(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?$/D', $value) !== 1
                    || \Brick\Math\BigDecimal::of($value)->compareTo($definition['max']) > 0) {
                    throw new InvalidSimulationInput('Invalid scenario hours.');
                }
            } elseif (!is_bool($value)) {
                throw new InvalidSimulationInput('Invalid scenario switch.');
            }
            $normalized[$key] = $value;
        }
        if (($normalized['backup'] ?? false) && $normalized['storage_gb'] === 0) {
            throw new InvalidSimulationInput('Backup requires a positive storage quantity.');
        }
        if (($normalized['tsa'] ?? false) && $normalized['documents'] === 0) {
            throw new InvalidSimulationInput('TSA requires a positive document quantity.');
        }

        return $normalized;
    }
}
