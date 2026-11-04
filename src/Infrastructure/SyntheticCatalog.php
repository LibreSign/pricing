<?php

declare(strict_types=1);

namespace LibreSign\Pricing\Infrastructure;

final readonly class SyntheticCatalog
{
    public function __construct(private string $filename)
    {
    }
    /** @return array{data: array<string, mixed>, sha256: string} */
    public function load(): array
    {
        $content = file_get_contents($this->filename);
        if ($content === false) {
            throw new \RuntimeException('Missing synthetic catalog.');
        }
        $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1 || ($data['version'] ?? null) !== 'synthetic-v1' ||
            ($data['homologated'] ?? null) !== false || ($data['status'] ?? null) !== 'SYNTHETIC_ONLY' ||
            ($data['currency'] ?? null) !== 'BRL' || !is_array($data['models'] ?? null) ||
            !is_array($data['tax_scenarios'] ?? null)) {
            throw new \RuntimeException('Catalog must remain synthetic and non-homologated.');
        }
        return ['data' => $data, 'sha256' => hash('sha256', $content)];
    }
}
