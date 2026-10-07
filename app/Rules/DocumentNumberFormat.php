<?php

namespace App\Rules;

use App\Enums\DocumentType;
use App\Support\Customers\DocumentNumber;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Format of the document number for the submitted `document_type`, including the SENIAT check
 * digit of RIFs (CLI-003, DEC-CLI-30). A missing or unknown `document_type` is reported by the
 * `required_with` and enum rules of that field, so this rule stays silent then.
 */
class DocumentNumberFormat implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $submittedType = $this->data['document_type'] ?? null;
        $type = is_string($submittedType) ? DocumentType::tryFrom($submittedType) : null;

        if ($type === null) {
            return;
        }

        if (! is_string($value) || ! DocumentNumber::isValid($type, DocumentNumber::normalize($type, $value))) {
            $fail('validation.document_number_format')->translate();
        }
    }
}
