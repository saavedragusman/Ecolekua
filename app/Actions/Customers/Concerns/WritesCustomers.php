<?php

namespace App\Actions\Customers\Concerns;

use App\Enums\DocumentType;
use App\Exceptions\DuplicatePhoneWarning;
use App\Models\Customer;
use App\Support\Customers\DocumentNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Rules that `CreateCustomer` and `UpdateCustomer` must apply identically (design Decisions 9-10):
 * the canonical document, the duplicate-phone warning and the unique-index race on the document.
 * The Actions stay separate; only these small, stateless helpers are shared.
 */
trait WritesCustomers
{
    private const DOCUMENT_UNIQUE_INDEX = 'customers_document_unique';

    /**
     * A race that passed validation and hit the unique index reads as the validation error (CLI-012).
     * Any other unique violation is not ours to translate and is rethrown as it came.
     *
     * @throws ValidationException
     * @throws UniqueConstraintViolationException
     */
    private function rethrowDocumentRaceAsValidationError(UniqueConstraintViolationException $exception): never
    {
        if (! str_contains($exception->getMessage(), self::DOCUMENT_UNIQUE_INDEX)) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'document_number' => __('validation.customer_document_unique'),
        ]);
    }

    /**
     * Other customers, active or inactive, with the same main phone. Contact-person phones are
     * never compared (DEC-CLI-27). Nothing has been written when this throws.
     *
     * @throws DuplicatePhoneWarning
     */
    private function warnAboutDuplicatePhone(string $phone, ?Customer $except = null): void
    {
        $matches = array_values(Customer::query()
            ->where('phone', $phone)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Customer $other): array => [
                'id' => $other->id,
                'name' => $other->name,
                'document' => $other->document_type === null || $other->document_number === null
                    ? null
                    : DocumentNumber::display($other->document_type, $other->document_number),
                'status' => $other->status->value,
                'status_label' => $other->status->label(),
            ])
            ->all());

        if ($matches !== []) {
            throw new DuplicatePhoneWarning($matches);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalizedDocument(array $data): ?string
    {
        $type = $data['document_type'] ?? null;

        if ($type === null || ! isset($data['document_number'])) {
            return null;
        }

        return DocumentNumber::normalize(DocumentType::from($type), $data['document_number']);
    }
}
