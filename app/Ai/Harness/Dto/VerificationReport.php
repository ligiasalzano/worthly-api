<?php

namespace App\Ai\Harness\Dto;

final readonly class VerificationReport
{
    /**
     * @param  list<array{field: string, status: string, evidence_ids: list<string>}>  $claims
     */
    public function __construct(
        public array $claims,
    ) {}

    public function hasUnsupported(): bool
    {
        foreach ($this->claims as $claim) {
            if (($claim['status'] ?? null) === 'unsupported') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function unsupportedFields(): array
    {
        $fields = [];

        foreach ($this->claims as $claim) {
            if (($claim['status'] ?? null) !== 'unsupported') {
                continue;
            }

            $field = $claim['field'] ?? null;

            if (! is_string($field) || $field === '' || in_array($field, $fields, true)) {
                continue;
            }

            $fields[] = $field;
        }

        return $fields;
    }
}
