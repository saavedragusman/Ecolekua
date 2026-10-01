<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a customer that has no history (CLI-010, design Decision 11). AGENTS.md section 7.9
 * forbids physical deletes of entities with history; in 002 nothing references a customer yet,
 * so every customer can go. The contact and address rows are removed by the foreign keys'
 * cascade in the same statement. The audit row keeps the full copy in `old_values` and survives
 * because `audit_logs.entity_id` has no foreign key.
 */
class DeleteCustomer
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Customer $customer, User $actor): void
    {
        DB::transaction(function () use ($customer, $actor): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $this->ensureHasNoHistory($customer);

            $customer->load(['contact', 'address', 'advisor']);
            $copy = $this->copy($customer);

            $customer->delete();

            $this->audit->handle(
                AuditAction::CustomerDeleted,
                $actor,
                $customer,
                oldValues: $copy,
            );
        });
    }

    /**
     * Blocks the delete when the customer has history (E-19). No condition exists in 002 because
     * no table references a customer yet. Spec 004 adds "has quotations" and spec 006 adds "has
     * orders or payments"; each condition throws a `BusinessRuleViolation` with the message "El
     * cliente tiene historial y no se puede eliminar. Puede desactivarlo." and brings its own
     * E-19 test (registered as `todo` in DeleteCustomerTest until then). Those later tables must
     * declare their foreign key with `restrictOnDelete()` (design Decision 2).
     */
    private function ensureHasNoHistory(Customer $customer): void
    {
        // Intentionally empty in 002.
    }

    /**
     * Full copy in audit form (design "Audit payloads"): phones in E.164, document canonical.
     *
     * @return array<string, mixed>
     */
    private function copy(Customer $customer): array
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
            'advisor_id' => $customer->advisor?->getKey(),
            'advisor_name' => $customer->advisor?->fullName(),
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
