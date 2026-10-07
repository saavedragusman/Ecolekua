<?php

namespace App\Support\Customers;

use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\VenezuelanState;
use App\Models\Customer;
use App\Rules\DocumentNumberFormat;
use App\Rules\VenezuelanPhone;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Customer input validation shared by the store/update FormRequests and the one-time import
 * (design.md "Validation contract"), so every caller applies identical rules and messages.
 * Normalization stays in PhoneNumber and DocumentNumber; the Actions remain the authority for
 * what must hold for every caller (duplicate phone, advisor eligibility).
 */
final class CustomerRules
{
    public const NAME_MAX_LENGTH = 200;

    public const NOTES_MAX_LENGTH = 5000;

    /**
     * Rules for every customer field, including `contact.*` and `address.*`.
     *
     * `contact` and `address` follow the full-replace semantics: `null` (or absent) means "none"
     * and an object upserts. An empty array is neither, and `required_with` does not fire for it,
     * so it is rejected (422) through `notEmptyArray()` instead of being normalized to null:
     * the caller must say `null` to delete, which keeps an accidental `[]` from silently removing data.
     *
     * @return array<string, list<mixed>>
     */
    public static function customer(?Customer $ignoring = null): array
    {
        return [
            'type' => ['required', Rule::enum(CustomerType::class)],
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'document_type' => ['nullable', 'required_with:document_number', Rule::enum(DocumentType::class)],
            'document_number' => [
                'nullable',
                'required_with:document_type',
                'string',
                'max:30',
                new DocumentNumberFormat,
                self::uniqueDocument($ignoring),
            ],
            'phone' => ['required', 'string', 'max:30', new VenezuelanPhone(allowLandline: false)],
            'email' => ['nullable', 'email', 'max:255'],
            'birthday_day' => ['nullable', 'integer', 'between:1,31', 'required_with:birthday_month'],
            'birthday_month' => ['nullable', 'integer', 'between:1,12', 'required_with:birthday_day'],
            'anniversary_day' => ['nullable', 'integer', 'between:1,31', 'required_with:anniversary_month', 'prohibited_unless:type,company'],
            'anniversary_month' => ['nullable', 'integer', 'between:1,12', 'required_with:anniversary_day', 'prohibited_unless:type,company'],
            'notes' => ['nullable', 'string', 'max:'.self::NOTES_MAX_LENGTH],
            'contact' => ['nullable', 'array', self::notEmptyArray(), 'prohibited_unless:type,company'],
            'contact.name' => ['required_with:contact', 'string', 'max:150'],
            'contact.position' => ['nullable', 'string', 'max:100'],
            'contact.phone' => ['required_with:contact', 'string', 'max:30', new VenezuelanPhone(allowLandline: true)],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'array', self::notEmptyArray()],
            'address.line' => ['required_with:address', 'string', 'max:255'],
            'address.city' => ['required_with:address', 'string', 'max:100'],
            'address.state' => ['required_with:address', Rule::enum(VenezuelanState::class)],
            'address.reference' => ['nullable', 'string', 'max:255'],
            'confirm_duplicate_phone' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Checks that need several fields at once, to register with `$validator->after()`:
     * day and month of each commemorative date must form a real date (29 February is valid,
     * DEC-CLI-31) and the document type must fit the submitted customer type (CLI-003, E-24, E-35).
     *
     * @return list<Closure(Validator): void>
     */
    public static function after(): array
    {
        return [
            fn (Validator $validator) => self::validateDate($validator, 'birthday_day', 'birthday_month'),
            fn (Validator $validator) => self::validateDate($validator, 'anniversary_day', 'anniversary_month'),
            self::validateDocumentTypeFitsCustomerType(...),
        ];
    }

    /**
     * Rejects `[]` for an optional nested object; `null` and absence stay valid ("none").
     */
    private static function notEmptyArray(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === []) {
                $fail('validation.not_empty_array')->translate();
            }
        };
    }

    private static function validateDate(Validator $validator, string $dayField, string $monthField): void
    {
        $data = $validator->getData();
        $day = $data[$dayField] ?? null;
        $month = $data[$monthField] ?? null;

        $rangeFailed = $validator->errors()->has($dayField) || $validator->errors()->has($monthField);

        if ($rangeFailed || ! is_numeric($day) || ! is_numeric($month)) {
            return;
        }

        // Year 2000 is a leap year, so 29 February is accepted.
        if (! checkdate((int) $month, (int) $day, 2000)) {
            $validator->errors()->add($dayField, __('validation.date', ['attribute' => __("validation.attributes.{$dayField}")]));
        }
    }

    private static function validateDocumentTypeFitsCustomerType(Validator $validator): void
    {
        $data = $validator->getData();
        $submittedType = $data['type'] ?? null;
        $submittedDocumentType = $data['document_type'] ?? null;

        $type = is_string($submittedType) ? CustomerType::tryFrom($submittedType) : null;
        $documentType = is_string($submittedDocumentType) ? DocumentType::tryFrom($submittedDocumentType) : null;

        if ($type !== null && $documentType !== null && ! in_array($documentType, DocumentType::allowedFor($type), true)) {
            $validator->errors()->add('document_type', __('validation.document_type_mismatch', [
                'attribute' => __('validation.attributes.document_type'),
            ]));
        }
    }

    /**
     * The normalized `(document_type, document_number)` pair must not belong to another customer,
     * active or inactive (CLI-012, E-13, E-36). The unique index is the database guarantee.
     */
    private static function uniqueDocument(?Customer $ignoring): ValidationRule
    {
        return new class($ignoring) implements DataAwareRule, ValidationRule
        {
            /** @var array<string, mixed> */
            private array $data = [];

            public function __construct(private readonly ?Customer $ignoring) {}

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

                if ($type === null || ! is_string($value)) {
                    return;
                }

                $taken = Customer::query()
                    ->where('document_type', $type->value)
                    ->where('document_number', DocumentNumber::normalize($type, $value))
                    ->when($this->ignoring !== null, fn ($query) => $query->whereKeyNot($this->ignoring?->getKey()))
                    ->exists();

                if ($taken) {
                    $fail('validation.customer_document_unique')->translate();
                }
            }
        };
    }
}
