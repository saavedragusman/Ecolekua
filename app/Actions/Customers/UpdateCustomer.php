<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Customers\Concerns\WritesCustomers;
use App\Enums\AuditAction;
use App\Enums\CustomerType;
use App\Exceptions\DuplicatePhoneWarning;
use App\Models\Customer;
use App\Models\User;
use App\Support\Customers\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a customer (CLI-008, design Decision 9). The payload is the complete state of the form:
 * `contact` and `address` use full-replace semantics (`null` or absent deletes, an object upserts).
 * A natural customer never keeps an anniversary or a contact person (DEC-CLI-15, E-23). Status and
 * advisor are not editable here. The audit row carries only the fields that changed; when nothing
 * changed nothing is written (as `UpdateUser`).
 */
class UpdateCustomer
{
    use WritesCustomers;

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated customer input (see CustomerRules::customer())
     *
     * @throws ValidationException when the document is taken by a concurrent request
     * @throws DuplicatePhoneWarning when the phone changed to one other customers have and it was not confirmed (E-14)
     */
    public function handle(Customer $customer, array $data, User $actor, bool $confirmDuplicatePhone = false): Customer
    {
        try {
            return DB::transaction(fn (): Customer => $this->update($customer, $data, $actor, $confirmDuplicatePhone));
        } catch (UniqueConstraintViolationException $exception) {
            $this->rethrowDocumentRaceAsValidationError($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(Customer $customer, array $data, User $actor, bool $confirmDuplicatePhone): Customer
    {
        $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
        $customer->load(['contact', 'address']);

        $before = $this->snapshot($customer);

        $phone = PhoneNumber::parse($data['phone'])->e164();

        // Only a new or changed phone is checked (DEC-CLI-27, E-14): editing other data of a
        // customer whose phone another customer already shares must not warn again.
        if ($phone !== $customer->phone && ! $confirmDuplicatePhone) {
            $this->warnAboutDuplicatePhone($phone, $customer);
        }

        $isNatural = CustomerType::from($data['type']) === CustomerType::Natural;

        $customer->fill([
            'type' => $data['type'],
            'name' => $data['name'],
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $this->normalizedDocument($data),
            'phone' => $phone,
            'email' => $data['email'] ?? null,
            'birthday_day' => $data['birthday_day'] ?? null,
            'birthday_month' => $data['birthday_month'] ?? null,
            'anniversary_day' => $isNatural ? null : ($data['anniversary_day'] ?? null),
            'anniversary_month' => $isNatural ? null : ($data['anniversary_month'] ?? null),
            'notes' => $data['notes'] ?? null,
        ])->save();

        $this->replaceContact($customer, $isNatural ? null : ($data['contact'] ?? null));
        $this->replaceAddress($customer, $data['address'] ?? null);

        $customer->load(['contact', 'address']);

        $after = $this->snapshot($customer);
        $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $this->audit->handle(
                AuditAction::CustomerUpdated,
                $actor,
                $customer,
                oldValues: array_intersect_key($before, array_flip($changed)),
                newValues: array_intersect_key($after, array_flip($changed)),
            );
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>|null  $contact
     */
    private function replaceContact(Customer $customer, ?array $contact): void
    {
        if ($contact === null) {
            $customer->contact?->delete();

            return;
        }

        $customer->contact()->updateOrCreate([], [
            'name' => $contact['name'],
            'position' => $contact['position'] ?? null,
            'phone' => PhoneNumber::parse($contact['phone'])->e164(),
            'email' => $contact['email'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private function replaceAddress(Customer $customer, ?array $address): void
    {
        if ($address === null) {
            $customer->address?->delete();

            return;
        }

        $customer->address()->updateOrCreate([], [
            'line' => $address['line'],
            'city' => $address['city'],
            'state' => $address['state'],
            'reference' => $address['reference'] ?? null,
        ]);
    }

    /**
     * The editable state in audit form (design "Audit payloads"): phones in E.164, document canonical,
     * contact and address as objects. Status and advisor are outside the editable state.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Customer $customer): array
    {
        return [
            'type' => $customer->type->value,
            'name' => $customer->name,
            'document_type' => $customer->document_type?->value,
            'document_number' => $customer->document_number,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'birthday_day' => $customer->birthday_day,
            'birthday_month' => $customer->birthday_month,
            'anniversary_day' => $customer->anniversary_day,
            'anniversary_month' => $customer->anniversary_month,
            'notes' => $customer->notes,
            'contact' => $customer->contact?->only(['name', 'position', 'phone', 'email']),
            'address' => $customer->address === null ? null : [
                'line' => $customer->address->line,
                'city' => $customer->address->city,
                'state' => $customer->address->state->value,
                'reference' => $customer->address->reference,
            ],
        ];
    }
}
