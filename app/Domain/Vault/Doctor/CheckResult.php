<?php

declare(strict_types=1);

namespace App\Domain\Vault\Doctor;

final readonly class CheckResult
{
    public const string PASS = 'PASS';

    public const string WARN = 'WARN';

    public const string FAIL = 'FAIL';

    public function __construct(
        public string $key,
        public string $label,
        public string $status,
        public string $value,
        public string $rationale,
        public bool $sapiSensitive = false,
    ) {}

    /**
     * @return array{key: string, label: string, status: string, value: string, rationale: string, sapi_sensitive: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status,
            'value' => $this->value,
            'rationale' => $this->rationale,
            'sapi_sensitive' => $this->sapiSensitive,
        ];
    }
}
