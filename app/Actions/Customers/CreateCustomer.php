<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Customers\Concerns\WritesCustomers;
use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Exceptions\DuplicatePhoneWarning;
use App\Models\Customer;
use App\Models\User;
use App\Support\Customers\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers a customer (CLI-001): always `active`, created by the actor, with phones in E.164 and
 * the document in its canonical form (design Decision 9). The creator becomes the advisor only
 * when eligible (CLI-014, E-25). Contact person and address are part of the aggregate and are
 * written in the same transaction as the `customers.created` audit row.
 */
class CreateCustomer
{
    use WritesCustomers;

    public function __construct(private readonly RecordAuditEvent $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated customer input (see CustomerRules::customer())
     *
     * @throws ValidationException when the document is taken by a concurrent request
     * @throws DuplicatePhoneWarning when other customers have the phone and it was not confirmed (E-14)
     */
    public function handle(array $data, User $actor, bool $confirmDuplicatePhone = false): Customer
    {
        try {
            return DB::transaction(fn (): Customer => $this->create($data, $actor, $confirmDuplicatePhone));
        } catch (UniqueConstraintViolationException $exception) {
            $this->rethrowDocumentRaceAsValidationError($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(array $data, User $actor, bool $confirmDuplicatePhone): Customer
    {
        $phone = PhoneNumber::parse($data['phone'])->e164();

        if (! $confirmDuplicatePhone) {
            $this->warnAboutDuplicatePhone($phone);
        }

        $advisor = $actor->isEligibleAdvisor() ? $actor : null;

        $customer = Customer::query()->create([
            'type' => $data['type'],
            'name' => $data['name'],
            'document_type' => $data['document_type'] ?? null,
            'document_number' => $this->normalizedDocument($data),
            'phone' => $phone,
            'email' => $data['email'] ?? null,
            'birthday_day' => $data['birthday_day'] ?? null,
            'birthday_month' => $data['birthday_month'] ?? null,
            'anniversary_day' => $data['anniversary_day'] ?? null,
            'anniversary_month' => $data['anniversary_month'] ?? null,
            'notes' => $data['notes'] ?? null,
            'advisor_id' => $advisor?->getKey(),
            'status' => CustomerStatus::Active,
            'created_by' => $actor->getKey(),
        ]);

        if (isset($data['contact'])) {
            $customer->contact()->create([
                'name' => $data['contact']['name'],
                'position' => $data['contact']['position'] ?? null,
                'phone' => PhoneNumber::parse($data['contact']['phone'])->e164(),
                'email' => $data['contact']['email'] ?? null,
            ]);
        }

        if (isset($data['address'])) {
            $customer->address()->create([
                'line' => $data['address']['line'],
                'city' => $data['address']['city'],
                'state' => $data['address']['state'],
                'reference' => $data['address']['reference'] ?? null,
            ]);
        }

        $customer->load(['contact', 'address']);

        $this->audit->handle(
            AuditAction::CustomerCreated,
            $actor,
            $customer,
            newValues: $this->auditValues($customer, $advisor),
        );

        return $customer;
    }

    /**
     * Full created values (design "Audit payloads"): phones in E.164, document canonical.
     *
     * @return array<string, mixed>
     */
    private function auditValues(Customer $customer, ?User $advisor): array
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
            'status' => $customer->status->value,
            'advisor_id' => $advisor?->getKey(),
            'advisor_name' => $advisor?->fullName(),
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
