<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns, reassigns or removes (`null`) the advisor of a customer (CLI-014, design Decision 12).
 * Only an eligible advisor (active user holding `customers.portfolio`) can be assigned (E-28).
 * Inactive customers stay reassignable (DEC-CLI-28). Assigning the current advisor again is a
 * no-op with no audit row. This is the only place, besides creation, that writes `advisor_id`:
 * nothing unassigns a customer automatically (DEC-CLI-29).
 */
class AssignCustomerAdvisor
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Customer $customer, ?User $advisor, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $advisor, $actor): Customer {
            $customer = Customer::query()->with('advisor')->lockForUpdate()->findOrFail($customer->id);

            $previous = $customer->advisor;

            // Keeping the current advisor changes nothing, even if that advisor is no longer
            // eligible (DEC-CLI-29): the stored assignment is never touched automatically.
            if ($previous?->getKey() === $advisor?->getKey()) {
                return $customer;
            }

            if ($advisor !== null && ! $advisor->isEligibleAdvisor()) {
                throw ValidationException::withMessages([
                    'advisor_id' => 'La asesora debe ser un usuario activo con permiso para tener cartera.',
                ]);
            }

            $customer->forceFill(['advisor_id' => $advisor?->getKey()])->save();

            $this->audit->handle(
                AuditAction::CustomerAdvisorAssigned,
                $actor,
                $customer,
                oldValues: ['advisor_id' => $previous?->getKey(), 'advisor_name' => $previous?->fullName()],
                newValues: ['advisor_id' => $advisor?->getKey(), 'advisor_name' => $advisor?->fullName()],
            );

            return $customer;
        });
    }
}
