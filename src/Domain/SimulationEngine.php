<?php

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use LibreSign\Pricing\Infrastructure\SyntheticCatalog;

final readonly class SimulationEngine
{
    public const VERSION = '1.0.0';
    public function __construct(private SyntheticCatalog $catalog)
    {
    }
    /** @return array<string, mixed> */
    public function simulate(SimulationInput $input): array
    {
        $snapshot = $this->catalog->load();
        $data = $snapshot['data'];
        if ($input->parameterVersion !== $data['version']) {
            throw new InvalidSimulationInput('Unknown parameter_version.');
        }
        $model = $data['models'][$input->model] ?? null;
        if (!is_array($model)) {
            throw new \RuntimeException('Model not configured.');
        }
        $price = $this->calculate($model, $input->usage, 'base_price', 'price_per_');
        $incremental = $this->calculate($model, $input->usage, 'incremental_fixed_cost', 'cost_per_');
        $common = $this->amount($model['allocated_common_cost'] ?? null);
        $evaluated = $input->candidatePrice === null ? $price : BigDecimal::of($input->candidatePrice);
        $factor = $this->rate($data['cooperative_residual_factor'] ?? null);
        $scenarios = [];
        foreach ($data['tax_scenarios'] as $id => $taxData) {
            $rate = $this->rate($taxData['rate'] ?? null);
            $tax = $this->round($evaluated->multipliedBy($rate));
            $contribution = $evaluated->minus($incremental)->minus($tax);
            $margin = $contribution->minus($common);
            $residual = $this->round(($margin->compareTo(0) > 0 ? $margin : BigDecimal::zero())->multipliedBy($factor));
            $scenarios[$id] = [
                'synthetic_tax_rate' => (string) $rate,
                'tax_amount' => $this->money($tax),
                'incremental_contribution' => $this->money($contribution),
                'full_cost_margin' => $this->money($margin),
                'cooperative_residual_potential' => $this->money($residual),
                'incremental_contribution_positive' => $contribution->compareTo(0) > 0,
                'full_cost_recovered' => $margin->compareTo(0) >= 0,
            ];
        }
        if ($scenarios === []) {
            throw new \RuntimeException('Tax scenarios are missing.');
        }
        $normalized = $input->normalized();
        $fingerprint = hash('sha256', json_encode(['engine' => self::VERSION, 'catalog_sha256' => $snapshot['sha256'], 'input' => $normalized], JSON_THROW_ON_ERROR));
        return [
            'engine_version' => self::VERSION,
            'simulation_fingerprint' => $fingerprint,
            'parameters' => [
                'version' => $data['version'],
                'sha256' => $snapshot['sha256'],
                'synthetic' => true,
                'homologated' => false,
                'example_only' => true,
            ],
            'input' => $normalized,
            'currency' => 'BRL',
            'billing_basis' => $model['billing_basis'],
            'synthetic_list_price' => $this->money($price),
            'evaluated_price' => $this->money($evaluated),
            'evaluated_price_source' => $input->candidatePrice === null ? 'synthetic_catalog' : 'candidate_input',
            'costs' => [
                'incremental' => $this->money($incremental),
                'allocated_common' => $this->money($common),
                'full' => $this->money($incremental->plus($common)),
            ],
            'tax_scenarios' => $scenarios,
            'explanation' => [
                'price' => 'base_price + sum(usage_i * synthetic_price_per_i)',
                'incremental_cost' => 'incremental_fixed_cost + sum(usage_i * synthetic_cost_per_i)',
                'incremental_contribution' => 'evaluated_price - incremental_cost - rounded_tax_amount',
                'full_cost_margin' => 'incremental_contribution - allocated_common_cost',
                'cooperative_residual_potential' => 'round(max(full_cost_margin, 0) * synthetic_factor, 2, HALF_UP)',
                'rounding' => 'HALF_UP to 2 decimal places at money boundaries',
                'scope' => 'Illustrative scenarios, not validated tax treatment or approved cooperative distribution',
            ],
            'commercialization' => [
                'quote_allowed' => false,
                'activation_allowed' => false,
                'reason_code' => 'NON_HOMOLOGATED_SYNTHETIC_PARAMETERS',
            ],
        ];
    }
    /** @param array<string, mixed> $model
     *  @param array<string, int> $usage */
    private function calculate(array $model, array $usage, string $base, string $prefix): BigDecimal
    {
        $result = $this->amount($model[$base] ?? null);
        foreach (['documents' => 'document', 'storage_gb' => 'storage_gb', 'service_hours' => 'service_hour'] as $quantity => $unit) {
            $result = $result->plus($this->amount($model[$prefix.$unit] ?? null)->multipliedBy($usage[$quantity]));
        }
        return $this->round($result);
    }
    private function amount(mixed $value): BigDecimal
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?$/D', $value)) {
            throw new \RuntimeException('Invalid synthetic decimal.');
        }
        return BigDecimal::of($value);
    }
    private function rate(mixed $value): BigDecimal
    {
        $rate = $this->amount($value);
        if ($rate->compareTo(0) < 0 || $rate->compareTo(1) > 0) {
            throw new \RuntimeException('Rate is outside [0,1].');
        }
        return $rate;
    }
    private function round(BigDecimal $value): BigDecimal
    {
        return $value->toScale(2, RoundingMode::HalfUp);
    }
    private function money(BigDecimal $value): string
    {
        return (string) $this->round($value);
    }
}
