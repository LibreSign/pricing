<?php

declare(strict_types=1);

namespace LibreSign\Pricing\Domain;

final readonly class SimulationInput
{
    private const MAXIMUMS = [
        'documents' => 1000000,
        'storage_gb' => 100000,
        'service_hours' => 10000,
    ];
    /** @param array<string, int> $usage */
    private function __construct(
        public string $model,
        public string $parameterVersion,
        public array $usage,
        public ?string $candidatePrice,
    ) {
    }
    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        if (array_diff(array_keys($body), ['model', 'parameter_version', 'usage', 'candidate_price']) !== []) {
            throw new InvalidSimulationInput('Unsupported request field.');
        }
        $model = $body['model'] ?? null;
        if (!is_string($model) || !in_array($model, ['cloud', 'managed', 'customer_infrastructure'], true)) {
            throw new InvalidSimulationInput('Invalid model.');
        }
        $version = $body['parameter_version'] ?? null;
        if (!is_string($version) || !preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $version)) {
            throw new InvalidSimulationInput('Invalid parameter_version.');
        }
        $raw = $body['usage'] ?? null;
        if (!is_array($raw) || array_diff(array_keys($raw), array_keys(self::MAXIMUMS)) !== []) {
            throw new InvalidSimulationInput('Invalid usage.');
        }
        $usage = [];
        foreach (self::MAXIMUMS as $key => $limit) {
            $number = $raw[$key] ?? null;
            if (!is_int($number) || $number < 0 || $number > $limit) {
                throw new InvalidSimulationInput('Invalid usage.'.$key);
            }
            $usage[$key] = $number;
        }
        if ($model === 'customer_infrastructure' && ($usage['documents'] !== 0 || $usage['storage_gb'] !== 0)) {
            throw new InvalidSimulationInput('Customer infrastructure has no document or storage charges.');
        }
        $candidate = $body['candidate_price'] ?? null;
        if ($candidate !== null && (!is_string($candidate) || !preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D', $candidate))) {
            throw new InvalidSimulationInput('Invalid candidate_price.');
        }
        return new self($model, $version, $usage, $candidate);
    }
    /** @return array<string, mixed> */
    public function normalized(): array
    {
        return [
            'model' => $this->model,
            'parameter_version' => $this->parameterVersion,
            'usage' => $this->usage,
            'candidate_price' => $this->candidatePrice,
        ];
    }
}
