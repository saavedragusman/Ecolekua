<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deactivates a customer (CLI-009, design Decision 11). Only `status` changes: data, contact,
 * address and advisor stay as they are (E-07). An already inactive customer is a no-op with no
 * audit row (E-40), the same rule as `DeactivateUser`.
 */
class DeactivateCustomer
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    public function handle(Customer $customer, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $actor): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            if ($customer->status === CustomerStatus::Inactive) {
                return $customer;
            }

            $customer->forceFill(['status' => CustomerStatus::Inactive])->save();

            $this->audit->handle(
                AuditAction::CustomerDeactivated,
                $actor,
                $customer,
                oldValues: ['status' => CustomerStatus::Active->value],
                newValues: ['status' => CustomerStatus::Inactive->value],
            );

            return $customer;
        });
    }
}
