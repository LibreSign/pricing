<?php

// SPDX-FileCopyrightText: 2026 LibreSign contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

use Brick\Math\BigDecimal;

final readonly class EconomicParameterMatrix
{
    /** @var array<string, array<string, mixed>> */
    private array $index;

    /** @param array<string, mixed> $document */
    private function __construct(private array $document, private string $sha256)
    {
        if (($document['schema_version'] ?? null) !== '2.0.0'
            || ($document['contract_id'] ?? null) !== 'libresign.pricing.economic-parameters'
            || ($document['currency'] ?? null) !== 'BRL'
            || !in_array($document['scope'] ?? null, ['SYNTHETIC_ONLY', 'PRIVATE_ECONOMICS'], true)
            || !in_array($document['approval_status'] ?? null, ['research_only', 'approved_for_quotes'], true)
            || !self::identifier($document['parameter_set_id'] ?? null)
            || !self::identifier($document['parameter_set_version'] ?? null)
            || !is_array($document['models'] ?? null)
            || !is_array($document['parameters'] ?? null)
            || !array_is_list($document['parameters'])
            || array_key_exists('simulation_assumptions', $document)
            || array_key_exists('scenario_results', $document)) {
            throw new \InvalidArgumentException('Invalid economic parameter matrix contract.');
        }

        if ($document['scope'] === 'SYNTHETIC_ONLY' && $document['approval_status'] !== 'research_only') {
            throw new \InvalidArgumentException('Synthetic parameters cannot be approved.');
        }

        foreach (['cloud', 'managed', 'customer_infrastructure'] as $model) {
            $configuration = $document['models'][$model] ?? null;
            if (!is_array($configuration)
                || !in_array($configuration['billing_basis'] ?? null, ['monthly', 'project', 'project_with_optional_recurring'], true)
                || ($configuration['parameter_prefix'] ?? null) !== 'simulation.'.$model) {
                throw new \InvalidArgumentException('Invalid model configuration.');
            }
        }

        $index = [];
        foreach ($document['parameters'] as $parameter) {
            if (!is_array($parameter)
                || !self::identifier($parameter['id'] ?? null)
                || !is_string($parameter['unit'] ?? null)
                || $parameter['unit'] === ''
                || !is_string($parameter['source'] ?? null)
                || $parameter['source'] === ''
                || !in_array($parameter['approval_status'] ?? null, ['not_approved_for_commercial_use', 'approved_for_commercial_use'], true)
                || !array_key_exists('value', $parameter)
                || !self::decimalOrNull($parameter['value'])) {
                throw new \InvalidArgumentException('Invalid economic parameter.');
            }
            $id = $parameter['id'];
            if (isset($index[$id])) {
                throw new \InvalidArgumentException('Duplicate economic parameter.');
            }
            if ($document['scope'] === 'SYNTHETIC_ONLY'
                && $parameter['approval_status'] === 'approved_for_commercial_use') {
                throw new \InvalidArgumentException('Synthetic parameter cannot be commercially approved.');
            }
            $index[$id] = $parameter;
        }
        if ($index === []) {
            throw new \InvalidArgumentException('Economic parameter matrix is empty.');
        }
        $this->index = $index;
    }

    public static function fromJson(string $content): self
    {
        if (strlen($content) > 1048576) {
            throw new \InvalidArgumentException('Economic parameter matrix is too large.');
        }
        try {
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Malformed economic parameter matrix.', 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('Economic parameter matrix must be a JSON object.');
        }
        return new self($data, hash('sha256', $content));
    }

    public function decimal(string $id, string $unit): ?BigDecimal
    {
        $parameter = $this->index[$id] ?? null;
        if ($parameter === null || $parameter['unit'] !== $unit) {
            throw new \InvalidArgumentException('Missing parameter or incompatible unit: '.$id);
        }
        $value = $parameter['value'];
        return $value === null ? null : BigDecimal::of($value);
    }

    public function requireDecimal(string $id, string $unit): BigDecimal
    {
        return $this->decimal($id, $unit)
            ?? throw new \DomainException('Required economic parameter is unknown: '.$id);
    }

    /** @return array{billing_basis: string, parameter_prefix: string} */
    public function model(string $id): array
    {
        $configuration = $this->document['models'][$id] ?? null;
        if (!is_array($configuration)) {
            throw new \InvalidArgumentException('Unknown pricing model.');
        }
        return [
            'billing_basis' => $configuration['billing_basis'],
            'parameter_prefix' => $configuration['parameter_prefix'],
        ];
    }

    /** @param list<string> $parameterIds */
    public function quoteAllowed(array $parameterIds, string $onDate): bool
    {
        if ($this->document['scope'] !== 'PRIVATE_ECONOMICS'
            || $this->document['approval_status'] !== 'approved_for_quotes'
            || !self::identifier($this->document['approved_by'] ?? null)
            || !self::identifier($this->document['approval_reference'] ?? null)
            || !self::validDate($onDate)
            || !self::validDate($this->document['effective_from'] ?? null)
            || !self::validDate($this->document['effective_until'] ?? null)
            || $onDate < $this->document['effective_from']
            || $onDate > $this->document['effective_until']) {
            return false;
        }
        foreach ($parameterIds as $id) {
            $parameter = $this->index[$id] ?? null;
            if ($parameter === null
                || $parameter['value'] === null
                || $parameter['approval_status'] !== 'approved_for_commercial_use') {
                return false;
            }
        }
        return true;
    }

    public function version(): string
    {
        return $this->document['parameter_set_version'];
    }

    public function fingerprint(): string
    {
        return $this->sha256;
    }

    public function scope(): string
    {
        return $this->document['scope'];
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'id' => $this->document['parameter_set_id'],
            'version' => $this->version(),
            'sha256' => $this->fingerprint(),
            'scope' => $this->scope(),
            'approval_status' => $this->document['approval_status'],
        ];
    }

    private static function decimalOrNull(mixed $value): bool
    {
        return $value === null
            || (is_string($value)
                && preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D', $value) === 1);
    }

    private static function identifier(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value) === 1;
    }

    private static function validDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches) !== 1) {
            return false;
        }
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}
